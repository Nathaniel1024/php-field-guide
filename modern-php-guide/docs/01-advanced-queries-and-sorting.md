# 01) Advanced Queries, Sorting, Pagination, Transactions, and Timezones

This module covers production patterns that break in basic tutorials.

## 1. Dynamic `ORDER BY` with allowlists (and why placeholders fail)

PDO placeholders (`:name`) only bind **values**, not SQL identifiers like column names or `ASC`/`DESC` tokens.

This is invalid SQL:

```php
$stmt = $pdo->prepare('SELECT * FROM tasks ORDER BY :column :dir');
```

If you need dynamic sorting, always map untrusted input to strict allowlists.

### Runnable example

```php
<?php
declare(strict_types=1);

$pdo = new PDO('sqlite:' . __DIR__ . '/../database.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$sortColumnInput = $_GET['sort'] ?? 'created_at';
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

$sql = "SELECT id, title, priority, due_date, created_at FROM tasks ORDER BY {$sortColumn} {$sortDirection}, id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$rows = $stmt->fetchAll();

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'applied_sort' => ['column' => $sortColumn, 'direction' => $sortDirection],
    'data' => $rows,
], JSON_PRETTY_PRINT);
```

---

## 2. Dynamic `WHERE IN (...)` for multi-select filters

When users can choose multiple categories/statuses, build a variable placeholder list safely.

### Runnable example

```php
<?php
declare(strict_types=1);

$pdo = new PDO('sqlite:' . __DIR__ . '/../database.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$selectedCategories = $_GET['categories'] ?? []; // e.g. categories[]=office&categories[]=it
$selectedStatuses = $_GET['statuses'] ?? [];     // e.g. statuses[]=pending

if (!is_array($selectedCategories)) {
    $selectedCategories = [];
}
if (!is_array($selectedStatuses)) {
    $selectedStatuses = [];
}

$allowedCategories = ['office', 'it', 'hr', 'warehouse', 'training'];
$allowedStatuses = ['pending', 'in_progress', 'done'];

$selectedCategories = array_values(array_intersect($allowedCategories, $selectedCategories));
$selectedStatuses = array_values(array_intersect($allowedStatuses, $selectedStatuses));

$whereParts = [];
$params = [];

if ($selectedCategories !== []) {
    $catPlaceholders = [];
    foreach ($selectedCategories as $index => $category) {
        $key = 'cat' . $index;
        $catPlaceholders[] = ':' . $key;
        $params[$key] = $category;
    }
    $whereParts[] = 'category IN (' . implode(', ', $catPlaceholders) . ')';
}

if ($selectedStatuses !== []) {
    $statusPlaceholders = [];
    foreach ($selectedStatuses as $index => $status) {
        $key = 'status' . $index;
        $statusPlaceholders[] = ':' . $key;
        $params[$key] = $status;
    }
    $whereParts[] = 'status IN (' . implode(', ', $statusPlaceholders) . ')';
}

$sql = 'SELECT id, title, category, status, priority FROM tasks';
if ($whereParts !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $whereParts);
}
$sql .= ' ORDER BY priority DESC, id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['filters' => compact('selectedCategories', 'selectedStatuses'), 'data' => $rows], JSON_PRETTY_PRINT);
```

---

## 3. Pagination with total pages and filter preservation

Use two queries:
1. `COUNT(*)` for total matching records.
2. Actual result query with `LIMIT` + `OFFSET`.

### Runnable example (server-rendered HTML)

```php
<?php
declare(strict_types=1);

$pdo = new PDO('sqlite:' . __DIR__ . '/../database.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

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

$dataSql = 'SELECT id, title, category, due_date FROM tasks' . $whereSql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset';
$dataStmt = $pdo->prepare($dataSql);
foreach ($params as $key => $value) {
    $dataStmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
}
$dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$dataStmt->execute();
$rows = $dataStmt->fetchAll();

function qs(array $base, int $newPage): string
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
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Pagination Demo</title></head>
<body>
<h1>Pagination Demo</h1>
<p>Total records: <?= $total ?> | Page <?= $page ?> / <?= $totalPages ?></p>

<ul>
    <?php foreach ($rows as $row): ?>
        <li>#<?= (int) $row['id'] ?> - <?= htmlspecialchars((string) $row['title'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string) $row['category'], ENT_QUOTES, 'UTF-8') ?>)</li>
    <?php endforeach; ?>
</ul>

<p>
    <?php if ($page > 1): ?>
        <a href="?<?= htmlspecialchars(qs($activeFilters, $page - 1), ENT_QUOTES, 'UTF-8') ?>">Prev</a>
    <?php endif; ?>
    <?php if ($page < $totalPages): ?>
        <a href="?<?= htmlspecialchars(qs($activeFilters, $page + 1), ENT_QUOTES, 'UTF-8') ?>">Next</a>
    <?php endif; ?>
</p>
</body>
</html>
```

