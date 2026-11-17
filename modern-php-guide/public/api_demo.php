<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';

$pageTitle = 'API Demo via cURL';
require __DIR__ . '/../includes/header.php';

$apiUrl = sprintf('http://%s/api/tasks.php', $_SERVER['HTTP_HOST'] ?? 'localhost:8000');
$response = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'CSRF validation failed.';
    } else {
        $method = strtoupper((string) ($_POST['method'] ?? 'GET'));
        $payloadRaw = trim((string) ($_POST['payload'] ?? ''));
        $query = trim((string) ($_POST['query'] ?? ''));

        $finalUrl = $apiUrl;
        if ($query !== '') {
            $finalUrl .= '?' . $query;
        }

        $ch = curl_init($finalUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 10,
        ]);

        if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
            $json = $payloadRaw;
            if ($json === '') {
                $json = '{}';
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($body === false) {
            $error = 'cURL error: ' . curl_error($ch);
        } else {
            $response = "HTTP {$code}\n" . $body;
        }

        curl_close($ch);
    }
}
?>

<div class="card">
    <h1>REST API Playground (PHP cURL)</h1>
    <p class="muted">Target endpoint: <code><?= e($apiUrl) ?></code></p>

    <?php if ($error !== null): ?>
        <p class="notice err"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" class="grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <select name="method">
            <?php foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method): ?>
                <option value="<?= e($method) ?>"><?= e($method) ?></option>
            <?php endforeach; ?>
        </select>

        <input name="query" placeholder="Optional query string (e.g. status=pending&category_id=2)">

        <textarea name="payload" placeholder='JSON payload for POST/PUT/DELETE, e.g. {"title":"New Task","description":"...","category_id":2,"status":"pending"}'></textarea>

        <button type="submit">Send Request</button>
    </form>
</div>

<?php if ($response !== null): ?>
    <div class="card">
        <h2>Response</h2>
        <pre><?= e($response) ?></pre>
    </div>
<?php endif; ?>

</div>
</body>
</html>
