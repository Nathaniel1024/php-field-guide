<?php

declare(strict_types=1);

/**
 * Playground 02: Dates + Money
 * Run: php playground/02-dates-and-money.php
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

/**
 * Format integer cents safely.
 */
function format_money_from_cents(int $cents, string $currency = '$'): string
{
    $sign = $cents < 0 ? '-' : '';
    $absolute = abs($cents);
    $major = intdiv($absolute, 100);
    $minor = $absolute % 100;

    return sprintf('%s%s%s.%02d', $sign, $currency, number_format((float) $major, 0, '.', ','), $minor);
}

try {
    heading('DateTimeImmutable math');

    $start = new DateTimeImmutable('2026-11-16 09:00:00', new DateTimeZone('Asia/Shanghai'));
    $due = $start->modify('+10 days')->modify('+2 hours');

    assert($start->format('Y-m-d H:i:s') === '2026-11-16 09:00:00');
    assert($due->format('Y-m-d H:i:s') === '2026-11-26 11:00:00');
    ok('Immutable date arithmetic works without mutating original');

    heading('Timezone conversion');

    $utc = $start->setTimezone(new DateTimeZone('UTC'));
    $newYork = $start->setTimezone(new DateTimeZone('America/New_York'));

    assert($utc->format('Y-m-d H:i') === '2026-11-16 01:00');
    ok('Shanghai -> UTC: ' . $utc->format(DateTimeInterface::ATOM));
    ok('Shanghai -> New York: ' . $newYork->format(DateTimeInterface::ATOM));

    heading('DatePeriod ranges');

    $rangeStart = new DateTimeImmutable('2026-11-01');
    $rangeEndExclusive = new DateTimeImmutable('2026-11-06');
    $period = new DatePeriod($rangeStart, new DateInterval('P1D'), $rangeEndExclusive);

    $days = [];
    foreach ($period as $day) {
        $days[] = $day->format('Y-m-d');
    }

    assert($days === ['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05']);
    ok('DatePeriod generated: ' . implode(', ', $days));

    heading('Money: why floats break');

    $floatSum = 0.1 + 0.2;
    echo '[INFO] 0.1 + 0.2 using float = ' . sprintf('%.17f', $floatSum) . PHP_EOL;
    assert($floatSum !== 0.3);
    ok('Float precision issue demonstrated');

    $lineItemsInCents = [1999, 250, 349, 799];
    $totalCents = array_sum($lineItemsInCents);
    assert($totalCents === 3397);

    $formatted = format_money_from_cents($totalCents, '$');
    assert($formatted === '$33.97');
    ok('Integer-cent money total: ' . $formatted);

    $refund = -1250;
    assert(format_money_from_cents($refund, '$') === '-$12.50');
    ok('Negative amounts handled: ' . format_money_from_cents($refund, '$'));

    echo PHP_EOL . 'All date/money playground checks passed.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
