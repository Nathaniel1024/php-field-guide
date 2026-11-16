# 02) File Uploads and CSV Import/Export (Production-Safe)

This module gives copy-paste implementations for secure uploads and large CSV workflows.

## 1. Bulletproof file/image uploads

Goals:
- Trust server-side MIME inspection (`finfo_file()`), not client filename.
- Avoid collisions with `random_bytes()`.
- Prevent path traversal by never using user path fragments.
- Limit file size and extension.

### Runnable example (`public/upload_demo.php` style)

```php
<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf'])
        && is_string($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}

$uploadRoot = __DIR__ . '/uploads';
if (!is_dir($uploadRoot) && !mkdir($uploadRoot, 0755, true) && !is_dir($uploadRoot)) {
    throw new RuntimeException('Failed to create upload directory');
}

$errors = [];
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'CSRF validation failed';
    }

    if (!isset($_FILES['asset']) || !is_array($_FILES['asset'])) {
        $errors[] = 'No file uploaded';
    } else {
        $file = $_FILES['asset'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload failed with error code: ' . (int) $file['error'];
        }

        $maxBytes = 2 * 1024 * 1024; // 2MB
        if ((int) ($file['size'] ?? 0) > $maxBytes) {
            $errors[] = 'File exceeds 2MB limit';
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            $errors[] = 'Invalid upload temp file';
        }

        $allowedMimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
        ];

        $detectedMime = null;
        if ($errors === []) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detectedMime = $finfo->file($tmpPath);
            if (!is_string($detectedMime) || !isset($allowedMimeToExt[$detectedMime])) {
                $errors[] = 'Unsupported MIME type';
            }
        }

        if ($errors === [] && $detectedMime !== null) {
            $safeName = bin2hex(random_bytes(16)) . '.' . $allowedMimeToExt[$detectedMime];
            $destination = $uploadRoot . DIRECTORY_SEPARATOR . $safeName;

            $realUploadRoot = realpath($uploadRoot);
            if ($realUploadRoot === false) {
                $errors[] = 'Upload root path error';
            } else {
                $resolvedDestinationDir = realpath(dirname($destination));
                if ($resolvedDestinationDir === false || strpos($resolvedDestinationDir, $realUploadRoot) !== 0) {
                    $errors[] = 'Directory traversal detected';
                }
            }

            if ($errors === [] && !move_uploaded_file($tmpPath, $destination)) {
                $errors[] = 'Failed to move uploaded file';
            }

            if ($errors === []) {
                $result = [
                    'stored_as' => $safeName,
                    'mime' => $detectedMime,
                    'bytes' => (int) $file['size'],
                ];
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Upload Demo</title></head>
<body>
<h1>Secure Upload Demo</h1>

<?php foreach ($errors as $error): ?>
    <p style="color:#b91c1c;">- <?= e($error) ?></p>
<?php endforeach; ?>

<?php if ($result !== null): ?>
    <p style="color:#166534;">Uploaded: <?= e($result['stored_as']) ?> (<?= e($result['mime']) ?>)</p>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="file" name="asset" required>
    <button type="submit">Upload</button>
</form>
</body>
</html>
```

---

## 2. Stream DB records to downloadable CSV (`php://output` + `fputcsv`)

For large datasets, stream row-by-row to avoid loading full result sets into memory.

### Runnable example (`public/export_tasks_csv.php` style)

```php
<?php
declare(strict_types=1);

$pdo = new PDO('sqlite:' . __DIR__ . '/../database.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$status = trim((string) ($_GET['status'] ?? ''));

$sql = 'SELECT id, title, description, category, quantity, status, due_date, priority, created_at FROM tasks';
$params = [];
if ($status !== '') {
    $sql .= ' WHERE status = :status';
    $params['status'] = $status;
}
$sql .= ' ORDER BY id ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$filename = 'tasks_export_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'wb');
if ($out === false) {
    http_response_code(500);
    echo 'Cannot open output stream';
    exit;
}

fputcsv($out, ['id', 'title', 'description', 'category', 'quantity', 'status', 'due_date', 'priority', 'created_at']);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
        (int) $row['id'],
        (string) $row['title'],
        (string) $row['description'],
        (string) $row['category'],
        (int) $row['quantity'],
        (string) $row['status'],
        (string) ($row['due_date'] ?? ''),
        (int) $row['priority'],
        (string) $row['created_at'],
    ]);
}

fclose($out);
exit;
```

---

## 3. Import + validate CSV into MySQL using PDO prepared statements

This pattern validates each row, imports valid rows in chunks, and collects row-level errors.

### Runnable example (`public/import_tasks_csv.php` style)

```php
<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=modern_php_guide;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$errors = [];
$summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath);
        $allowedMimes = ['text/plain', 'text/csv', 'application/vnd.ms-excel'];
        if (!is_string($mime) || !in_array($mime, $allowedMimes, true)) {
            $errors[] = 'File is not a supported CSV MIME type';
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

                    $rowNumber = 1; // Header row
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

                        $summary = [
                            'imported' => $imported,
                            'errors' => $rowErrors,
                        ];
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $errors[] = 'Import failed. Rolled back transaction: ' . $e->getMessage();
                    }
                }
                fclose($handle);
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>CSV Import Demo</title></head>
<body>
<h1>Import Tasks CSV</h1>

<?php foreach ($errors as $error): ?>
    <p style="color:#b91c1c;">- <?= e($error) ?></p>
<?php endforeach; ?>

<?php if ($summary !== null): ?>
    <p style="color:#166534;">Imported rows: <?= (int) $summary['imported'] ?></p>
    <?php foreach ($summary['errors'] as $rowError): ?>
        <p style="color:#b45309;">- <?= e((string) $rowError) ?></p>
    <?php endforeach; ?>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
    <input type="file" name="csv" accept=".csv,text/csv" required>
    <button type="submit">Import CSV</button>
</form>

<h2>Expected Header</h2>
<pre>title,description,category,quantity,status,due_date,priority</pre>
</body>
</html>
```

