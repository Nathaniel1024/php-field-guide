# 10) Code Review Challenges: Spot the Bug / Security Flaw

Seven realistic review exercises from Beginner → Senior. Each snippet looks plausible but hides a bug or vulnerability.

---

## Challenge 1 (Beginner): Missing `exit` after redirect

```php
<?php

declare(strict_types=1);

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
}

// Sensitive action should only run for authenticated users.
$pdo->prepare('DELETE FROM tasks WHERE id = :id')->execute([
    'id' => (int) ($_GET['id'] ?? 0),
]);

echo 'Task deleted.';
```

<details>
<summary>Reveal Answer & Fix</summary>

**What breaks:** `header('Location: ...')` only sets a response header. Script execution continues unless you terminate it.

**Risk:** Unauthorized users may still execute sensitive logic before browser follows redirect.

**Safe fix:** `exit;` immediately after redirect (or `return` in controller methods).

```php
<?php

declare(strict_types=1);

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$pdo->prepare('DELETE FROM tasks WHERE id = :id')->execute([
    'id' => (int) ($_GET['id'] ?? 0),
]);

echo 'Task deleted.';
```

</details>

---

## Challenge 2 (Beginner+): `in_array()` loose comparison bypass

```php
<?php

declare(strict_types=1);

$allowedRoles = ['admin', 'manager', 'editor'];
$incomingRole = $_GET['role'] ?? '';

if (in_array($incomingRole, $allowedRoles)) {
    echo 'Role allowed';
}
```

<details>
<summary>Reveal Answer & Fix</summary>

**What breaks:** `in_array()` defaults to loose comparison. Type juggling can produce unexpected matches in other allowlists (especially numeric/string combinations).

**Risk:** Authorization/filter bypass through coercion edge cases.

**Safe fix:** Always pass strict mode `true` for allowlists.

```php
<?php

declare(strict_types=1);

$allowedRoles = ['admin', 'manager', 'editor'];
$incomingRole = (string) ($_GET['role'] ?? '');

if (in_array($incomingRole, $allowedRoles, true)) {
    echo 'Role allowed';
}
```

</details>

---

## Challenge 3 (Intermediate): Currency math with floats

```php
<?php

declare(strict_types=1);

$price = 19.99;
$tax = 0.07;
$total = $price + ($price * $tax);

echo number_format($total, 2); // looks fine... usually
```

<details>
<summary>Reveal Answer & Fix</summary>

**What breaks:** Binary floating-point cannot exactly represent many decimal values (`0.1`, `19.99`, etc.).

**Risk:** Rounding drift in invoices, reconciliation mismatches, off-by-one cent bugs.

**Safe fix:** Store and compute money in integer cents.

```php
<?php

declare(strict_types=1);

$priceCents = 1999;
$taxBps = 700; // 7.00% in basis points
$taxCents = intdiv($priceCents * $taxBps, 10000);
$totalCents = $priceCents + $taxCents;

echo number_format($totalCents / 100, 2, '.', ''); // 21.38
```

</details>

---

## Challenge 4 (Intermediate+): `foreach` reference corruption

```php
<?php

declare(strict_types=1);

$items = [1, 2, 3];

foreach ($items as &$value) {
    $value *= 10;
}

foreach ($items as $value) {
    // ...
}

print_r($items);
```

<details>
<summary>Reveal Answer & Fix</summary>

**What breaks:** After `foreach ($items as &$value)`, `$value` still references the last element.

**Risk:** Later assignment/iteration can accidentally mutate the final element.

**Safe fix:** `unset($value);` immediately after reference loop.

```php
<?php

declare(strict_types=1);

$items = [1, 2, 3];

foreach ($items as &$value) {
    $value *= 10;
}
unset($value);

foreach ($items as $value) {
    // Safe iteration
}

print_r($items);
```

</details>

---

## Challenge 5 (Advanced): SQL injection in dynamic `ORDER BY`

```php
<?php

declare(strict_types=1);

$sort = $_GET['sort'] ?? 'created_at';
$dir = $_GET['dir'] ?? 'DESC';

$sql = "SELECT * FROM tasks ORDER BY {$sort} {$dir}";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

<details>
<summary>Reveal Answer & Fix</summary>

**What breaks:** `ORDER BY` identifiers and direction tokens are interpolated directly from user input.

**Risk:** SQL injection via crafted `sort`/`dir` parameters (placeholders cannot bind identifiers).

**Safe fix:** strict allowlist mapping for columns and directions.

```php
<?php

