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

require_once __DIR__ . '/../includes/security.php';

use App\Domain\TaskStatus;
use App\Repository\TaskRepository;
use App\Support\Db;

$repo = new TaskRepository(Db::connect());

$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'CSRF validation failed';
    } else {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            $errors[] = 'Title is required';
        }

        if ($errors === []) {
            $statusRaw = (string) ($_POST['status'] ?? TaskStatus::Pending->value);
            try {
                $status = TaskStatus::from($statusRaw);
            } catch (ValueError) {
                $status = TaskStatus::Pending;
            }

            $repo->create(
                title: $title,
                description: trim((string) ($_POST['description'] ?? 'No description')),
                category: trim((string) ($_POST['category'] ?? 'general')),
                quantity: max(0, (int) ($_POST['quantity'] ?? 1)),
                status: $status,
                priority: max(1, min(5, (int) ($_POST['priority'] ?? 3))),
                dueDate: ($_POST['due_date'] ?? '') !== '' ? (string) $_POST['due_date'] : null,
            );

            flash('success', 'Task created via repository + enum model.');
            redirect('/public/oop_demo.php');
        }
    }
}

$tasks = $repo->all();
$pageTitle = 'OOP + Composer Demo';
require __DIR__ . '/../includes/header.php';

$success = flash('success');
?>
<div class="card">
    <h1>OOP + PSR-4 Demo</h1>

    <?php if (!is_file($autoloadPath)): ?>
        <p class="notice err">`vendor/autoload.php` not found. Run <code>composer dump-autoload</code> for full PSR-4 flow. Fallback autoloader is active for demo convenience.</p>
    <?php endif; ?>

    <?php if ($success !== null): ?>
        <p class="notice ok"><?= e($success) ?></p>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <p class="notice err"><?= e($error) ?></p>
    <?php endforeach; ?>

    <form method="post" class="grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input name="title" placeholder="Title" required>
        <input name="description" placeholder="Description">
        <input name="category" placeholder="Category" value="general">
        <input type="number" name="quantity" value="1" min="0">
        <input type="date" name="due_date">
        <input type="number" name="priority" value="3" min="1" max="5">

        <select name="status">
            <?php foreach (TaskStatus::cases() as $status): ?>
                <option value="<?= e($status->value) ?>"><?= e($status->label()) ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit">Create</button>
    </form>
</div>

<div class="card">
    <h2>Tasks</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Category</th><th>Priority</th><th>Due</th><th>Created</th></tr></thead>
            <tbody>
            <?php foreach ($tasks as $task): ?>
                <tr>
                    <td><?= $task->id ?></td>
                    <td><?= e($task->title) ?></td>
                    <td><?= e($task->status->label()) ?></td>
                    <td><?= e($task->category) ?></td>
                    <td><?= $task->priority ?></td>
                    <td><?= e($task->dueDate?->format('Y-m-d') ?? '-') ?></td>
                    <td><?= e($task->createdAt->format('Y-m-d H:i:s')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</div>
</body>
</html>
