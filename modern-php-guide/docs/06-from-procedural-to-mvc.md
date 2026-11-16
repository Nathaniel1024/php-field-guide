# 06) From Procedural to MVC-lite (No Framework)

This guide shows how to evolve a flat structure like:
- `public/index.php` (mixed routing + SQL + HTML)
- `api/tasks.php` (mixed HTTP handling + SQL)

into a clean, testable pattern using:
- **Front Controller** (single entry point)
- **Regex/match Router**
- **Repository** (all PDO access)
- **View helper** (`view()`) for template rendering

No external framework required.

---

## Target structure

```text
modern-php-guide/
├── public/
│   └── index.php                 # single front controller
├── src/
│   ├── Support/
│   │   ├── Db.php
│   │   └── view.php
│   ├── Http/
│   │   ├── Request.php
│   │   └── Response.php
│   ├── Repository/
│   │   └── TaskRepository.php
│   └── Controller/
│       └── TaskController.php
└── views/
    ├── tasks/
    │   ├── index.php
    │   └── show.php
    └── errors/
        └── 404.php
```

---

## 1) Single `public/index.php` front controller + regex/match router

This front controller handles routes like:
- `GET /tasks`
- `POST /tasks`
- `GET /tasks/{id}`

> If you serve from subfolder during local dev, ensure routing still points all requests to `public/index.php`.

```php
<?php

declare(strict_types=1);

$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

require_once __DIR__ . '/../src/Support/view.php';

use App\Controller\TaskController;
use App\Http\Request;
use App\Http\Response;

$request = Request::fromGlobals();
$controller = new TaskController();

$method = $request->method();
$path = $request->path();

$response = match (true) {
    $method === 'GET'  && preg_match('#^/tasks$#', $path) === 1 => $controller->index($request),
    $method === 'POST' && preg_match('#^/tasks$#', $path) === 1 => $controller->store($request),
    $method === 'GET'  && preg_match('#^/tasks/(\d+)$#', $path, $m) === 1 => $controller->show($request, (int) $m[1]),
    default => Response::html(view('errors/404', ['path' => $path]), 404),
};

$response->send();
```

---

## 2) Request/Response helpers (tiny HTTP layer)

### `src/Http/Request.php`

```php
<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $request
     * @param array<string, mixed> $server
     */
    private function __construct(
        private array $query,
        private array $request,
        private array $server,
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self($_GET, $_POST, $_SERVER);
    }

    public function method(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function path(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/';
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->request[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }
}
```

### `src/Http/Response.php`

```php
<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /** @param array<string, string> $headers */
    private function __construct(
        private string $body,
        private int $status,
        private array $headers = [],
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
```

---

## 3) Repository layer (all SQL isolated)

### `src/Repository/TaskRepository.php`

> This is the key refactor: **all PDO queries live here**, not in templates/controllers.

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Db;
use PDO;

final class TaskRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::connect();
    }

    /** @return list<array<string, mixed>> */
    public function all(?string $q = null): array
    {
        $sql = 'SELECT id, title, description, category, quantity, status, due_date, priority, created_at
                FROM tasks';
        $params = [];

        if ($q !== null && trim($q) !== '') {
            $sql .= ' WHERE title LIKE :q OR description LIKE :q';
            $params['q'] = '%' . trim($q) . '%';
        }

        $sql .= ' ORDER BY id DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tasks WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @param array{title:string,description:string,category:string,quantity:int,status:string,due_date:?string,priority:int} $data */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tasks (title, description, category, quantity, status, due_date, priority)
             VALUES (:title, :description, :category, :quantity, :status, :due_date, :priority)'
        );

        $stmt->execute($data);

        return (int) $this->pdo->lastInsertId();
    }
}
```

---

## 4) Lightweight controller using repository + response objects

### `src/Controller/TaskController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Repository\TaskRepository;

final class TaskController
{
    public function __construct(private ?TaskRepository $repo = null)
    {
        $this->repo ??= new TaskRepository();
    }

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $tasks = $this->repo->all($q !== '' ? $q : null);

