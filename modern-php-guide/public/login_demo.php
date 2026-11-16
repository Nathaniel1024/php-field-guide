<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Strict',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

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

$errors = [];
$success = null;

$userEmail = 'admin@example.com';
$passwordHash = password_hash('ChangeMe123!', PASSWORD_DEFAULT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'CSRF validation failed';
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $key = rate_limit_key($ip, $email);
        $limit = check_rate_limit($key, maxAttempts: 5, windowSec: 300);

        if ($limit['limited']) {
            $errors[] = 'Too many attempts. Try again later.';
        } else {
            $okUser = hash_equals($userEmail, strtolower($email));
            $okPass = password_verify($password, $passwordHash);

            if (!$okUser || !$okPass) {
                record_failed_attempt($key, $limit['bucket']);
                $errors[] = 'Invalid credentials. Remaining attempts: ' . max(0, $limit['remaining'] - 1);
            } else {
                unset($_SESSION['login_attempts'][$key]);
                session_regenerate_id(true);
                $_SESSION['user_email'] = $userEmail;
                $_SESSION['logged_in_at'] = time();
                $success = 'Login successful; session ID regenerated.';
            }
        }
    }
}

$pageTitle = 'Login Security Demo';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>Session Hardening + Rate Limit Demo</h1>
    <p class="muted">Demo credentials: <code>admin@example.com</code> / <code>ChangeMe123!</code></p>

    <?php foreach ($errors as $error): ?>
        <p class="notice err"><?= e($error) ?></p>
    <?php endforeach; ?>
    <?php if ($success !== null): ?>
        <p class="notice ok"><?= e($success) ?></p>
    <?php endif; ?>

    <form method="post" class="grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit">Login</button>
    </form>
</div>
</div>
</body>
</html>
