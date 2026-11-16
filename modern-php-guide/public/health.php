<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$checks = [];
$statusCode = 200;

$checks['php_version'] = [
    'ok' => PHP_VERSION_ID >= 80200,
    'current' => PHP_VERSION,
    'required' => '8.2+',
];
if ($checks['php_version']['ok'] === false) {
    $statusCode = 503;
}

$checks['extensions'] = [
    'pdo' => extension_loaded('pdo'),
    'curl' => extension_loaded('curl'),
    'mbstring' => extension_loaded('mbstring'),
    'fileinfo' => extension_loaded('fileinfo'),
];
if (in_array(false, $checks['extensions'], true)) {
    $statusCode = 503;
}

try {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT 1 AS ok');
    $stmt->execute();
    $row = $stmt->fetch();

    $checks['database'] = [
        'ok' => ((int) ($row['ok'] ?? 0)) === 1,
        'message' => 'Connection/query successful',
    ];

    if ($checks['database']['ok'] === false) {
        $statusCode = 503;
    }
} catch (Throwable $e) {
    $checks['database'] = [
        'ok' => false,
        'message' => 'Database check failed',
        'error' => $e->getMessage(),
    ];
    $statusCode = 503;
}

$requiredEnv = ['DB_DRIVER'];
$envReport = [];
foreach ($requiredEnv as $name) {
    $value = $_ENV[$name] ?? getenv($name);
    $envReport[$name] = $value !== false && $value !== null && $value !== '';
}
$checks['env'] = $envReport;

$timestamp = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

http_response_code($statusCode);
echo json_encode([
    'status' => $statusCode === 200 ? 'ok' : 'degraded',
    'timestamp' => $timestamp,
    'checks' => $checks,
], JSON_PRETTY_PRINT);
