<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

$pdo = db();

$q = trim((string) ($_GET['q'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(title LIKE :q OR description LIKE :q)';
    $params['q'] = '%' . $q . '%';
}
if ($category !== '') {
    $where[] = 'category = :category';
    $params['category'] = $category;
}
if ($dateFrom !== '') {
    $where[] = 'due_date >= :date_from';
    $params['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'due_date <= :date_to';
    $params['date_to'] = $dateTo;
}

$whereSql = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));

$perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 5)));
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$countStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM tasks' . $whereSql);
$countStmt->execute($params);
$total = (int) ($countStmt->fetch()['total'] ?? 0);
$totalPages = max(1, (int) ceil($total / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$dataStmt = $pdo->prepare('SELECT id, title, category, due_date, status, priority FROM tasks' . $whereSql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset');
foreach ($params as $key => $value) {
    $dataStmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
}
$dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$dataStmt->execute();
$rows = $dataStmt->fetchAll();

$categoryStmt = $pdo->prepare('SELECT DISTINCT category FROM tasks ORDER BY category ASC');
$categoryStmt->execute();
$categories = $categoryStmt->fetchAll();

function page_qs(array $base, int $newPage): string
{
    $base['page'] = $newPage;
    return http_build_query($base);
}

$activeFilters = [
    'q' => $q,
    'category' => $category,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'per_page' => $perPage,
];

$pageTitle = 'Pagination Demo';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>Pagination Demo</h1>

    <form method="get" class="grid">
        <input type="search" name="q" placeholder="Search..." value="<?= e($q) ?>">

        <select name="category">
            <option value="">All categories</option>
            <?php foreach ($categories as $row): ?>
                <option value="<?= e((string) $row['category']) ?>" <?= $category === $row['category'] ? 'selected' : '' ?>><?= e((string) $row['category']) ?></option>
            <?php endforeach; ?>
        </select>

        <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
        <input type="date" name="date_to" value="<?= e($dateTo) ?>">
        <input type="number" min="1" max="100" name="per_page" value="<?= $perPage ?>">

        <button type="submit">Apply</button>
        <a class="pill" href="/public/pagination_demo.php">Reset</a>
    </form>

    <p class="muted">Total: <?= $total ?> | Page <?= $page ?> / <?= $totalPages ?></p>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>ID</th><th>Title</th><th>Category</th><th>Status</th><th>Priority</th><th>Due</th></tr></thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="6" class="muted">No records found.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= (int) $row['id'] ?></td>
                    <td><?= e((string) $row['title']) ?></td>
                    <td><?= e((string) $row['category']) ?></td>
                    <td><?= e((string) $row['status']) ?></td>
                    <td><?= (int) $row['priority'] ?></td>
                    <td><?= e((string) ($row['due_date'] ?? '-')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="row" style="margin-top:.75rem;">
        <?php if ($page > 1): ?>
            <a href="?<?= e(page_qs($activeFilters, $page - 1)) ?>">← Prev</a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
            <a href="?<?= e(page_qs($activeFilters, $page + 1)) ?>">Next →</a>
        <?php endif; ?>
    </div>
</div>
</div>
</body>
</html>
