# 11) Relational Joins + Clean HTML Rendering (Without N+1)

This module shows a practical MySQL + PDO approach for relational data:
- Design foreign keys that protect integrity.
- Join related tables safely with explicit column aliases.
- Render relational results in HTML without key collisions or XSS bugs.
- Use aggregations for dashboard cards and `<select>` counts.
- Avoid the N+1 query problem in both 1-to-many and many-to-many layouts.

---

## 1) Foreign Keys & Relational Schema Design (`users`, `categories`, `tasks`)

Use InnoDB, explicit foreign keys, and indexes on FK columns.

- `tasks.category_id` uses `ON DELETE RESTRICT`: prevents deleting a category still used by tasks.
- `tasks.assignee_user_id` uses `ON DELETE CASCADE`: deleting a user removes their assigned tasks.

```sql
CREATE DATABASE IF NOT EXISTS task_board CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE task_board;

DROP TABLE IF EXISTS tasks;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

CREATE TABLE categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    color_hex CHAR(7) NOT NULL DEFAULT '#6b7280',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB;

CREATE TABLE tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    status ENUM('todo', 'in_progress', 'done') NOT NULL DEFAULT 'todo',
    effort_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    category_id BIGINT UNSIGNED NOT NULL,
    assignee_user_id BIGINT UNSIGNED NULL,
    due_date DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tasks_category
        FOREIGN KEY (category_id)
        REFERENCES categories(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_tasks_assignee
        FOREIGN KEY (assignee_user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    KEY idx_tasks_category_id (category_id),
    KEY idx_tasks_assignee_user_id (assignee_user_id),
    KEY idx_tasks_status (status),
    KEY idx_tasks_due_date (due_date),
    KEY idx_tasks_category_status (category_id, status)
) ENGINE=InnoDB;

INSERT INTO users (name, email) VALUES
('Alice Chen', 'alice@example.com'),
('Brian Kim', 'brian@example.com'),
('Diana Ross', 'diana@example.com');

INSERT INTO categories (name, color_hex) VALUES
('Work', '#2563eb'),
('Personal', '#16a34a'),
('Learning', '#9333ea');

INSERT INTO tasks (title, status, effort_minutes, category_id, assignee_user_id, due_date) VALUES
('Prepare monthly report', 'in_progress', 180, 1, 1, '2026-11-20'),
('Book annual health check', 'todo', 45, 2, NULL, '2026-11-21'),
('Finish PDO chapter draft', 'done', 120, 3, 2, '2026-11-18'),
('Team backlog grooming', 'todo', 90, 1, 3, '2026-11-25');
```

---

## 2) `INNER JOIN` vs `LEFT JOIN` in PDO (With Column Aliasing)

`INNER JOIN` only returns rows where both sides match.
`LEFT JOIN` keeps all left-table rows (`tasks`) even if related `users` row is `NULL`.

Always alias colliding column names when using `PDO::FETCH_ASSOC`.

### Complete demo: query + responsive HTML table

Save as `public/joins_demo.php`:

```php
<?php

declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=task_board;charset=utf8mb4',
    'root',
    '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$joinType = ($_GET['join'] ?? 'left') === 'inner' ? 'INNER JOIN' : 'LEFT JOIN';

$sql = "
    SELECT
        tasks.id AS task_id,
        tasks.title,
        tasks.status,
        tasks.due_date,
        categories.id AS category_id,
        categories.name AS category_name,
        users.id AS assignee_id,
        users.name AS assignee_name
    FROM tasks
    INNER JOIN categories ON categories.id = tasks.category_id
    {$joinType} users ON users.id = tasks.assignee_user_id
    ORDER BY tasks.id DESC
";

$rows = $pdo->query($sql)->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>INNER JOIN vs LEFT JOIN</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 20px; }
        .toolbar { margin-bottom: 12px; display: flex; gap: 8px; flex-wrap: wrap; }
        .btn { padding: 8px 12px; border-radius: 8px; border: 1px solid #d1d5db; text-decoration: none; color: #111827; }
        .btn.active { background: #111827; color: #fff; border-color: #111827; }
        .table-wrap { overflow-x: auto; border: 1px solid #e5e7eb; border-radius: 12px; }
        table { width: 100%; border-collapse: collapse; min-width: 760px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #f1f5f9; }
        th { background: #f8fafc; font-weight: 600; }
        .muted { color: #6b7280; }
        .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; background: #e5e7eb; font-size: 12px; }
    </style>
</head>
<body>
    <h1>Tasks with Related Category + Assignee</h1>

    <div class="toolbar">
        <a class="btn <?= $joinType === 'LEFT JOIN' ? 'active' : '' ?>" href="?join=left">LEFT JOIN</a>
        <a class="btn <?= $joinType === 'INNER JOIN' ? 'active' : '' ?>" href="?join=inner">INNER JOIN</a>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Task ID</th>
                    <th>Title</th>
                    <th>Category</th>
                    <th>Assignee</th>
                    <th>Status</th>
                    <th>Due Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= (int) $row['task_id'] ?></td>
                        <td><?= e((string) $row['title']) ?></td>
                        <td><?= e((string) $row['category_name']) ?></td>
                        <td><?= e((string) ($row['assignee_name'] ?? 'Unassigned')) ?></td>
                        <td><span class="pill"><?= e((string) $row['status']) ?></span></td>
                        <td class="muted"><?= e((string) ($row['due_date'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
```

