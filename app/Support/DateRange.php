<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Resolves a date-filter preset (or a custom start/end pair) into a concrete
 * [start, end] window, shared by every dashboard's historical-analytics
 * section (Phase 1 analytics blueprint §03).
 *
 * The date filter is query-string based and applies only to *historical*
 * metrics — a DateRange is never consulted for operational/current-state
 * counts (the nurse's shared queue, "active now", etc.), which must render
 * identically no matter what range is selected.
 */
final class DateRange
{
    public const PRESETS = ['today', 'this_week', 'this_month', 'last_30_days', 'this_year', 'custom'];

    /**
     * A custom range wider than this is clamped rather than rejected, so a
     * malformed or deliberately huge query-string value cannot force an
     * unbounded table scan.
     */
    public const MAX_CUSTOM_RANGE_DAYS = 730;

    private function __construct(
        public readonly string $preset,
        public readonly Carbon $start,
        public readonly Carbon $end,
    ) {}

    /**
     * Builds a DateRange from raw, untrusted request input. Any invalid
     * combination (unknown preset, missing/unparseable custom bound,
     * start after end) falls back to $default rather than throwing —
     * malformed query-string values must never break a dashboard.
     */
    public static function fromInput(
        ?string $preset,
        ?string $customStart,
        ?string $customEnd,
        string $default = 'last_30_days',
    ): self {
        $preset = $preset ?? $default;

        if (! in_array($preset, self::PRESETS, true)) {
            $preset = $default;
        }

        if ($preset === 'custom') {
            $custom = self::tryCustomRange($customStart, $customEnd);

            if ($custom !== null) {
                return new self('custom', $custom[0], $custom[1]);
            }

            // Custom input was invalid — fall back to the default preset
            // rather than to an arbitrary custom shape.
            $preset = $default === 'custom' ? 'last_30_days' : $default;
        }

        return new self($preset, ...self::presetBounds($preset));
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private static function tryCustomRange(?string $start, ?string $end): ?array
    {
        if (! $start || ! $end) {
            return null;
        }

        try {
            $startDate = Carbon::createFromFormat('Y-m-d', $start)?->startOfDay();
            $endDate = Carbon::createFromFormat('Y-m-d', $end)?->endOfDay();
        } catch (\Throwable) {
            return null;
        }

        if (! $startDate || ! $endDate || $startDate->greaterThan($endDate)) {
            return null;
        }

        if ($startDate->diffInDays($endDate) > self::MAX_CUSTOM_RANGE_DAYS) {
            $endDate = $startDate->copy()->addDays(self::MAX_CUSTOM_RANGE_DAYS)->endOfDay();
        }

        return [$startDate, $endDate];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function presetBounds(string $preset): array
    {
        $now = Carbon::now();

        return match ($preset) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'this_week' => [$now->copy()->startOfWeek(), $now->copy()->endOfDay()],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'this_year' => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
            // last_30_days is inclusive of today, so it spans 30 calendar
            // days total (today minus 29 days) rather than excluding today.
            default => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    public function cacheKey(): string
    {
        return sprintf('%s_%s_%s', $this->preset, $this->start->format('Ymd'), $this->end->format('Ymd'));
    }
}
