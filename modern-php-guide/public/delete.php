<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$token = $_POST['csrf_token'] ?? null;
$id = (int) ($_POST['id'] ?? 0);

if (!verify_csrf(is_string($token) ? $token : null)) {
    flash('error', 'Invalid CSRF token.');
    redirect('/public/index.php');
}

if ($id <= 0) {
    flash('error', 'Invalid task ID.');
    redirect('/public/index.php');
}

$pdo = db();
$stmt = $pdo->prepare('DELETE FROM tasks WHERE id = :id');
$stmt->execute(['id' => $id]);

flash('success', 'Task deleted successfully.');
redirect('/public/index.php');