---

## 3) Aggregations (`COUNT`, `SUM`, `GROUP BY`) for Cards + Dropdown Counts

You can calculate dashboard metrics and dropdown labels from one grouped query.

### Complete demo: grouped totals + `<select>` labels

Save as `public/category_totals_demo.php`:

```php
<?php

declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=task_board;charset=utf8mb4',
    'root',
    '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$sql = "
    SELECT
        c.id,
        c.name,
        COUNT(t.id) AS total_tasks,
        SUM(CASE WHEN t.status = 'done' THEN 1 ELSE 0 END) AS done_tasks,
        COALESCE(SUM(t.effort_minutes), 0) AS total_effort_minutes
    FROM categories c
    LEFT JOIN tasks t ON t.category_id = c.id
    GROUP BY c.id, c.name
    ORDER BY c.name ASC
";

$categoryStats = $pdo->query($sql)->fetchAll();
$totalTasks = array_sum(array_map(static fn (array $row): int => (int) $row['total_tasks'], $categoryStats));
$totalDone = array_sum(array_map(static fn (array $row): int => (int) $row['done_tasks'], $categoryStats));
$totalEffort = array_sum(array_map(static fn (array $row): int => (int) $row['total_effort_minutes'], $categoryStats));
$selectedCategoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Category Aggregations</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 20px; }
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 16px; }
        .card { border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px; background: #fff; }
        .label { color: #6b7280; font-size: 13px; }
        .value { font-size: 24px; font-weight: 700; margin-top: 4px; }
        select { padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; min-width: 260px; }
        table { margin-top: 16px; width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; text-align: left; }
        th { background: #f8fafc; }
    </style>
</head>
<body>
    <h1>Category Dashboard</h1>

    <div class="cards">
        <div class="card">
            <div class="label">Total Tasks</div>
            <div class="value"><?= $totalTasks ?></div>
        </div>
        <div class="card">
            <div class="label">Done Tasks</div>
            <div class="value"><?= $totalDone ?></div>
        </div>
        <div class="card">
            <div class="label">Total Effort (minutes)</div>
            <div class="value"><?= $totalEffort ?></div>
        </div>
    </div>

    <form method="get">
        <label for="category_id">Filter by category</label><br>
        <select name="category_id" id="category_id" onchange="this.form.submit()">
            <option value="0">All Categories</option>
            <?php foreach ($categoryStats as $category): ?>
                <?php $id = (int) $category['id']; ?>
                <option value="<?= $id ?>" <?= $selectedCategoryId === $id ? 'selected' : '' ?>>
                    <?= e((string) $category['name']) ?> (<?= (int) $category['total_tasks'] ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <table>
        <thead>
            <tr>
                <th>Category</th>
                <th>Total Tasks</th>
                <th>Done Tasks</th>
                <th>Effort Minutes</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($categoryStats as $category): ?>
                <tr>
                    <td><?= e((string) $category['name']) ?></td>
                    <td><?= (int) $category['total_tasks'] ?></td>
                    <td><?= (int) $category['done_tasks'] ?></td>
                    <td><?= (int) $category['total_effort_minutes'] ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
```

---

## 4) Solving the N+1 Query Problem in HTML Rendering

### 4.1 Anti-pattern: query inside a loop

This pattern is expensive because each parent row triggers another query.

```php
<?php

declare(strict_types=1);

$taskRows = $pdo->query('SELECT id, title FROM tasks ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);

foreach ($taskRows as $task) {
    echo '<h3>' . htmlspecialchars((string) $task['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>';

    $tagStmt = $pdo->prepare('SELECT name FROM tags WHERE task_id = :task_id ORDER BY name');
    $tagStmt->execute(['task_id' => (int) $task['id']]);

    $tags = $tagStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tags as $tag) {
        echo '<span class="badge">' . htmlspecialchars((string) $tag['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span> ';
    }
}
```

### 4.2 Setup for many-to-many tags (`tasks` ↔ `tags`)

```sql
CREATE TABLE IF NOT EXISTS tags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    UNIQUE KEY uq_tags_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS task_tag (
    task_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (task_id, tag_id),
    CONSTRAINT fk_task_tag_task
        FOREIGN KEY (task_id)
        REFERENCES tasks(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_task_tag_tag
        FOREIGN KEY (tag_id)
        REFERENCES tags(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    KEY idx_task_tag_tag_id (tag_id)
) ENGINE=InnoDB;

INSERT INTO tags (name) VALUES
('backend'), ('urgent'), ('frontend'), ('research')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT IGNORE INTO task_tag (task_id, tag_id) VALUES
(1, 1), (1, 2),
(2, 4),
(3, 1), (3, 4),
(4, 2), (4, 3);
```

