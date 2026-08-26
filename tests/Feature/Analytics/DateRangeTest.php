<?php

use App\Support\DateRange;
use Illuminate\Support\Carbon;

// A fixed "now" makes every boundary assertion deterministic.
beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 8, 25, 14, 30, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('resolves today to the current calendar day only', function () {
    $range = DateRange::fromInput('today', null, null);

    expect($range->start->toDateString())->toBe('2026-08-25')
        ->and($range->end->toDateString())->toBe('2026-08-25')
        ->and($range->start->format('H:i:s'))->toBe('00:00:00')
        ->and($range->end->format('H:i:s'))->toBe('23:59:59');
});

it('resolves last_30_days to a 30-day inclusive window ending today', function () {
    $range = DateRange::fromInput('last_30_days', null, null);

    // Inclusive of today: start is 29 days back, not 30.
    expect($range->start->toDateString())->toBe('2026-07-27')
        ->and($range->end->toDateString())->toBe('2026-08-25');
});

it('resolves this_month to the first of the month through today', function () {
    $range = DateRange::fromInput('this_month', null, null);

    expect($range->start->toDateString())->toBe('2026-08-01')
        ->and($range->end->toDateString())->toBe('2026-08-25');
});

it('falls back to the given default when the preset is unrecognized', function () {
    $range = DateRange::fromInput('not_a_real_preset', null, null, 'this_month');

    expect($range->preset)->toBe('this_month');
});

it('accepts a valid custom range', function () {
    $range = DateRange::fromInput('custom', '2026-08-01', '2026-08-10');

    expect($range->preset)->toBe('custom')
        ->and($range->start->toDateString())->toBe('2026-08-01')
        ->and($range->end->toDateString())->toBe('2026-08-10');
});

it('falls back to the default when a custom range has start after end', function () {
    $range = DateRange::fromInput('custom', '2026-08-10', '2026-08-01', 'this_month');

    expect($range->preset)->toBe('this_month');
});

it('falls back to the default when a custom range is missing a bound', function () {
    $range = DateRange::fromInput('custom', '2026-08-10', null, 'this_month');

    expect($range->preset)->toBe('this_month');
});

it('falls back to the default when a custom date string cannot be parsed', function () {
    $range = DateRange::fromInput('custom', 'not-a-date', '2026-08-10', 'this_month');

    expect($range->preset)->toBe('this_month');
});

it('clamps a custom range wider than the maximum span instead of erroring', function () {
    $range = DateRange::fromInput('custom', '2000-01-01', '2026-08-25');

    // Compare calendar days, not sub-second precision — the clamp's intent
    // is "don't scan more than N days of data", not an exact microsecond span.
    $spanDays = $range->start->copy()->startOfDay()->diffInDays($range->end->copy()->startOfDay());

    expect($spanDays)->toBeLessThanOrEqual(DateRange::MAX_CUSTOM_RANGE_DAYS);
});

it('produces a stable cache key that changes when the range changes', function () {
    $a = DateRange::fromInput('this_month', null, null);
    $b = DateRange::fromInput('last_30_days', null, null);

    expect($a->cacheKey())->not->toBe($b->cacheKey());
});
