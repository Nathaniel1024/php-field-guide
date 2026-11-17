# 12) Email Delivery + Structured Logging in Vanilla PHP 8.2+

This guide covers two operations every real app needs in production:
1. Reliable outbound email delivery.
2. Structured application logs + DB audit trails.

---

## 1) Reliable Emails: SMTP vs `mail()`

### Why `mail()` often fails in production

Native `mail()` can work on local machines, but often fails in real deployments because:
- No authenticated SMTP session (many providers reject unauthenticated mail).
- Missing/incorrect DNS records (SPF, DKIM, DMARC) reduce deliverability.
- Shared host mail reputation and spam filtering issues.
- Weak error visibility (message accepted by local MTA ≠ delivered to inbox).

For production-grade sending, prefer:
- Authenticated SMTP via a maintained library (PHPMailer), or
- HTTP email provider APIs (Resend/Postmark/Mailgun) with cURL.

---

## 2) SMTP Email Helper with PHPMailer (Composer)

Install once:

```bash
composer require phpmailer/phpmailer
```

### 2.1 `.env` credentials

Create `.env` in repo root (never commit real secrets):

```dotenv
APP_ENV=production
APP_DEBUG=0

MAIL_TRANSPORT=smtp
MAIL_HOST=smtp.mailprovider.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=mailer@example.com
MAIL_PASSWORD=replace_with_real_secret
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME="Modern PHP Guide"
```

### 2.2 Minimal env loader + helper (`includes/env.php`)

```php
<?php

declare(strict_types=1);

function load_env_file(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', $trimmed, 2), 2, '');
        $key = trim($key);
        $value = trim($value);

        if ($key === '') {
            continue;
        }

        $value = trim($value, "\"'");

        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}

function env(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return (string) $value;
}
```

### 2.3 SMTP send helper (`includes/mailer.php`)

```php
<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/env.php';

/**
 * @param array<int, array{path:string,name?:string}> $attachments
 */
function send_smtp_email(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody,
    array $attachments = []
): void {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = env('MAIL_HOST', 'localhost');
        $mail->Port = (int) env('MAIL_PORT', '587');
        $mail->SMTPAuth = true;
        $mail->Username = env('MAIL_USERNAME', '');
        $mail->Password = env('MAIL_PASSWORD', '');

        $encryption = env('MAIL_ENCRYPTION', 'tls');
        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->CharSet = 'UTF-8';
        $mail->setFrom(
            env('MAIL_FROM_ADDRESS', 'no-reply@example.com'),
            env('MAIL_FROM_NAME', 'App') ?? 'App'
        );
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;

        $mail->isHTML(true);
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody;

        foreach ($attachments as $attachment) {
            $path = $attachment['path'];
            if (!is_file($path)) {
                continue;
            }
            $mail->addAttachment($path, $attachment['name'] ?? basename($path));
        }

        $mail->send();
    } catch (Exception $exception) {
        throw new RuntimeException('SMTP email failed: ' . $mail->ErrorInfo, 0, $exception);
    }
}
```

### 2.4 Usage example

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/mailer.php';

load_env_file(__DIR__ . '/../.env');

$html = '<h1>Invoice Ready</h1><p>Your invoice <strong>#INV-2042</strong> is attached.</p>';
$text = "Invoice Ready\n\nYour invoice #INV-2042 is attached.";

send_smtp_email(
    'customer@example.com',
    'Customer Name',
    'Your invoice is ready',
    $html,
    $text,
    [
        ['path' => __DIR__ . '/../storage/invoices/INV-2042.pdf', 'name' => 'invoice-2042.pdf'],
    ]
);
```

---

## 3) Zero-Dependency HTTP Email API (Resend/Postmark/Mailgun)

If you already use the cURL JSON helper pattern from module 03 (`http_json()`), you can send emails with no extra SDK.

### 3.1 Generic cURL JSON helper

```php
<?php

declare(strict_types=1);

