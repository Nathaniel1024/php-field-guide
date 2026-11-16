# 04) Modern PHP 8.2 OOP and Composer (PSR-4)

This module is a practical refresher for scaling from procedural scripts to maintainable PHP architecture.

## 1) PHP 8.2 OOP essentials: promotion, `readonly`, enums, `match`

### Runnable domain example

```php
<?php
declare(strict_types=1);

enum TaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InProgress => 'In Progress',
            self::Done => 'Done',
        };
    }
}

final class Task
{
    public function __construct(
        public readonly int $id,
        public string $title,
        public string $description,
        public TaskStatus $status,
        public int $priority = 3,
        public ?DateTimeImmutable $dueDate = null,
        public readonly DateTimeImmutable $createdAt = new DateTimeImmutable(),
    ) {
        if ($this->priority < 1 || $this->priority > 5) {
            throw new InvalidArgumentException('priority must be 1..5');
        }
    }

    public function markDone(): void
    {
        $this->status = TaskStatus::Done;
    }

    public function urgencyBadge(): string
    {
        return match (true) {
            $this->priority >= 5 => 'critical',
            $this->priority >= 4 => 'high',
            $this->priority >= 2 => 'normal',
            default => 'low',
        };
    }
}

// Usage
$task = new Task(
    id: 101,
    title: 'Patch API timeout handling',
    description: 'Add retries and proper status code checks',
    status: TaskStatus::InProgress,
    priority: 4,
    dueDate: new DateTimeImmutable('2026-12-01'),
);

$task->markDone();

echo $task->status->value . PHP_EOL;      // done
echo $task->status->label() . PHP_EOL;    // Done
echo $task->urgencyBadge() . PHP_EOL;     // high
```

### HTML status dropdown powered by backed enums

```php
<?php
declare(strict_types=1);

enum TaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InProgress => 'In Progress',
            self::Done => 'Done',
        };
    }
}

$selected = $_GET['status'] ?? TaskStatus::Pending->value;
?>
<select name="status">
    <?php foreach (TaskStatus::cases() as $status): ?>
        <option value="<?= htmlspecialchars($status->value, ENT_QUOTES, 'UTF-8') ?>" <?= $selected === $status->value ? 'selected' : '' ?>>
            <?= htmlspecialchars($status->label(), ENT_QUOTES, 'UTF-8') ?>
        </option>
    <?php endforeach; ?>
</select>
```

---

## 2) Composer + PSR-4 autoloading for scalable structure

### Project structure

```text
modern-php-guide/
├── composer.json
├── src/
│   ├── Domain/
│   │   ├── Task.php
│   │   └── TaskStatus.php
│   ├── Repository/
│   │   └── TaskRepository.php
│   └── Support/
│       └── Db.php
└── public/
    └── oop_demo.php
```

### `composer.json`

```json
{
  "name": "field-guide/modern-php-starter",
  "description": "Modern PHP Field Guide & Starter Kit",
  "type": "project",
  "require": {
    "php": ">=8.2"
  },
  "autoload": {
    "psr-4": {
      "App\\": "src/"
    }
  },
  "scripts": {
    "serve": "php -S localhost:8000",
    "lint": "find . -name '*.php' -print0 | xargs -0 -n1 php -l"
  }
}
```

Run:

```bash
composer dump-autoload
```

### `src/Support/Db.php`

```php
<?php
declare(strict_types=1);

namespace App\Support;

use PDO;

final class Db
{
    public static function connect(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $pdo = new PDO('sqlite:' . dirname(__DIR__, 2) . '/database.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $pdo;
    }
}
```

### `src/Domain/TaskStatus.php`

```php
<?php
declare(strict_types=1);

namespace App\Domain;

enum TaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Done = 'done';
}
```

### `src/Domain/Task.php`

```php
<?php
declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

final class Task
{
    public function __construct(
        public readonly int $id,
        public string $title,
        public string $description,
        public string $category,
        public int $quantity,
        public TaskStatus $status,
        public int $priority,
        public ?DateTimeImmutable $dueDate,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
```

### `src/Repository/TaskRepository.php`

