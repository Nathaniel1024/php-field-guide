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

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = null;
if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
    $token = trim($m[1]);
}

$validTokens = [
    'svc-mobile' => 'prod_token_7f0fd263e7',
    'svc-backoffice' => 'prod_token_9de33a7b11',
];

$client = null;
if ($token !== null) {
    foreach ($validTokens as $name => $knownToken) {
        if (hash_equals($knownToken, $token)) {
            $client = $name;
            break;
        }
    }
}

if ($client === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: provide Bearer token'], JSON_PRETTY_PRINT);
    exit;
}

http_response_code(200);
echo json_encode([
    'ok' => true,
    'client' => $client,
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
], JSON_PRETTY_PRINT);