        return Response::html(view('tasks/index', [
            'tasks' => $tasks,
            'q' => $q,
        ]));
    }

    public function show(Request $request, int $id): Response
    {
        $task = $this->repo->find($id);
        if ($task === null) {
            return Response::html(view('errors/404', ['path' => '/tasks/' . $id]), 404);
        }

        return Response::html(view('tasks/show', ['task' => $task]));
    }

    public function store(Request $request): Response
    {
        $title = trim((string) $request->input('title', ''));
        $description = trim((string) $request->input('description', ''));
        $category = trim((string) $request->input('category', 'general'));
        $status = trim((string) $request->input('status', 'pending'));
        $quantity = max(0, (int) $request->input('quantity', 1));
        $priority = max(1, min(5, (int) $request->input('priority', 3)));
        $dueDateRaw = trim((string) $request->input('due_date', ''));

        if ($title === '' || $description === '' || !in_array($status, ['pending', 'in_progress', 'done'], true)) {
            return Response::json(['error' => 'Validation failed'], 422);
        }

        $newId = $this->repo->create([
            'title' => $title,
            'description' => $description,
            'category' => $category,
            'quantity' => $quantity,
            'status' => $status,
            'due_date' => $dueDateRaw !== '' ? $dueDateRaw : null,
            'priority' => $priority,
        ]);

        return Response::redirect('/tasks/' . $newId, 302);
    }
}
```

---

## 5) `view()` helper for clean template rendering

### `src/Support/view.php`

```php
<?php

declare(strict_types=1);

/**
 * @param array<string, mixed> $data
 */
function view(string $template, array $data = []): string
{
    $basePath = dirname(__DIR__, 2) . '/views/';
    $file = $basePath . ltrim($template, '/') . '.php';

    if (!is_file($file)) {
        throw new RuntimeException('View not found: ' . $file);
    }

    extract($data, EXTR_SKIP);

    ob_start();
    require $file;

    return (string) ob_get_clean();
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
```

### `views/tasks/index.php`

```php
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Tasks</title></head>
<body>
<h1>Tasks</h1>

<form method="get" action="/tasks">
    <input type="search" name="q" value="<?= e((string) ($q ?? '')) ?>" placeholder="Search">
    <button type="submit">Search</button>
</form>

<ul>
    <?php foreach (($tasks ?? []) as $task): ?>
        <li>
            <a href="/tasks/<?= (int) $task['id'] ?>">#<?= (int) $task['id'] ?></a>
            <?= e((string) $task['title']) ?> (<?= e((string) $task['status']) ?>)
        </li>
    <?php endforeach; ?>
</ul>
</body>
</html>
```

### `views/tasks/show.php`

```php
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Task #<?= (int) $task['id'] ?></title></head>
<body>
<h1>Task #<?= (int) $task['id'] ?></h1>
<p><strong>Title:</strong> <?= e((string) $task['title']) ?></p>
<p><strong>Description:</strong> <?= e((string) $task['description']) ?></p>
<p><strong>Status:</strong> <?= e((string) $task['status']) ?></p>
<p><a href="/tasks">← Back to list</a></p>
</body>
</html>
```

### `views/errors/404.php`

```php
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>404</title></head>
<body>
<h1>404 Not Found</h1>
<p>No route matched: <code><?= e((string) ($path ?? 'unknown')) ?></code></p>
<p><a href="/tasks">Go to tasks</a></p>
</body>
</html>
```

---

## 6) Why this refactor works

- **Front Controller**: one HTTP entry point simplifies bootstrapping and security headers.
- **Router**: explicit `match + regex` gives clear route intent without framework magic.
- **Repository**: SQL stays in one place; controllers become thin and testable.
- **`view()` helper**: templates stay focused on presentation, not SQL/HTTP.
- **Incremental path**: you can migrate one route at a time from procedural files.

---

## 7) Migration plan from current repo state

1. Keep existing pages working.
2. Introduce `TaskRepository` for current endpoints first.
3. Add `Request`, `Response`, and `view()` helpers.
4. Move one route (`GET /tasks`) into front controller.
5. Move `POST /tasks`, then `GET /tasks/{id}`.
6. Retire legacy route files once parity is confirmed.

This avoids a risky big-bang rewrite.