function http_json(string $url, string $method = 'POST', array $payload = [], array $headers = []): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }

    $defaultHeaders = ['Accept: application/json', 'Content-Type: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $headers),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        throw new RuntimeException("Network error ({$errno}): {$error}");
    }
    if ($body === false) {
        throw new RuntimeException('Unknown cURL failure');
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        $decoded = ['raw' => $body];
    }

    if ($status >= 400) {
        throw new RuntimeException('HTTP ' . $status . ': ' . json_encode($decoded));
    }

    return ['status' => $status, 'data' => $decoded];
}
```

### 3.2 Provider-specific payload examples

```php
<?php

declare(strict_types=1);

// Resend-style JSON example
$resendResponse = http_json(
    'https://api.resend.com/emails',
    'POST',
    [
        'from' => 'Modern PHP Guide <no-reply@example.com>',
        'to' => ['customer@example.com'],
        'subject' => 'Password reset',
        'html' => '<p>Click reset link.</p>',
        'text' => 'Click reset link.',
    ],
    ['Authorization: Bearer ' . (env('RESEND_API_KEY', '') ?? '')]
);

// Postmark-style JSON example
$postmarkResponse = http_json(
    'https://api.postmarkapp.com/email',
    'POST',
    [
        'From' => 'no-reply@example.com',
        'To' => 'customer@example.com',
        'Subject' => 'Order update',
        'HtmlBody' => '<p>Your order has shipped.</p>',
        'TextBody' => 'Your order has shipped.',
    ],
    ['X-Postmark-Server-Token: ' . (env('POSTMARK_SERVER_TOKEN', '') ?? '')]
);

// Mailgun-style JSON endpoint (varies by region/domain)
$mailgunDomain = env('MAILGUN_DOMAIN', 'mg.example.com') ?? 'mg.example.com';
$mailgunResponse = http_json(
    'https://api.mailgun.net/v3/' . $mailgunDomain . '/messages',
    'POST',
    [
        'from' => 'Modern PHP Guide <no-reply@example.com>',
        'to' => 'customer@example.com',
        'subject' => 'Alert',
        'html' => '<p>Important alert.</p>',
        'text' => 'Important alert.',
    ],
    ['Authorization: Bearer ' . (env('MAILGUN_API_KEY', '') ?? '')]
);
```

> Notes:
> - Keep API keys in `.env`, not in code.
> - Some providers expect multipart/form-data instead of JSON for certain endpoints. Adjust `CURLOPT_POSTFIELDS` format per provider docs.
> - Log only message IDs/status codes; do not log full recipient PII unless policy allows it.

---

## 4) Structured Application Logging (PSR-3-inspired)

Goal: write consistent logs like:
- `storage/logs/app-2026-11-17.log` (daily rotation)
- one event per line (JSON or key-value text)
- include severity + request context
- never expose stack traces to the browser in production

### 4.1 Logger helper (`includes/logger.php`)

```php
<?php

declare(strict_types=1);

final class Logger
{
    private string $logDir;
    private bool $jsonMode;