declare(strict_types=1);

$columnInput = (string) ($_GET['sort'] ?? 'created_at');
$dirInput = strtolower((string) ($_GET['dir'] ?? 'desc'));

$allowedColumns = [
    'id' => 'id',
    'title' => 'title',
    'priority' => 'priority',
    'created_at' => 'created_at',
];
$allowedDirs = ['asc' => 'ASC', 'desc' => 'DESC'];

$column = $allowedColumns[$columnInput] ?? 'created_at';
$direction = $allowedDirs[$dirInput] ?? 'DESC';

$sql = "SELECT * FROM tasks ORDER BY {$column} {$direction}, id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

</details>

---

## Challenge 6 (Advanced+): API token check vulnerable to timing attacks

```php
<?php

declare(strict_types=1);

$expectedToken = 'prod_token_123456789';
$receivedToken = $_SERVER['HTTP_X_API_TOKEN'] ?? '';

if ($receivedToken !== $expectedToken) {
    http_response_code(401);
    exit('Unauthorized');
}
```

<details>
<summary>Reveal Answer & Fix</summary>

**What breaks:** Direct string comparison can leak timing differences based on prefix matches.

**Risk:** Token brute-force optimization with high-precision timing measurements.

**Safe fix:** Use `hash_equals()` for timing-safe comparison.

```php
<?php

declare(strict_types=1);

$expectedToken = 'prod_token_123456789';
$receivedToken = (string) ($_SERVER['HTTP_X_API_TOKEN'] ?? '');

if (!hash_equals($expectedToken, $receivedToken)) {
    http_response_code(401);
    exit('Unauthorized');
}
```

</details>

---

## Challenge 7 (Senior): Timezone end-date off-by-one bug

```php
<?php

declare(strict_types=1);

$dateFrom = $_GET['from'] ?? '2026-11-01';
$dateTo = $_GET['to'] ?? '2026-11-16';

$stmt = $pdo->prepare(
    'SELECT * FROM tasks
     WHERE created_at >= :from
       AND created_at <= :to'
);
$stmt->execute([
    'from' => $dateFrom . ' 00:00:00',
    'to' => $dateTo . ' 23:59:59',
]);
```

<details>
<summary>Reveal Answer & Fix</summary>

**What breaks:** This assumes app timezone equals DB timezone and that all timestamps align perfectly. In UTC storage with local user dates, records near boundaries can be excluded.

**Risk:** Missing rows on end date, inconsistent reports across timezones.

**Safe fix:** Convert local dates to UTC and use half-open intervals: `>= start_utc` and `< next_day_start_utc`.

```php
<?php

declare(strict_types=1);

$userTz = new DateTimeZone('Asia/Shanghai');
$storageTz = new DateTimeZone('UTC');

$dateFrom = (string) ($_GET['from'] ?? '2026-11-01');
$dateTo = (string) ($_GET['to'] ?? '2026-11-16');

$startLocal = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateFrom . ' 00:00:00', $userTz);
$endLocalExclusive = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateTo . ' 00:00:00', $userTz)?->modify('+1 day');

if (!$startLocal || !$endLocalExclusive) {
    throw new RuntimeException('Invalid date range input');
}

$startUtc = $startLocal->setTimezone($storageTz)->format('Y-m-d H:i:s');
$endUtcExclusive = $endLocalExclusive->setTimezone($storageTz)->format('Y-m-d H:i:s');

$stmt = $pdo->prepare(
    'SELECT * FROM tasks
     WHERE created_at >= :start_utc
       AND created_at < :end_utc_exclusive'
);
$stmt->execute([
    'start_utc' => $startUtc,
    'end_utc_exclusive' => $endUtcExclusive,
]);
```

</details>

---

## Suggested usage in team reviews

- Ask reviewer to identify issue before opening the answer.
- Require explanation of exploit/failure mode, not only syntax fixes.
- Discuss severity and production blast radius.
- Prefer prevention patterns (allowlists, strict comparisons, half-open date ranges, integer money).
