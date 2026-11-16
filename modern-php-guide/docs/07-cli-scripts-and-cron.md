# 07) CLI Scripts and Cron Jobs in Vanilla PHP 8.2+

This guide covers how to build robust, production-grade CLI scripts and background workers without frameworks.

---

## 1) CLI anatomy and safety

### Core rules
- Start executable scripts with a shebang: `#!/usr/bin/env php`
- Block browser access (CLI-only): `PHP_SAPI !== 'cli'`
- Disable execution timeout for long jobs: `set_time_limit(0)`
- Return proper exit codes:
  - `exit(0)` success
  - `exit(1)` failure

### Copy-paste template (`bin/task-runner.php`)

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI only." . PHP_EOL;
    exit(1);
}

set_time_limit(0);
ini_set('display_errors', '1');
error_reporting(E_ALL);

function log_line(string $message): void
{
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    fwrite(STDOUT, "[{$now}] {$message}" . PHP_EOL);
}

try {
    log_line('Job started');

    // ... do work ...

    log_line('Job completed successfully');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
```

Make executable:

```bash
chmod +x bin/task-runner.php
./bin/task-runner.php
```

---

## 2) Arguments + interactive prompts (`$argv`, `getopt()`, `readline()`/`STDIN`)

### Example target command

```bash
php bin/sync.php --dry-run --limit=100 --source=tasks
```

### Full script example (`bin/sync.php`)

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only." . PHP_EOL);
    exit(1);
}

set_time_limit(0);

/**
 * @return array{dry_run:bool,limit:int,source:string,yes:bool}
 */
function parse_cli_options(): array
{
    // Long options: --dry-run, --limit=100, --source=tasks, --yes
    $opts = getopt('', ['dry-run', 'limit::', 'source::', 'yes']);

    $dryRun = array_key_exists('dry-run', $opts);
    $yes = array_key_exists('yes', $opts);

    $limitRaw = $opts['limit'] ?? '100';
    $limit = filter_var($limitRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]) ?: 100;

    $sourceRaw = (string) ($opts['source'] ?? 'tasks');
    $allowedSources = ['tasks', 'inventory', 'all'];
    $source = in_array($sourceRaw, $allowedSources, true) ? $sourceRaw : 'tasks';

    return [
        'dry_run' => $dryRun,
        'limit' => $limit,
        'source' => $source,
        'yes' => $yes,
    ];
}

function prompt_confirm(string $question): bool
{
    if (function_exists('readline')) {
        $answer = readline($question . ' [y/N]: ');
    } else {
        fwrite(STDOUT, $question . ' [y/N]: ');
        $answer = fgets(STDIN);
    }

    $normalized = strtolower(trim((string) $answer));
    return in_array($normalized, ['y', 'yes'], true);
}

$options = parse_cli_options();

fwrite(STDOUT, 'Parsed options: ' . json_encode($options, JSON_THROW_ON_ERROR) . PHP_EOL);

if (!$options['yes']) {
    $confirmed = prompt_confirm("Proceed syncing source '{$options['source']}' with limit {$options['limit']}?");
    if (!$confirmed) {
        fwrite(STDOUT, "Cancelled by user." . PHP_EOL);
        exit(0);
    }
}

try {
    // Simulated work
    for ($i = 1; $i <= min(5, $options['limit']); $i++) {
        fwrite(STDOUT, "Processing item {$i}" . ($options['dry_run'] ? ' [DRY RUN]' : '') . PHP_EOL);
        usleep(100000);
    }

    fwrite(STDOUT, "Sync complete." . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Sync failed: {$e->getMessage()}" . PHP_EOL);
    exit(1);
}
```

---

## 3) Prevent overlapping cron jobs with `flock()`

If cron runs every minute and one run takes 90 seconds, overlapping runs can corrupt state.

### Pattern (`bin/minutely-worker.php`)

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

set_time_limit(0);

$lockFile = '/tmp/modern-php-guide-minutely-worker.lock';
$fp = fopen($lockFile, 'c');
if ($fp === false) {
    fwrite(STDERR, "Cannot open lock file: {$lockFile}" . PHP_EOL);
    exit(1);
}

if (!flock($fp, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "Another instance is running. Exiting." . PHP_EOL);
    fclose($fp);
    exit(0);
}

ftruncate($fp, 0);
fwrite($fp, (string) getmypid());
fflush($fp);

try {
    fwrite(STDOUT, "Worker acquired lock. Running..." . PHP_EOL);

    // Simulated work
    sleep(10);

    fwrite(STDOUT, "Worker finished." . PHP_EOL);
    flock($fp, LOCK_UN);
    fclose($fp);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Worker error: {$e->getMessage()}" . PHP_EOL);
    flock($fp, LOCK_UN);
    fclose($fp);
    exit(1);
}
```

### Cron example

```cron
* * * * * /usr/bin/php /var/www/myapp/bin/minutely-worker.php >> /var/log/myapp/minutely-worker.log 2>&1
```

---

## 4) Memory-safe processing for 100,000+ rows + terminal UX

Goals:
- Avoid loading huge datasets into memory at once.
- Process in chunks (pagination/cursor batching).
- Manually trigger GC in long loops.
- Add readable terminal UX (colors + progress bar).

### Complete example (`bin/process-large-dataset.php`)

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

set_time_limit(0);

$dsn = 'sqlite:' . dirname(__DIR__) . '/database.sqlite';
$pdo = new PDO($dsn, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

const ANSI_RESET = "\033[0m";
const ANSI_RED = "\033[31m";
const ANSI_GREEN = "\033[32m";
const ANSI_YELLOW = "\033[33m";

function color(string $text, string $ansi): string
{
    return $ansi . $text . ANSI_RESET;
}

function progress_bar(int $current, int $total, int $width = 40): void
{
    $ratio = $total > 0 ? $current / $total : 0;
    $filled = (int) round($ratio * $width);
    $bar = str_repeat('#', $filled) . str_repeat('-', max(0, $width - $filled));
    $percent = number_format($ratio * 100, 1);

    fwrite(STDOUT, "\r[{$bar}] {$percent}% ({$current}/{$total})");

    if ($current >= $total) {
        fwrite(STDOUT, PHP_EOL);
    }
}

try {
    $countStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM tasks');
    $countStmt->execute();
    $total = (int) ($countStmt->fetch()['total'] ?? 0);

    fwrite(STDOUT, color("Starting batch process for {$total} rows", ANSI_YELLOW) . PHP_EOL);

    $batchSize = 500;
    $lastId = 0;
    $processed = 0;

    while (true) {
        $stmt = $pdo->prepare(
            'SELECT id, title, status, priority
             FROM tasks
             WHERE id > :last_id
             ORDER BY id ASC
             LIMIT :batch_size'
        );
        $stmt->bindValue(':last_id', $lastId, PDO::PARAM_INT);
        $stmt->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        if ($rows === []) {
            break;
        }

        foreach ($rows as $row) {
            // Simulate work per row
            $processed++;
            $lastId = (int) $row['id'];

            // Example transformation / outbound sync / indexing / etc.
            $normalizedTitle = mb_strtolower((string) $row['title']);
            if ($normalizedTitle === '') {
                fwrite(STDERR, color("[WARN] Empty title for task {$lastId}", ANSI_RED) . PHP_EOL);
            }
        }

        progress_bar($processed, max($total, 1));

        unset($rows, $stmt);
        gc_collect_cycles();
    }

    fwrite(STDOUT, color("Done. Processed {$processed} rows successfully.", ANSI_GREEN) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, color('[ERROR] ' . $e->getMessage(), ANSI_RED) . PHP_EOL);
    exit(1);
}
```

---

## Extra production tips

- Use separate DB credentials for background workers (least privilege).
- Emit structured logs for cron jobs (JSON or key-value lines).
- Add retry logic with backoff for external APIs.
- Track checkpoints (`last_processed_id`, timestamps) for resumability.
- Consider signal handling (`pcntl_signal`) for graceful shutdown in long daemons.

---

## Quick run commands

```bash
php bin/sync.php --dry-run --limit=100 --source=tasks
php bin/minutely-worker.php
php bin/process-large-dataset.php
```

For cron, always use full absolute paths and log both stdout + stderr.
