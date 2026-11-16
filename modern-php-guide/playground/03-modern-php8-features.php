<?php

declare(strict_types=1);

/**
 * Playground 03: Modern PHP 8 features
 * Run: php playground/03-modern-php8-features.php
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

enum TaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Done = 'done';
}

readonly class UserProfile
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $bio = null,
    ) {
    }
}

final class Reporter
{
    public function __construct(public ?UserProfile $profile)
    {
    }

    public function profileName(): ?string
    {
        return $this->profile?->name;
    }
}

function label_for_status(TaskStatus $status): string
{
    return match ($status) {
        TaskStatus::Pending => 'Pending',
        TaskStatus::InProgress => 'In Progress',
        TaskStatus::Done => 'Done',
    };
}

function build_line(string $title, int $qty, bool $urgent = false): string
{
    $tag = $urgent ? 'URGENT' : 'normal';
    return "{$title} x{$qty} [{$tag}]";
}

/** @return Generator<int, int, mixed, void> */
function quantity_stream(int $max): Generator
{
    for ($index = 1; $index <= $max; $index++) {
        yield $index => $index * 10;
    }
}

try {
    heading('Enums + match');

    $status = TaskStatus::InProgress;
    assert($status->value === 'in_progress');
    assert(label_for_status($status) === 'In Progress');
    ok('Enum value and match label resolved');

    heading('Nullsafe operator ?->');

    $withProfile = new Reporter(new UserProfile(id: 7, name: 'Nina', bio: 'Maintains deploy scripts'));
    $withoutProfile = new Reporter(null);

    assert($withProfile->profileName() === 'Nina');
    assert($withoutProfile->profileName() === null);
    ok('Nullsafe access returns value or null safely');

    heading('Named arguments');

    $line = build_line(title: 'Monitor batteries', qty: 6, urgent: true);
    assert($line === 'Monitor batteries x6 [URGENT]');
    ok('Named arguments call result: ' . $line);

    heading('Readonly class');

    $profile = new UserProfile(id: 9, name: 'Asha');
    assert($profile->name === 'Asha');
    ok('Readonly object created');

    try {
        $profile->name = 'Changed';
        throw new RuntimeException('Readonly property mutation should have failed');
    } catch (Error $error) {
        ok('Readonly mutation blocked: ' . $error->getMessage());
    }

    heading('Generators');

    $generated = [];
    foreach (quantity_stream(4) as $key => $value) {
        $generated[$key] = $value;
    }

    assert($generated === [1 => 10, 2 => 20, 3 => 30, 4 => 40]);
    ok('Generator streamed values: ' . json_encode($generated, JSON_THROW_ON_ERROR));

    heading('Fibers (PHP 8.1+)');

    if (!class_exists(Fiber::class)) {
        throw new RuntimeException('Fiber class not available in this PHP build');
    }

    $fiber = new Fiber(static function (): string {
        $incoming = Fiber::suspend('fiber-paused');
        return 'fiber-resumed-with:' . $incoming;
    });

    $first = $fiber->start();
    assert($first === 'fiber-paused');

    $final = $fiber->resume('payload');
    assert($final === null);
    assert($fiber->isTerminated());
    assert($fiber->getReturn() === 'fiber-resumed-with:payload');
    ok('Fiber start/suspend/resume flow works');

    echo PHP_EOL . 'All modern PHP 8 playground checks passed.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