---

## 4. PDO transactions with `beginTransaction`, `commit`, `rollBack`

When one use case affects multiple tables/rows, wrap everything in one transaction.

### Runnable example

```php
<?php
declare(strict_types=1);

$pdo = new PDO('sqlite:' . __DIR__ . '/../database.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

try {
    $pdo->beginTransaction();

    $taskStmt = $pdo->prepare(
        'INSERT INTO tasks (title, description, category, quantity, status, due_date, priority)
         VALUES (:title, :description, :category, :quantity, :status, :due_date, :priority)'
    );
    $taskStmt->execute([
        'title' => 'Bulk receive monitor stands',
        'description' => 'Warehouse receiving batch #A17',
        'category' => 'warehouse',
        'quantity' => 14,
        'status' => 'in_progress',
        'due_date' => '2026-12-05',
        'priority' => 4,
    ]);

    $newTaskId = (int) $pdo->lastInsertId();

    $auditStmt = $pdo->prepare(
        'INSERT INTO tasks (title, description, category, quantity, status, due_date, priority)
         VALUES (:title, :description, :category, :quantity, :status, :due_date, :priority)'
    );
    $auditStmt->execute([
        'title' => 'Audit log for task #' . $newTaskId,
        'description' => 'Created by transaction demo',
        'category' => 'it',
        'quantity' => 1,
        'status' => 'done',
        'due_date' => null,
        'priority' => 1,
    ]);

    $pdo->commit();
    echo "Transaction committed. New task ID: {$newTaskId}" . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo 'Transaction rolled back: ' . $e->getMessage() . PHP_EOL;
}
```

---

## 5. Date/timezone correctness with `DateTimeImmutable` (fix end-of-day bugs)

Classic bug: developers filter by `<= 2026-11-16 23:59:59` while DB stores UTC and app runs local timezone, causing off-by-hours errors.

Safer approach:
- Convert local user date range to UTC.
- Use half-open interval: `created_at >= :start_utc AND created_at < :end_utc_exclusive`.

### Runnable example

```php
<?php
declare(strict_types=1);

$pdo = new PDO('sqlite:' . __DIR__ . '/../database.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$userTimezone = new DateTimeZone('Asia/Shanghai');
$storageTimezone = new DateTimeZone('UTC');

$dateFromInput = $_GET['date_from'] ?? '2026-11-01';
$dateToInput = $_GET['date_to'] ?? '2026-11-30';

$startLocal = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateFromInput . ' 00:00:00', $userTimezone);
$endLocalExclusive = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateToInput . ' 00:00:00', $userTimezone)?->modify('+1 day');

if (!$startLocal || !$endLocalExclusive) {
    http_response_code(422);
    echo 'Invalid date input';
    exit;
}

$startUtc = $startLocal->setTimezone($storageTimezone)->format('Y-m-d H:i:s');
$endUtcExclusive = $endLocalExclusive->setTimezone($storageTimezone)->format('Y-m-d H:i:s');

$stmt = $pdo->prepare(
    'SELECT id, title, created_at
     FROM tasks
     WHERE created_at >= :start_utc
       AND created_at < :end_utc_exclusive
     ORDER BY created_at ASC'
);
$stmt->execute([
    'start_utc' => $startUtc,
    'end_utc_exclusive' => $endUtcExclusive,
]);

$rows = $stmt->fetchAll();

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'input_timezone' => $userTimezone->getName(),
    'storage_timezone' => $storageTimezone->getName(),
    'range_local' => [$startLocal->format(DateTimeInterface::ATOM), $endLocalExclusive->format(DateTimeInterface::ATOM)],
    'range_utc_exclusive' => [$startUtc, $endUtcExclusive],
    'count' => count($rows),
    'data' => $rows,
], JSON_PRETTY_PRINT);
```

