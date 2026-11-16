<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';

$errors = [];
$summary = null;

$host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1';
$port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306';
$name = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'modern_php_guide';
$user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root';
$pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '';

$pdo = null;
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name),
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (Throwable $e) {
    $errors[] = 'MySQL connection failed: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'CSRF validation failed';
    }

    if (!isset($_FILES['csv']) || !is_array($_FILES['csv'])) {
        $errors[] = 'CSV file is required';
    } else {
        $file = $_FILES['csv'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload error code: ' . (int) $file['error'];
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            $errors[] = 'Invalid uploaded file';
        }

        if ($errors === []) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($tmpPath);
            $allowedMimes = ['text/plain', 'text/csv', 'application/vnd.ms-excel'];
            if (!is_string($mime) || !in_array($mime, $allowedMimes, true)) {
                $errors[] = 'File is not a supported CSV MIME type';
            }
        }

        if ($errors === []) {
            $handle = fopen($tmpPath, 'rb');
            if ($handle === false) {
                $errors[] = 'Cannot open CSV file';
            } else {
                $header = fgetcsv($handle);
                $expected = ['title', 'description', 'category', 'quantity', 'status', 'due_date', 'priority'];
                if ($header === false || $header !== $expected) {
                    $errors[] = 'Invalid CSV header. Expected: ' . implode(',', $expected);
                } else {
                    $insertStmt = $pdo->prepare(
                        'INSERT INTO tasks (title, description, category, quantity, status, due_date, priority)
                         VALUES (:title, :description, :category, :quantity, :status, :due_date, :priority)'
                    );

                    $rowNumber = 1;
                    $imported = 0;
                    $rowErrors = [];

                    try {
                        $pdo->beginTransaction();

                        while (($row = fgetcsv($handle)) !== false) {
                            $rowNumber++;

                            if (count($row) !== 7) {
                                $rowErrors[] = "Row {$rowNumber}: expected 7 columns";
                                continue;
                            }

                            [$title, $description, $category, $quantityRaw, $status, $dueDateRaw, $priorityRaw] = $row;

                            $title = trim((string) $title);
                            $description = trim((string) $description);
                            $category = trim((string) $category);
                            $status = trim((string) $status);
                            $dueDateRaw = trim((string) $dueDateRaw);
                            $quantity = filter_var($quantityRaw, FILTER_VALIDATE_INT);
                            $priority = filter_var($priorityRaw, FILTER_VALIDATE_INT);

                            if ($title === '' || mb_strlen($title) > 150) {
                                $rowErrors[] = "Row {$rowNumber}: invalid title";
                                continue;
                            }
                            if ($description === '') {
                                $rowErrors[] = "Row {$rowNumber}: description required";
                                continue;
                            }
                            if ($category === '' || mb_strlen($category) > 50) {
                                $rowErrors[] = "Row {$rowNumber}: invalid category";
                                continue;
                            }
                            if ($quantity === false || $quantity < 0) {
                                $rowErrors[] = "Row {$rowNumber}: invalid quantity";
                                continue;
                            }
                            if (!in_array($status, ['pending', 'in_progress', 'done'], true)) {
                                $rowErrors[] = "Row {$rowNumber}: invalid status";
                                continue;
                            }
                            if ($priority === false || $priority < 1 || $priority > 5) {
                                $rowErrors[] = "Row {$rowNumber}: invalid priority";
                                continue;
                            }

                            $dueDate = null;
                            if ($dueDateRaw !== '') {
                                $date = DateTimeImmutable::createFromFormat('Y-m-d', $dueDateRaw);
                                if (!$date || $date->format('Y-m-d') !== $dueDateRaw) {
                                    $rowErrors[] = "Row {$rowNumber}: invalid due_date";
                                    continue;
                                }
                                $dueDate = $dueDateRaw;
                            }

                            $insertStmt->execute([
                                'title' => $title,
                                'description' => $description,
                                'category' => $category,
                                'quantity' => $quantity,
                                'status' => $status,
                                'due_date' => $dueDate,
                                'priority' => $priority,
                            ]);

                            $imported++;
                        }

                        $pdo->commit();
                        $summary = ['imported' => $imported, 'row_errors' => $rowErrors];
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $errors[] = 'Import failed, transaction rolled back: ' . $e->getMessage();
                    }
                }

                fclose($handle);
            }
        }
    }
}

$pageTitle = 'CSV Import Demo (MySQL)';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>CSV Import Demo (MySQL)</h1>
    <p class="muted">Uses <code>DB_HOST</code>, <code>DB_PORT</code>, <code>DB_NAME</code>, <code>DB_USER</code>, <code>DB_PASS</code>.</p>

    <?php foreach ($errors as $error): ?>
        <p class="notice err"><?= e($error) ?></p>
    <?php endforeach; ?>

    <?php if ($summary !== null): ?>
        <p class="notice ok">Imported rows: <?= (int) $summary['imported'] ?></p>
        <?php foreach ($summary['row_errors'] as $rowError): ?>
            <p class="notice err"><?= e((string) $rowError) ?></p>
        <?php endforeach; ?>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="file" name="csv" accept=".csv,text/csv" required>
        <button type="submit">Import CSV</button>
    </form>

    <p class="muted">Expected header: <code>title,description,category,quantity,status,due_date,priority</code></p>
</div>
</div>
</body>
</html>
