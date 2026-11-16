<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

$pdo = db();

$sortColumnInput = (string) ($_GET['sort'] ?? 'created_at');
$sortDirInput = strtolower((string) ($_GET['dir'] ?? 'desc'));

$sortColumns = [
    'id' => 'id',
    'title' => 'title',
    'priority' => 'priority',
    'due_date' => 'due_date',
    'created_at' => 'created_at',
];
$sortDirections = ['asc' => 'ASC', 'desc' => 'DESC'];

$sortColumn = $sortColumns[$sortColumnInput] ?? 'created_at';
$sortDirection = $sortDirections[$sortDirInput] ?? 'DESC';

$sql = "SELECT id, title, priority, due_date, created_at, category, status FROM tasks ORDER BY {$sortColumn} {$sortDirection}, id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$rows = $stmt->fetchAll();

$pageTitle = 'Sort Demo';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>Dynamic ORDER BY (Allowlist) Demo</h1>
    <p class="muted">PDO placeholders cannot bind column identifiers; use strict allowlists.</p>

    <form method="get" class="grid">
        <select name="sort">
            <?php foreach (array_keys($sortColumns) as $column): ?>
                <option value="<?= e($column) ?>" <?= $sortColumnInput === $column ? 'selected' : '' ?>><?= e($column) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="dir">
            <?php foreach (['asc', 'desc'] as $dir): ?>
                <option value="<?= e($dir) ?>" <?= $sortDirInput === $dir ? 'selected' : '' ?>><?= strtoupper(e($dir)) ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit">Apply Sort</button>
    </form>
</div>

<div class="card">
    <p>Applied: <span class="pill"><?= e($sortColumn) ?> <?= e($sortDirection) ?></span></p>
    <div class="table-wrap">
        <table>
            <thead><tr><th>ID</th><th>Title</th><th>Category</th><th>Status</th><th>Priority</th><th>Due</th><th>Created</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= (int) $row['id'] ?></td>
                    <td><?= e((string) $row['title']) ?></td>
                    <td><?= e((string) $row['category']) ?></td>
                    <td><?= e((string) $row['status']) ?></td>
                    <td><?= (int) $row['priority'] ?></td>
                    <td><?= e((string) ($row['due_date'] ?? '-')) ?></td>
                    <td><?= e((string) $row['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</div>
</body>
</html>
