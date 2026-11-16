<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

$pdo = db();

$filters = [
    'q' => $_GET['q'] ?? '',
    'category' => $_GET['category'] ?? '',
    'status' => $_GET['status'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
];

$allowed = [
    'q' => 'title',
    'category' => 'category',
    'status' => 'status',
    'date_from' => 'due_date',
    'date_to' => 'due_date',
];

$where = build_where_clause($filters, $allowed);

$sql = 'SELECT id, title, description, category, quantity, status, due_date, priority, created_at
        FROM tasks' . $where['sql'] . ' ORDER BY priority DESC, due_date ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($where['params']);
$tasks = $stmt->fetchAll();

$catStmt = $pdo->prepare('SELECT DISTINCT category FROM tasks ORDER BY category ASC');
$catStmt->execute();
$categories = $catStmt->fetchAll();

$pageTitle = 'Task & Inventory Tracker';
require __DIR__ . '/../includes/header.php';

$success = flash('success');
$error = flash('error');

if ($success !== null) {
    echo '<p class="notice ok">' . e($success) . '</p>';
}
if ($error !== null) {
    echo '<p class="notice err">' . e($error) . '</p>';
}
?>

<div class="card">
    <h1>Task & Inventory Tracker</h1>
    <form method="get" class="grid">
        <input type="search" name="q" placeholder="Live search title/description..." value="<?= e((string) $filters['q']) ?>">

        <select name="category">
            <option value="">All categories</option>
            <?php foreach ($categories as $row): ?>
                <option value="<?= e((string) $row['category']) ?>" <?= ($filters['category'] === $row['category']) ? 'selected' : '' ?>>
                    <?= e((string) $row['category']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="status">
            <option value="">All statuses</option>
            <?php foreach (['pending', 'in_progress', 'done'] as $status): ?>
                <option value="<?= e($status) ?>" <?= ($filters['status'] === $status) ? 'selected' : '' ?>><?= e($status) ?></option>
            <?php endforeach; ?>
        </select>

        <input type="date" name="date_from" value="<?= e((string) $filters['date_from']) ?>">
        <input type="date" name="date_to" value="<?= e((string) $filters['date_to']) ?>">

        <button type="submit">Filter</button>
        <a href="/public/index.php" class="pill">Reset</a>
    </form>
</div>

<div class="card">
    <h2>Results (<?= count($tasks) ?>)</h2>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <?php
                $headers = ['ID', 'Title', 'Category', 'Qty', 'Status', 'Due', 'Priority', 'Actions'];
                for ($index = 0; $index < count($headers); $index++):
                    echo '<th>' . e($headers[$index]) . '</th>';
                endfor;
                ?>
            </tr>
            </thead>
            <tbody>
            <?php if ($tasks === []): ?>
                <tr><td colspan="8" class="muted">No records match your filters.</td></tr>
            <?php endif; ?>

            <?php foreach ($tasks as $task): ?>
                <tr>
                    <td><?= (int) $task['id'] ?></td>
                    <td>
                        <strong><?= e((string) $task['title']) ?></strong><br>
                        <span class="muted"><?= e((string) $task['description']) ?></span>
                    </td>
                    <td><?= e((string) $task['category']) ?></td>
                    <td><?= (int) $task['quantity'] ?></td>
                    <td><span class="pill"><?= e((string) $task['status']) ?></span></td>
                    <td><?= e((string) ($task['due_date'] ?? '-')) ?></td>
                    <td><span class="pill p<?= (int) $task['priority'] ?>">P<?= (int) $task['priority'] ?></span></td>
                    <td class="row">
                        <a href="/public/edit.php?id=<?= (int) $task['id'] ?>">Edit</a>
                        <form method="post" action="/public/delete.php" onsubmit="return confirm('Delete this task?')">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
                            <button type="submit" class="danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</div>
</body>
</html>
