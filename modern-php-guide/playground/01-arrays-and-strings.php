<?php

declare(strict_types=1);

/**
 * Playground 01: Arrays + Strings
 * Run: php playground/01-arrays-and-strings.php
 */

assert_options(ASSERT_ACTIVE, 1);
assert_options(ASSERT_EXCEPTION, 1);

function heading(string $title): void
{
    echo PHP_EOL . '=== ' . $title . ' ===' . PHP_EOL;
}

function ok(string $message): void
{
    echo '[OK] ' . $message . PHP_EOL;
}

try {
    heading('Destructuring');

    $task = ['id' => 101, 'title' => 'Ship release notes', 'status' => 'pending'];
    ['id' => $id, 'title' => $title, 'status' => $status] = $task;

    assert($id === 101);
    assert($title === 'Ship release notes');
    assert($status === 'pending');
    ok("Destructured array: #{$id} {$title} ({$status})");

    [$first, $second] = ['alpha', 'beta', 'gamma'];
    assert($first === 'alpha' && $second === 'beta');
    ok('Indexed destructuring works');

    heading('array_map / array_filter / array_reduce');

    $quantities = [3, 0, 8, 2, 0, 5];

    $doubled = array_map(static fn (int $value): int => $value * 2, $quantities);
    assert($doubled === [6, 0, 16, 4, 0, 10]);
    ok('array_map doubled quantities: ' . implode(', ', $doubled));

    $nonZero = array_values(array_filter($quantities, static fn (int $value): bool => $value > 0));
    assert($nonZero === [3, 8, 2, 5]);
    ok('array_filter removed zeros: ' . implode(', ', $nonZero));

    $sum = array_reduce($quantities, static fn (int $carry, int $value): int => $carry + $value, 0);
    assert($sum === 18);
    ok("array_reduce total = {$sum}");

    heading('array_column');

    $rows = [
        ['id' => 1, 'title' => 'Refill paper', 'priority' => 4],
        ['id' => 2, 'title' => 'Rotate backups', 'priority' => 5],
        ['id' => 3, 'title' => 'Label cables', 'priority' => 2],
    ];

    $titles = array_column($rows, 'title');
    assert($titles === ['Refill paper', 'Rotate backups', 'Label cables']);
    ok('Extracted titles: ' . implode(' | ', $titles));

    $byId = array_column($rows, 'title', 'id');
    assert($byId[2] === 'Rotate backups');
    ok('Indexed by id using array_column');

    heading('String helpers');

    $needleText = 'Inventory task: Count HDMI cables in rack 3';
    assert(str_contains($needleText, 'HDMI'));
    assert(str_starts_with($needleText, 'Inventory task:'));
    ok('str_contains + str_starts_with passed');

    $filename = 'report_2026-11-16.csv';
    assert(str_ends_with($filename, '.csv'));
    ok('str_ends_with identified CSV file');

    heading('Regex with preg_match_all');

    $log = 'IDs touched: #12, #77, #503 and ticket TKT-9988.';
    $count = preg_match_all('/#(\d+)/', $log, $matches);

    if ($count === false) {
        throw new RuntimeException('preg_match_all failed');
    }

    $ids = array_map('intval', $matches[1]);
    assert($count === 3);
    assert($ids === [12, 77, 503]);
    ok('Extracted IDs from text: ' . implode(', ', $ids));

    echo PHP_EOL . 'All array/string playground checks passed.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
