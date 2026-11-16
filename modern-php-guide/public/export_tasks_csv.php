<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$pdo = db();
$status = trim((string) ($_GET['status'] ?? ''));

$sql = 'SELECT id, title, description, category, quantity, status, due_date, priority, created_at FROM tasks';
$params = [];

if ($status !== '') {
    $sql .= ' WHERE status = :status';
    $params['status'] = $status;
}

$sql .= ' ORDER BY id ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$filename = 'tasks_export_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'wb');
if ($out === false) {
    http_response_code(500);
    echo 'Cannot open output stream';
    exit;
}

fputcsv($out, ['id', 'title', 'description', 'category', 'quantity', 'status', 'due_date', 'priority', 'created_at']);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
        (int) $row['id'],
        (string) $row['title'],
        (string) $row['description'],
        (string) $row['category'],
        (int) $row['quantity'],
        (string) $row['status'],
        (string) ($row['due_date'] ?? ''),
        (int) $row['priority'],
        (string) $row['created_at'],
    ]);
}
fclose($out);
exit;