    public function __construct(string $logDir, bool $jsonMode = true)
    {
        $this->logDir = rtrim($logDir, DIRECTORY_SEPARATOR);
        $this->jsonMode = $jsonMode;

        if (!is_dir($this->logDir) && !mkdir($this->logDir, 0775, true) && !is_dir($this->logDir)) {
            throw new RuntimeException('Unable to create log directory: ' . $this->logDir);
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $entry = [
            'timestamp' => gmdate('c'),
            'level' => strtoupper($level),
            'message' => $message,
            'context' => $this->buildContext($context),
        ];

        $file = $this->logDir . DIRECTORY_SEPARATOR . 'app-' . gmdate('Y-m-d') . '.log';

        if ($this->jsonMode) {
            $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($line === false) {
                $line = '{"level":"ERROR","message":"Failed to encode log event"}';
            }
        } else {
            $ctx = [];
            foreach ($entry['context'] as $key => $value) {
                $ctx[] = $key . '=' . str_replace(['\n', '\r'], ' ', (string) $value);
            }
            $line = sprintf(
                '[%s] %s %s %s',
                $entry['timestamp'],
                $entry['level'],
                $entry['message'],
                implode(' ', $ctx)
            );
        }

        file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function buildContext(array $context): array
    {
        $requestContext = [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'path' => $_SERVER['REQUEST_URI'] ?? 'cli',
            'user_id' => $_SESSION['user_id'] ?? null,
            'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8)),
        ];

        return array_merge($requestContext, $context);
    }
}
```

### 4.2 Global error/exception handling (no browser leak)

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/logger.php';

$logger = new Logger(__DIR__ . '/../storage/logs', true);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_error_handler(static function (
    int $severity,
    string $message,
    string $file,
    int $line
) use ($logger): bool {
    $logger->error('PHP runtime warning/error', [
        'severity' => $severity,
        'error_message' => $message,
        'file' => $file,
        'line' => $line,
    ]);

    return true;
});

set_exception_handler(static function (Throwable $throwable) use ($logger): void {
    $logger->error('Uncaught exception', [
        'exception' => $throwable::class,
        'message' => $throwable->getMessage(),
        'file' => $throwable->getFile(),
        'line' => $throwable->getLine(),
        'trace' => $throwable->getTraceAsString(),
    ]);

    http_response_code(500);
    echo 'Something went wrong. Please try again later.';
});
```

### 4.3 Example usage in CRUD flow

```php
<?php

declare(strict_types=1);

$logger->info('Task created', ['task_id' => $newTaskId, 'category_id' => $categoryId]);
$logger->warning('Validation failed', ['errors' => $errors]);
$logger->error('Email delivery failed', ['to' => $recipient, 'provider' => 'smtp']);
```

---

## 5) Database Audit Trail Pattern (`audit_logs`)

For critical actions (status changes, deletes, permission updates), store immutable DB audit rows.

### 5.1 `audit_logs` table

```sql
CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_user_id INTEGER NULL,
    action VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INTEGER NOT NULL,
    before_json TEXT NULL,
    after_json TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_audit_logs_entity ON audit_logs (entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_audit_logs_actor ON audit_logs (actor_user_id);
CREATE INDEX IF NOT EXISTS idx_audit_logs_created_at ON audit_logs (created_at);
```

### 5.2 Audit helper (`includes/audit.php`)

```php
<?php

declare(strict_types=1);

/**
 * @param array<string, mixed>|null $before
 * @param array<string, mixed>|null $after
 */
function write_audit_log(
    PDO $pdo,
    ?int $actorUserId,
    string $action,
    string $entityType,
    int $entityId,
    ?array $before,
    ?array $after
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs
         (actor_user_id, action, entity_type, entity_id, before_json, after_json, ip_address, user_agent)
         VALUES
         (:actor_user_id, :action, :entity_type, :entity_id, :before_json, :after_json, :ip_address, :user_agent)'
    );

    $stmt->execute([
        'actor_user_id' => $actorUserId,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'before_json' => $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
        'after_json' => $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);
}
```

### 5.3 Transaction-safe usage example (critical update)

```php
<?php

declare(strict_types=1);

$taskId = (int) $_POST['task_id'];
$newStatus = (string) $_POST['status'];
$actorUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

$pdo->beginTransaction();

try {
    $select = $pdo->prepare('SELECT id, title, status, priority FROM tasks WHERE id = :id');
    $select->execute(['id' => $taskId]);
    $before = $select->fetch(PDO::FETCH_ASSOC);

    if (!is_array($before)) {
        throw new RuntimeException('Task not found');
    }

    $update = $pdo->prepare('UPDATE tasks SET status = :status WHERE id = :id');
    $update->execute([
        'status' => $newStatus,
        'id' => $taskId,
    ]);

    $select->execute(['id' => $taskId]);
    $after = $select->fetch(PDO::FETCH_ASSOC);

    write_audit_log(
        $pdo,
        $actorUserId,
        'task_status_updated',
        'task',
        $taskId,
        is_array($before) ? $before : null,
        is_array($after) ? $after : null
    );

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    throw $exception;
}
```

---

## 6) Production checklist (email + logs)

- `display_errors=Off` in production, and render generic 500 messages.
- Keep SMTP/API credentials in environment variables only.
- Store logs outside public web root (`storage/logs`).
- Use JSON lines for machine parsing and alerting.
- Include correlation fields (`request_id`, `user_id`) in every log.
- Keep audit logs append-only (no updates/deletes in normal app flow).
- Mask sensitive values in logs (tokens, passwords, full card data).

This gives you an operational baseline: reliable delivery, debuggable failures, and accountable data changes without adding a full framework.
