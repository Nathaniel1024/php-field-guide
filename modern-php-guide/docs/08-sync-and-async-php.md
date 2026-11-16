# 08) Sync vs Async in PHP (Practical Guide)

This guide explains how to think about synchronous vs asynchronous execution in modern PHP, with runnable examples you can adapt immediately.

---

## 1) Mental model: why classic PHP is synchronous (and why that can be good)

In traditional PHP-FPM setups:
- One request is handled by one worker process.
- Your code runs top-to-bottom synchronously.
- A blocking operation (DB query, HTTP call, file I/O) pauses that worker until done.

### Why this is a strength
- Simpler reasoning and debugging.
- Strong request isolation.
- Predictable lifecycle: boot → handle request → respond → cleanup.

### When this becomes a bottleneck
- Slow external APIs inside request path.
- Multiple serial network calls (`A` then `B` then `C`).
- Heavy tasks (PDF generation, email batches, webhook fan-out) blocking user response.

Rule of thumb:
- Keep request path fast and deterministic.
- Push slow/non-critical work to background workers.

---

## 2) Concurrent HTTP requests with `curl_multi_*` (vanilla PHP)

You can parallelize multiple outbound HTTP calls without extra frameworks.

### Sequential baseline (`~5s` for 5×1s endpoints)

```php
<?php

declare(strict_types=1);

$urls = [
    'https://httpbin.org/delay/1',
    'https://httpbin.org/delay/1',
    'https://httpbin.org/delay/1',
    'https://httpbin.org/delay/1',
    'https://httpbin.org/delay/1',
];

$start = microtime(true);

foreach ($urls as $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        throw new RuntimeException('cURL error: ' . curl_error($ch));
    }
    curl_close($ch);
}

$elapsed = microtime(true) - $start;
echo "Sequential elapsed: " . number_format($elapsed, 3) . "s" . PHP_EOL;
```

### Concurrent version (`~1s` for same 5 calls)

```php
<?php

declare(strict_types=1);

$urls = [
    'https://httpbin.org/delay/1',
    'https://httpbin.org/delay/1',
    'https://httpbin.org/delay/1',
    'https://httpbin.org/delay/1',
    'https://httpbin.org/delay/1',
];

$start = microtime(true);

$multi = curl_multi_init();
$handles = [];

foreach ($urls as $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_multi_add_handle($multi, $ch);
    $handles[] = $ch;
}

$running = null;
do {
    $status = curl_multi_exec($multi, $running);
    if ($status === CURLM_OK && $running > 0) {
        curl_multi_select($multi, 1.0);
    }
} while ($running > 0 && $status === CURLM_OK);

$httpCodes = [];
foreach ($handles as $ch) {
    $httpCodes[] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $body = curl_multi_getcontent($ch);
    if ($body === false || curl_errno($ch) !== 0) {
        throw new RuntimeException('cURL error: ' . curl_error($ch));
    }

    curl_multi_remove_handle($multi, $ch);
    curl_close($ch);
}

curl_multi_close($multi);

$elapsed = microtime(true) - $start;
echo "Concurrent elapsed: " . number_format($elapsed, 3) . "s" . PHP_EOL;
echo "HTTP codes: " . implode(', ', $httpCodes) . PHP_EOL;
```

Expected behavior:
- Sequential ≈ sum of all latencies.
- Concurrent ≈ max(single-call latency), bounded by network/server limits.

---

## 3) Async background processing for web apps (real-world PHP pattern)

For HTTP requests, the usual async strategy is **not** event-loop magic inside the request.
Instead:
1. Return response fast.
2. Persist work for background processing.
3. Process via worker(s).

### Pattern A: DB-backed job queue + CLI worker

#### A.1 Queue table schema

```sql
CREATE TABLE jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(100) NOT NULL,
    payload_json JSON NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    attempts INT NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reserved_at DATETIME NULL,
    processed_at DATETIME NULL,
    failed_at DATETIME NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_jobs_status_available (status, available_at)
);
```

#### A.2 HTTP endpoint quickly enqueues job (milliseconds)

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$pdo = db();

$jobPayload = [
    'email' => 'customer@example.com',
    'subject' => 'Welcome!',
    'template' => 'welcome_v2',
];

$start = microtime(true);

$stmt = $pdo->prepare(
    'INSERT INTO jobs (type, payload_json, status, available_at)
     VALUES (:type, :payload_json, :status, CURRENT_TIMESTAMP)'
);
$stmt->execute([
    'type' => 'send_email',
    'payload_json' => json_encode($jobPayload, JSON_THROW_ON_ERROR),
    'status' => 'queued',
]);

