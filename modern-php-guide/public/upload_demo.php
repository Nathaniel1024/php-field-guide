<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';

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

        $maxBytes = 2 * 1024 * 1024;
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
            $resolvedDestinationDir = realpath(dirname($destination));

            if ($realUploadRoot === false || $resolvedDestinationDir === false || strpos($resolvedDestinationDir, $realUploadRoot) !== 0) {
                $errors[] = 'Directory traversal detected';
            } elseif (!move_uploaded_file($tmpPath, $destination)) {
                $errors[] = 'Failed to move uploaded file';
            } else {
                $result = [
                    'stored_as' => $safeName,
                    'mime' => $detectedMime,
                    'bytes' => (int) $file['size'],
                ];
            }
        }
    }
}

$pageTitle = 'Upload Demo';
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <h1>Secure Upload Demo</h1>
    <p class="muted">Allowed: JPG, PNG, WEBP, PDF up to 2MB.</p>

    <?php foreach ($errors as $error): ?>
        <p class="notice err"><?= e($error) ?></p>
    <?php endforeach; ?>

    <?php if ($result !== null): ?>
        <p class="notice ok">Uploaded: <?= e($result['stored_as']) ?> (<?= e($result['mime']) ?>, <?= (int) $result['bytes'] ?> bytes)</p>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="file" name="asset" required>
        <button type="submit">Upload</button>
    </form>
</div>
</div>
</body>
</html>