### 4.3 Solution A: one query with `GROUP_CONCAT`

Fetch tasks and their tags in one result set.

Save as `public/n_plus_one_solution_group_concat.php`:

```php
<?php

declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=task_board;charset=utf8mb4',
    'root',
    '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$sql = "
    SELECT
        t.id AS task_id,
        t.title,
        c.name AS category_name,
        GROUP_CONCAT(DISTINCT g.name ORDER BY g.name SEPARATOR ',') AS tag_csv
    FROM tasks t
    INNER JOIN categories c ON c.id = t.category_id
    LEFT JOIN task_tag tt ON tt.task_id = t.id
    LEFT JOIN tags g ON g.id = tt.tag_id
    GROUP BY t.id, t.title, c.name
    ORDER BY t.id DESC
";

$rows = $pdo->query($sql)->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>N+1 Solution with GROUP_CONCAT</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 20px; }
        .task { border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px; margin-bottom: 10px; }
        .meta { color: #6b7280; font-size: 14px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; background: #dbeafe; margin-right: 6px; margin-top: 6px; font-size: 12px; }
    </style>
</head>
<body>
    <h1>Tasks + Tags (Single Query)</h1>

    <?php foreach ($rows as $row): ?>
        <?php $tagList = empty($row['tag_csv']) ? [] : explode(',', (string) $row['tag_csv']); ?>
        <article class="task">
            <strong>#<?= (int) $row['task_id'] ?> <?= e((string) $row['title']) ?></strong>
            <div class="meta">Category: <?= e((string) $row['category_name']) ?></div>
            <div>
                <?php if ($tagList === []): ?>
                    <span class="meta">No tags</span>
                <?php else: ?>
                    <?php foreach ($tagList as $tag): ?>
                        <span class="badge"><?= e($tag) ?></span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</body>
</html>
```

### 4.4 Solution B: flat join + grouping in PHP

This approach is useful when you need nested HTML sections like category headers and task lists.

Save as `public/n_plus_one_solution_php_grouping.php`:

```php
<?php

declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=task_board;charset=utf8mb4',
    'root',
    '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$sql = "
    SELECT
        c.id AS category_id,
        c.name AS category_name,
        t.id AS task_id,
        t.title AS task_title,
        t.status,
        u.name AS assignee_name
    FROM categories c
    LEFT JOIN tasks t ON t.category_id = c.id
    LEFT JOIN users u ON u.id = t.assignee_user_id
    ORDER BY c.name ASC, t.id DESC
";

$rows = $pdo->query($sql)->fetchAll();
$grouped = [];

foreach ($rows as $row) {
    $categoryId = (int) $row['category_id'];
    if (!isset($grouped[$categoryId])) {
        $grouped[$categoryId] = [
            'category_name' => (string) $row['category_name'],
            'tasks' => [],
        ];
    }

    if ($row['task_id'] !== null) {
        $grouped[$categoryId]['tasks'][] = [
            'task_id' => (int) $row['task_id'],
            'task_title' => (string) $row['task_title'],
            'status' => (string) $row['status'],
            'assignee_name' => $row['assignee_name'] === null ? 'Unassigned' : (string) $row['assignee_name'],
        ];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>N+1 Solution with PHP Grouping</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 20px; }
        section { border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px; margin-bottom: 12px; }
        h3 { margin: 0 0 10px; }
        ul { margin: 0; padding-left: 20px; }
        li { margin-bottom: 6px; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
    <h1>Categories with Nested Tasks (Single Flat Query)</h1>

    <?php foreach ($grouped as $category): ?>
        <section>
            <h3><?= e($category['category_name']) ?></h3>
            <?php if ($category['tasks'] === []): ?>
                <p class="muted">No tasks in this category.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($category['tasks'] as $task): ?>
                        <li>
                            #<?= $task['task_id'] ?>
                            <?= e($task['task_title']) ?>
                            — <?= e($task['status']) ?>
                            — <?= e($task['assignee_name']) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</body>
</html>
```

---

## Practical checklist for relational HTML rendering

- Alias colliding columns whenever multiple tables share `id`, `name`, or `created_at`.
- Use `LEFT JOIN` when missing relationships should still render a row.
- Use `COALESCE` or PHP fallbacks (`?? 'Unassigned'`) for nullable join fields.
- Aggregate once with `COUNT`/`SUM` for cards, filters, and quick stats.
- Replace looped per-row queries with one join query + SQL or PHP grouping.
- Escape all dynamic HTML output with `htmlspecialchars()`.
