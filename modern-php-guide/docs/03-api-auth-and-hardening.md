# 03) API Auth and Hardening

This module focuses on practical API safety patterns for production-ish PHP services.

## 1) CORS + `OPTIONS` preflight done correctly

### Runnable API gateway example (`api/gateway.php` style)

```php
<?php
declare(strict_types=1);

$allowedOrigins = [
    'http://localhost:3000',
    'http://127.0.0.1:5173',
    'https://app.example.com',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 600');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

http_response_code(200);
echo json_encode(['ok' => true, 'message' => 'CORS policy applied'], JSON_PRETTY_PRINT);
```

---

## 2) Bearer token validation with timing-safe `hash_equals()`

### Runnable example

```php
<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// In production, store hashed token or use a DB/API key table.
$validTokens = [
    'svc-mobile' => 'prod_token_7f0fd263e7',
    'svc-backoffice' => 'prod_token_9de33a7b11',
];

function unauthorized(string $message = 'Unauthorized'): never
{
    http_response_code(401);
    echo json_encode(['error' => $message], JSON_PRETTY_PRINT);
    exit;
}

function extract_bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!is_string($header) || $header === '') {
        return null;
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return null;
    }
    return trim($m[1]);
}

$received = extract_bearer_token();
if ($received === null) {
    unauthorized('Missing Bearer token');
}

$matchedClient = null;
foreach ($validTokens as $client => $token) {
    if (hash_equals($token, $received)) {
        $matchedClient = $client;
        break;
    }
}

if ($matchedClient === null) {
    unauthorized('Invalid token');
}

http_response_code(200);
echo json_encode(['ok' => true, 'client' => $matchedClient], JSON_PRETTY_PRINT);
```

---

## 3) Advanced cURL patterns (timeouts, errors, multipart, parallel)

### 3.1 Single request with robust error handling

```php
<?php
declare(strict_types=1);

function http_json(string $url, string $method = 'GET', array $payload = []): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
    ];

    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
    }

    curl_setopt_array($ch, $opts);
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
        throw new RuntimeException('HTTP error ' . $status . ': ' . json_encode($decoded));
    }

    return ['status' => $status, 'data' => $decoded];
}

try {
    $result = http_json('http://localhost:8000/api/tasks.php?status=pending');
    var_dump($result);
} catch (Throwable $e) {
    echo $e->getMessage() . PHP_EOL;
}
```

### 3.2 Multipart upload using `CURLFile`

```php
<?php
declare(strict_types=1);

$filePath = __DIR__ . '/../tmp/sample.png';

if (!is_file($filePath)) {
    throw new RuntimeException('Missing upload file: ' . $filePath);
}

$ch = curl_init('http://localhost:8000/public/upload_demo.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'note' => 'multipart test',
        'asset' => new CURLFile($filePath, mime_content_type($filePath) ?: 'application/octet-stream', basename($filePath)),
    ],
]);

$body = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($body === false) {
    throw new RuntimeException('Upload failed: ' . curl_error($ch));
}

curl_close($ch);
echo "HTTP {$status}\n{$body}\n";
```

### 3.3 Parallel calls with `curl_multi_*`

```php
<?php
declare(strict_types=1);

$urls = [
    'http://localhost:8000/api/tasks.php?status=pending',
    'http://localhost:8000/api/tasks.php?status=in_progress',
    'http://localhost:8000/api/tasks.php?status=done',
];

$multi = curl_multi_init();
$handles = [];

foreach ($urls as $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_multi_add_handle($multi, $ch);
    $handles[] = $ch;
}

$running = null;
do {
    $mrc = curl_multi_exec($multi, $running);
    if ($mrc === CURLM_OK) {
        curl_multi_select($multi, 1.0);
    }
} while ($running > 0 && $mrc === CURLM_OK);

$responses = [];
foreach ($handles as $ch) {
    $responses[] = [
        'url' => curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
        'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'body' => curl_multi_getcontent($ch),
    ];
    curl_multi_remove_handle($multi, $ch);
    curl_close($ch);
}
curl_multi_close($multi);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($responses, JSON_PRETTY_PRINT);
```

---

## 4) Session hardening + brute-force rate limiter

### Runnable login sketch (`public/login.php` style)

```php
<?php
declare(strict_types=1);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');

// Demo user (in production fetch from DB).
$userEmail = 'admin@example.com';
$passwordHash = password_hash('ChangeMe123!', PASSWORD_DEFAULT);

// Basic in-session rate limiter by IP + username.
function rate_limit_key(string $ip, string $username): string
{
    return hash('sha256', strtolower($ip . '|' . $username));
}

function check_rate_limit(string $key, int $maxAttempts, int $windowSec): array
{
    $_SESSION['login_attempts'] ??= [];
    $bucket = $_SESSION['login_attempts'][$key] ?? ['count' => 0, 'first' => time()];

    if (time() - (int) $bucket['first'] > $windowSec) {
        $bucket = ['count' => 0, 'first' => time()];
    }

    $remaining = max(0, $maxAttempts - (int) $bucket['count']);
    return ['limited' => $bucket['count'] >= $maxAttempts, 'remaining' => $remaining, 'bucket' => $bucket];
}

function record_failed_attempt(string $key, array $bucket): void
{
    $bucket['count'] = (int) $bucket['count'] + 1;
    $_SESSION['login_attempts'][$key] = $bucket;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(['message' => 'POST email/password to test'], JSON_PRETTY_PRINT);
    exit;
}

$email = trim((string) ($_POST['email'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

$key = rate_limit_key($ip, $email);
$limit = check_rate_limit($key, maxAttempts: 5, windowSec: 300);
if ($limit['limited']) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many attempts. Try again later.'], JSON_PRETTY_PRINT);
    exit;
}

$okUser = hash_equals($userEmail, strtolower($email));
$okPass = password_verify($password, $passwordHash);

if (!$okUser || !$okPass) {
    record_failed_attempt($key, $limit['bucket']);
    http_response_code(401);
    echo json_encode(['error' => 'Invalid credentials', 'remaining_attempts' => $limit['remaining'] - 1], JSON_PRETTY_PRINT);
    exit;
}

unset($_SESSION['login_attempts'][$key]);
session_regenerate_id(true);
$_SESSION['user_email'] = $userEmail;
$_SESSION['logged_in_at'] = time();

http_response_code(200);
echo json_encode(['message' => 'Login successful'], JSON_PRETTY_PRINT);
```

---

## 5) cURL localhost HTTPS failures (dev vs prod SSL verification)

When testing on `https://localhost` with a self-signed cert, cURL may fail with SSL errors unless your local CA is trusted.

### Safe debugging pattern

```php
<?php
declare(strict_types=1);

$url = 'https://localhost:8443/api/tasks.php';
$isLocalDev = true;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);

if ($isLocalDev) {
    // DEV ONLY: disable verification temporarily when using self-signed certs.
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
}

$body = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$errno = curl_errno($ch);
$error = curl_error($ch);
curl_close($ch);

if ($errno !== 0) {
    throw new RuntimeException("cURL SSL/network error ({$errno}): {$error}");
}

echo "HTTP {$status}\n{$body}\n";
```

Production rule:
- Keep `CURLOPT_SSL_VERIFYPEER` enabled.
- Keep `CURLOPT_SSL_VERIFYHOST` at default/strict verification.
- Trust a valid CA certificate chain.