$elapsedMs = (microtime(true) - $start) * 1000;

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'job_id' => (int) $pdo->lastInsertId(),
    'enqueue_ms' => round($elapsedMs, 2),
], JSON_PRETTY_PRINT);
```

#### A.3 CLI worker claims + processes jobs in background

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

set_time_limit(0);
$pdo = db();

function process_job(array $job): void
{
    $payload = json_decode((string) $job['payload_json'], true, 512, JSON_THROW_ON_ERROR);

    // Example job types
    switch ((string) $job['type']) {
        case 'send_email':
            // simulate slow operation
            usleep(300000);
            break;

        default:
            throw new RuntimeException('Unknown job type: ' . $job['type']);
    }
}

while (true) {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT * FROM jobs
         WHERE status = :queued AND available_at <= CURRENT_TIMESTAMP
         ORDER BY id ASC
         LIMIT 1
         FOR UPDATE'
    );
    $stmt->execute(['queued' => 'queued']);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($job === false) {
        $pdo->commit();
        usleep(500000); // 0.5s idle wait
        continue;
    }

    $reserve = $pdo->prepare(
        'UPDATE jobs
         SET status = :processing, reserved_at = CURRENT_TIMESTAMP, attempts = attempts + 1
         WHERE id = :id'
    );
    $reserve->execute([
        'processing' => 'processing',
        'id' => (int) $job['id'],
    ]);

    $pdo->commit();

    try {
        process_job($job);

        $done = $pdo->prepare(
            'UPDATE jobs SET status = :done, processed_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $done->execute([
            'done' => 'done',
            'id' => (int) $job['id'],
        ]);

        fwrite(STDOUT, "Processed job #{$job['id']}" . PHP_EOL);
    } catch (Throwable $e) {
        $fail = $pdo->prepare(
            'UPDATE jobs
             SET status = :failed, failed_at = CURRENT_TIMESTAMP, error_message = :error
             WHERE id = :id'
        );
        $fail->execute([
            'failed' => 'failed',
            'error' => mb_substr($e->getMessage(), 0, 1000),
            'id' => (int) $job['id'],
        ]);

        fwrite(STDERR, "Failed job #{$job['id']}: {$e->getMessage()}" . PHP_EOL);
    }
}
```

### Pattern B: Non-blocking spawn + `fastcgi_finish_request()`

Use this carefully for short/medium async tails. For critical workloads, queues are safer.

```php
<?php

declare(strict_types=1);

// In a web request handler:
header('Content-Type: application/json; charset=utf-8');

echo json_encode(['ok' => true, 'message' => 'Request accepted'], JSON_PRETTY_PRINT);

// Flush response to user ASAP (FPM only)
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// Option 1: spawn detached worker process (Linux)
$cmd = 'php ' . escapeshellarg(__DIR__ . '/../bin/worker-once.php') . ' > /dev/null 2>&1 &';
exec($cmd);

// Option 2: continue in same process after flush (no spawn)
// do_expensive_non_user_critical_work();
```

Alternative with `proc_open` for richer process control:

```php
<?php

declare(strict_types=1);

$command = ['php', __DIR__ . '/../bin/worker-once.php'];
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', '/tmp/worker-out.log', 'a'],
    2 => ['file', '/tmp/worker-err.log', 'a'],
];

$proc = proc_open($command, $descriptors, $pipes);
if (is_resource($proc)) {
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($proc); // returns quickly when launched this way
}
```

---

## 4) True event-loop async: Fibers + ecosystem runtimes

### Native Fibers mental model (PHP 8.1+)

`Fiber` gives cooperative multitasking primitives:
- Start a unit of work.
- Suspend execution manually.
- Resume later with data.

It is a building block, not a complete runtime by itself.

### Runnable Fiber example

```php
<?php

declare(strict_types=1);

if (!class_exists(Fiber::class)) {
    throw new RuntimeException('Fibers require PHP 8.1+');
}

$fiberA = new Fiber(static function (): string {
    $input = Fiber::suspend('A waiting');
    return 'A done with ' . $input;
});

$fiberB = new Fiber(static function (): string {
    $input = Fiber::suspend('B waiting');
    return 'B done with ' . $input;
});

// Start both
$stateA = $fiberA->start(); // "A waiting"
$stateB = $fiberB->start(); // "B waiting"

echo $stateA . PHP_EOL;
echo $stateB . PHP_EOL;

// Resume both
$fiberA->resume('payload-1');
$fiberB->resume('payload-2');

echo $fiberA->getReturn() . PHP_EOL;
echo $fiberB->getReturn() . PHP_EOL;
```

### When to use ReactPHP, Amp, Swoole/OpenSwoole

- **ReactPHP / Amp**: event-loop apps, many concurrent I/O operations, long-lived workers, websocket servers, high-throughput API clients.
- **Swoole/OpenSwoole**: coroutine-based high-performance servers, long-running workers, built-in async primitives.
- **Classic PHP-FPM + queues**: still best default for most CRUD/business apps.

Pragmatic guidance:
1. Start synchronous.
2. Add `curl_multi` for parallel outbound calls.
3. Move heavy work to queue workers.
4. Reach for async runtimes only when concurrency needs justify operational complexity.

---

## Quick decision checklist

- Need one HTTP response fast while heavy work continues? → enqueue a job.
- Need many outbound API calls in one request? → `curl_multi_*`.
- Need always-on high-concurrency service? → consider ReactPHP/Amp/Swoole.
- Need simplest stable architecture for business apps? → PHP-FPM + queue workers.
