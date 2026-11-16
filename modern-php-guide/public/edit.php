<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

$pdo = db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id <= 0) {
    flash('error', 'Invalid task ID.');
    redirect('/public/index.php');
}

$taskStmt = $pdo->prepare('SELECT * FROM tasks WHERE id = :id');
$taskStmt->execute(['id' => $id]);
$task = $taskStmt->fetch();

if ($task === false) {
    flash('error', 'Task not found.');
    redirect('/public/index.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? ''));
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $status = trim((string) ($_POST['status'] ?? 'pending'));
    $dueDate = trim((string) ($_POST['due_date'] ?? ''));
    $priority = (int) ($_POST['priority'] ?? 3);
    $token = $_POST['csrf_token'] ?? null;

    if (!verify_csrf(is_string($token) ? $token : null)) {
        $errors[] = 'CSRF validation failed. Refresh and try again.';
    }
    if ($title === '' || mb_strlen($title) > 150) {
        $errors[] = 'Title is required and must be <= 150 characters.';
    }
    if ($description === '') {
        $errors[] = 'Description is required.';
    }
    if ($category === '' || mb_strlen($category) > 50) {
        $errors[] = 'Category is required and must be <= 50 characters.';
    }
    if (!in_array($status, ['pending', 'in_progress', 'done'], true)) {
        $errors[] = 'Invalid status selected.';
    }
    if ($quantity < 0) {
        $errors[] = 'Quantity cannot be negative.';
    }
    if ($priority < 1 || $priority > 5) {
        $errors[] = 'Priority must be between 1 and 5.';
    }
    if ($dueDate !== '' && DateTime::createFromFormat('Y-m-d', $dueDate) === false) {
        $errors[] = 'Due date must be in YYYY-MM-DD format.';
    }

    if ($errors === []) {
        $stmt = $pdo->prepare('UPDATE tasks SET
                                title = :title,
                                description = :description,
                                category = :category,
                                quantity = :quantity,
                                status = :status,
                                due_date = :due_date,
                                priority = :priority,
                                updated_at = CURRENT_TIMESTAMP
                               WHERE id = :id');
        $stmt->execute([
            'title' => $title,
            'description' => $description,
            'category' => $category,
            'quantity' => $quantity,
            'status' => $status,
            'due_date' => $dueDate !== '' ? $dueDate : null,
            'priority' => $priority,
            'id' => $id,
        ]);

        flash('success', 'Task updated successfully.');
        redirect('/public/index.php');
    }

    $task = [
        'id' => $id,
        'title' => $title,
        'description' => $description,
        'category' => $category,
        'quantity' => $quantity,
        'status' => $status,
        'due_date' => $dueDate,
        'priority' => $priority,
    ];
}

$pageTitle = 'Edit Task';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>Edit Task #<?= (int) $task['id'] ?></h1>

    <?php foreach ($errors as $error): ?>
        <p class="notice err"><?= e($error) ?></p>
    <?php endforeach; ?>

    <form method="post" class="grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">

        <input name="title" required maxlength="150" value="<?= e((string) $task['title']) ?>">
        <input name="category" required maxlength="50" value="<?= e((string) $task['category']) ?>">
        <input name="quantity" type="number" min="0" value="<?= (int) $task['quantity'] ?>">

        <select name="status">
            <?php foreach (['pending', 'in_progress', 'done'] as $status): ?>
                <option value="<?= e($status) ?>" <?= $task['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
            <?php endforeach; ?>
        </select>

        <input name="due_date" type="date" value="<?= e((string) ($task['due_date'] ?? '')) ?>">
        <input name="priority" type="number" min="1" max="5" value="<?= (int) $task['priority'] ?>">

        <textarea name="description" required><?= e((string) $task['description']) ?></textarea>

        <button type="submit">Update</button>
    </form>
</div>
</div>
</body>
</html>