```php
<?php
declare(strict_types=1);

namespace App\Repository;

use App\Domain\Task;
use App\Domain\TaskStatus;
use DateTimeImmutable;
use PDO;

final class TaskRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<Task> */
    public function all(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tasks ORDER BY id DESC');
        $stmt->execute();

        $tasks = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tasks[] = $this->map($row);
        }

        return $tasks;
    }

    public function create(string $title, string $description, string $category, int $quantity, TaskStatus $status, int $priority, ?string $dueDate): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tasks (title, description, category, quantity, status, due_date, priority)
             VALUES (:title, :description, :category, :quantity, :status, :due_date, :priority)'
        );
        $stmt->execute([
            'title' => $title,
            'description' => $description,
            'category' => $category,
            'quantity' => $quantity,
            'status' => $status->value,
            'due_date' => $dueDate,
            'priority' => $priority,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): Task
    {
        return new Task(
            id: (int) $row['id'],
            title: (string) $row['title'],
            description: (string) $row['description'],
            category: (string) $row['category'],
            quantity: (int) $row['quantity'],
            status: TaskStatus::from((string) $row['status']),
            priority: (int) $row['priority'],
            dueDate: isset($row['due_date']) && $row['due_date'] !== null ? new DateTimeImmutable((string) $row['due_date']) : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
```

### `public/oop_demo.php`

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Domain\TaskStatus;
use App\Repository\TaskRepository;
use App\Support\Db;

$repo = new TaskRepository(Db::connect());

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $title = trim((string) ($_POST['title'] ?? ''));
    if ($title !== '') {
        $repo->create(
            title: $title,
            description: trim((string) ($_POST['description'] ?? 'No description')),
            category: trim((string) ($_POST['category'] ?? 'general')),
            quantity: max(0, (int) ($_POST['quantity'] ?? 1)),
            status: TaskStatus::from((string) ($_POST['status'] ?? TaskStatus::Pending->value)),
            priority: max(1, min(5, (int) ($_POST['priority'] ?? 3))),
            dueDate: ($_POST['due_date'] ?? '') !== '' ? (string) $_POST['due_date'] : null,
        );
    }
}

$tasks = $repo->all();
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>OOP Demo</title></head>
<body>
<h1>OOP + PSR-4 Demo</h1>

<form method="post">
    <input name="title" placeholder="Title" required>
    <input name="description" placeholder="Description">
    <input name="category" placeholder="Category" value="general">
    <input type="number" name="quantity" value="1" min="0">
    <input type="date" name="due_date">
    <input type="number" name="priority" value="3" min="1" max="5">
    <select name="status">
        <?php foreach (TaskStatus::cases() as $status): ?>
            <option value="<?= htmlspecialchars($status->value, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status->value, ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Create</button>
</form>

<ul>
    <?php foreach ($tasks as $task): ?>
        <li>#<?= $task->id ?> - <?= htmlspecialchars($task->title, ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($task->status->value, ENT_QUOTES, 'UTF-8') ?>)</li>
    <?php endforeach; ?>
</ul>
</body>
</html>
```

---

## 3) Minimal migration path from procedural to OOP

1. Keep controllers/pages procedural (`public/*.php`) first.
2. Move DB access into repositories.
3. Introduce small domain objects (`Task`, `TaskStatus`).
4. Add service layer only when business rules grow.
5. Keep framework-free until complexity truly requires one.

---

## 4) Foreach by-reference gotcha (`unset($value)`)

If you iterate by reference and then reuse the same variable, PHP keeps a reference to the last element.

### Buggy pattern

```php
<?php
declare(strict_types=1);

$items = [1, 2, 3];

foreach ($items as &$value) {
    $value *= 2;
}

foreach ($items as $value) {
    // Unexpected side effects can happen because $value is still a reference.
}

var_dump($items);
```

### Correct pattern

```php
<?php
declare(strict_types=1);

$items = [1, 2, 3];

foreach ($items as &$value) {
    $value *= 2;
}
unset($value); // Critical: break lingering reference.

foreach ($items as $value) {
    // Safe normal iteration.
}

var_dump($items); // [2, 4, 6]
```

