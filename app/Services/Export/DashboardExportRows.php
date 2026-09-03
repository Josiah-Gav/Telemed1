<?php

namespace App\Services\Export;

/**
 * Flattens the analytics array already returned by
 * DashboardAnalyticsService::forNurse/forPhysician/forAdmin into export
 * "sections" — a title, an optional description, an optional header row,
 * and data rows. Nothing here queries the database, resolves a DateRange,
 * performs authorization, or computes a metric that DashboardAnalyticsService
 * did not already compute. It is a pure mapper: same input analytics array
 * in, same section structure out, every time.
 *
 * The section structure (title/description/headers/rows) is deliberately
 * format-agnostic — CSV export flattens it further via toCsvRows() below,
 * and the future PDF renderer (Phase 4) is expected to consume forRole()'s
 * output directly, so both formats always agree because they share one
 * mapping of "what the analytics array means," not two.
 */
class DashboardExportRows
{
    /**
     * @var array<string, array{0: string, 1: string}> chart key => [section title, first-column header]
     */
    private const CHART_LABELS = [
        'volume_over_time' => ['Requests Over Time', 'Date'],
        'status_distribution' => ['Status Distribution', 'Status'],
        'priority_distribution' => ['Priority Distribution', 'Priority'],
        'initial_vs_follow_up' => ['Initial vs Follow-up', 'Type'],
    ];

    /**
     * Keys whose value is a nullable rate/percentage — must render as an
     * em dash when null, never as 0, matching the dashboard UI's rule that
     * "no concluded cases yet" is not the same claim as "0% success".
     */
    private const NULLABLE_PERCENTAGE_KEYS = ['rate', 'percentage'];

    /**
     * @param  'nurse'|'physician'|'admin'  $role
     * @param  array  $analytics  The exact return value of
     *                            DashboardAnalyticsService::forNurse/forPhysician/forAdmin.
     * @param  string  $generatedBy  The exporting user's canonical display name
     *                               (trim(first_name.' '.last_name), the same
     *                               convention used throughout this app — see
     *                               ConsultationHistoryRows::relationName()).
     *                               Supplied by the controller from the
     *                               authenticated user; this class never
     *                               resolves it itself. Defaults to '' only
     *                               so pre-existing direct unit callers that
     *                               don't care about this field keep compiling.
     * @param  string  $timelineLabel  Human-readable label for the resolved
     *                                 date range (e.g. "Last 30 Days", or a
     *                                 formatted "Aug 01 2026 - Aug 27 2026"
     *                                 for a custom range) — see
     *                                 timelineLabel() below. Used only in the
     *                                 title; the Range/Range Start/Range End
     *                                 rows are untouched and still carry the
     *                                 raw preset/dates.
     * @return list<array{title: string, description: ?string, headers: ?list<string>, rows: list<list<string>>}>
     */
    public static function forRole(string $role, array $analytics, \DateTimeInterface $generatedAt, string $generatedBy = '', string $timelineLabel = ''): array
    {
        $filters = $analytics['filters'] ?? ['preset' => '', 'start' => '', 'end' => ''];

        $sections = [];
        $sections[] = self::metaSection($role, $filters, $generatedAt, $generatedBy, $timelineLabel);
        $sections[] = self::operationalSection($analytics['operational'] ?? []);
        $sections[] = self::periodSection($analytics['period'] ?? [], $filters);

        foreach ($analytics['charts'] ?? [] as $chartKey => $chart) {
            $sections[] = self::chartSection($chartKey, $chart);
        }

        if (array_key_exists('symptoms', $analytics)) {
            array_push($sections, ...self::symptomSections($analytics['symptoms']));
        }

        return $sections;
    }

    /**
     * Flattens forRole()'s sections into plain CSV rows: a title row, an
     * optional description row, an optional header row, then the section's
     * data rows, with a blank row separating one section from the next.
     * CSV-specific — the PDF renderer is expected to consume forRole()'s
     * structured sections directly instead.
     *
     * @param  list<array{title: string, description: ?string, headers: ?list<string>, rows: list<list<string>>}>  $sections
     * @return list<list<string>>
     */
    public static function toCsvRows(array $sections): array
    {
        $rows = [];

        foreach ($sections as $index => $section) {
            if ($index > 0) {
                $rows[] = [];
            }

            $rows[] = [$section['title']];

            if (! empty($section['description'])) {
                $rows[] = [$section['description']];
            }

            if (! empty($section['headers'])) {
                $rows[] = $section['headers'];
            }

            foreach ($section['rows'] as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array{preset: string, start: string, end: string}  $filters
     */
    private static function metaSection(string $role, array $filters, \DateTimeInterface $generatedAt, string $generatedBy, string $timelineLabel): array
    {
        return [
            'title' => trim(sprintf('%s %s %s Report', ucfirst($role), $generatedBy, $timelineLabel)),
            'description' => null,
            'headers' => null,
            'rows' => [
                ['Role', ucfirst($role)],
                ['Generated By', $generatedBy],
                ['Range', $filters['preset'] ?? ''],
                ['Range Start', $filters['start'] ?? ''],
                ['Range End', $filters['end'] ?? ''],
                ['Generated', $generatedAt->format('Y-m-d H:i')],
            ],
        ];
    }

    private static function operationalSection(array $operational): array
    {
        return [
            'title' => 'Operational — Current State (Not Date-Filtered)',
            'description' => 'These metrics reflect the current, live state of the system and are not limited by the selected date range above.',
            'headers' => ['Metric', 'Value'],
            'rows' => self::flattenMetrics($operational),
        ];
    }

    /**
     * @param  array{preset: string, start: string, end: string}  $filters
     */
    private static function periodSection(array $period, array $filters): array
    {
        $start = $filters['start'] ?? '';
        $end = $filters['end'] ?? '';

        return [
            'title' => "Period — {$start} to {$end}",
            'description' => 'These metrics are limited to the selected date range above.',
            'headers' => ['Metric', 'Value'],
            'rows' => self::flattenMetrics($period),
        ];
    }

    /**
     * @param  array{labels: list<string>, datasets: list<array{label?: string, data: list<int>}>}  $chart
     */
    private static function chartSection(string $chartKey, array $chart): array
    {
        [$title, $firstColumnHeader] = self::CHART_LABELS[$chartKey] ?? [self::humanize($chartKey), 'Label'];

        $labels = $chart['labels'] ?? [];
        $datasets = $chart['datasets'] ?? [];

        $headers = [$firstColumnHeader];
        foreach ($datasets as $dataset) {
            $headers[] = $dataset['label'] ?? 'Value';
        }

        $rows = [];
        foreach ($labels as $i => $label) {
            $row = [(string) $label];
            foreach ($datasets as $dataset) {
                $row[] = (string) ($dataset['data'][$i] ?? 0);
            }
            $rows[] = $row;
        }

        return [
            'title' => $title,
            'description' => null,
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    /**
     * Admin and physician only (whichever role's $analytics carries a
     * 'symptoms' key — see forRole() above). Mirrors SymptomAnalytics::
     * summarize()'s exact output —
     * including suppressed_terms_count, so the k=3 privacy suppression
     * stays visible in the export rather than silently shrinking a list.
     */
    private static function symptomSections(array $symptoms): array
    {
        $sections = [];

        $sections[] = [
            'title' => 'Symptom Analytics — Summary',
            'description' => null,
            'headers' => ['Metric', 'Value'],
            'rows' => [
                ['Valid Requests', (string) ($symptoms['valid_requests'] ?? 0)],
                ['Malformed Requests', (string) ($symptoms['malformed_requests'] ?? 0)],
            ],
        ];

        $sections[] = [
            'title' => 'Symptom Analytics — Standardized Symptoms',
            'description' => null,
            'headers' => ['Symptom', 'Count'],
            'rows' => array_map(
                fn (array $entry) => [(string) $entry['name'], (string) $entry['count']],
                $symptoms['standardized'] ?? [],
            ),
        ];

        $custom = $symptoms['custom'] ?? [];
        $sections[] = [
            'title' => 'Symptom Analytics — Custom Symptom Summary',
            'description' => null,
            'headers' => ['Metric', 'Value'],
            'rows' => [
                ['Requests With Custom', (string) ($custom['requests_with_custom'] ?? 0)],
                ['Percentage', self::formatValue('percentage', $custom['percentage'] ?? null)],
                ['Suppressed Terms Count', (string) ($custom['suppressed_terms_count'] ?? 0)],
            ],
        ];

        $sections[] = [
            'title' => 'Symptom Analytics — Custom Symptom Terms',
            'description' => 'Terms reported fewer than 3 times are withheld above (suppressed_terms_count) rather than listed here — see the Custom Symptom Summary section.',
            'headers' => ['Symptom', 'Count'],
            'rows' => array_map(
                fn (array $entry) => [(string) $entry['name'], (string) $entry['count']],
                $custom['terms'] ?? [],
            ),
        ];

        $severity = $symptoms['severity'] ?? [];
        $sections[] = [
            'title' => 'Symptom Analytics — Severity Summary',
            'description' => null,
            'headers' => ['Metric', 'Value'],
            'rows' => [
                ['Valid Entries', (string) ($severity['valid_entries'] ?? 0)],
                ['Severe Count', (string) ($severity['severe_count'] ?? 0)],
                ['Default Bucket', (string) ($severity['default_bucket'] ?? '')],
            ],
        ];

        $sections[] = [
            'title' => 'Symptom Analytics — Severity Distribution',
            'description' => null,
            'headers' => ['Severity Level', 'Count'],
            'rows' => array_map(
                fn ($level, $count) => ["Severity {$level}", (string) $count],
                array_keys($severity['counts'] ?? []),
                array_values($severity['counts'] ?? []),
            ),
        ];

        return $sections;
    }

    /**
     * Recursively flattens an operational/period metrics array into
     * ['Human Readable Label', 'value'] pairs — a nested associative array
     * (e.g. my_open_cases, completion_rate) becomes one row per leaf,
     * labeled "Parent — Child". A list (e.g. a chart's own labels/data,
     * which never appears here) is left untouched rather than recursed
     * into, since flattening it would not produce a meaningful metric name.
     *
     * @return list<list<string>>
     */
    private static function flattenMetrics(array $metrics, string $prefix = ''): array
    {
        $rows = [];

        foreach ($metrics as $key => $value) {
            $label = self::humanize((string) $key);
            $label = $prefix !== '' ? "{$prefix} — {$label}" : $label;

            if (is_array($value) && self::isAssociative($value)) {
                array_push($rows, ...self::flattenMetrics($value, $label));

                continue;
            }

            $rows[] = [$label, self::formatValue((string) $key, $value)];
        }

        return $rows;
    }

    private static function formatValue(string $key, mixed $value): string
    {
        if (in_array($key, self::NULLABLE_PERCENTAGE_KEYS, true)) {
            return $value === null ? '—' : ((string) $value).'%';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            // Defensive only — every known operational/period leaf is a
            // scalar; a stray list here is rendered rather than dropped.
            return implode(', ', array_map('strval', $value));
        }

        return (string) $value;
    }

    /**
     * Human-readable timeline label for the resolved preset — reuses the
     * exact vocabulary resources/views/components/dash/filter-bar.blade.php
     * already presents in its own preset <select> (Today / This Week / This
     * Month / Last 30 Days / This Year), so a filename/title can never
     * disagree with what's shown on screen. 'custom' is the one case the
     * filter-bar's own dropdown can't spell out on its own (it just reads
     * "Custom Range" there, with the actual bounds shown in separate date
     * inputs) — a filename needs the real bounds in one string, so those are
     * formatted here instead.
     *
     * @param  string  $startDate  'Y-m-d', e.g. DateRange::$start->toDateString().
     * @param  string  $endDate  'Y-m-d', e.g. DateRange::$end->toDateString().
     */
    public static function timelineLabel(string $preset, string $startDate, string $endDate): string
    {
        return match ($preset) {
            'today' => 'Today',
            'this_week' => 'This Week',
            'this_month' => 'This Month',
            'last_30_days' => 'Last 30 Days',
            'this_year' => 'This Year',
            'custom' => self::formatDateRangeLabel($startDate, $endDate),
            default => ucwords(str_replace('_', ' ', $preset)),
        };
    }

    private static function formatDateRangeLabel(string $startDate, string $endDate): string
    {
        $start = \DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        $end = \DateTimeImmutable::createFromFormat('Y-m-d', $endDate);

        if (! $start || ! $end) {
            return 'Custom Range';
        }

        return $start->format('M d Y').' - '.$end->format('M d Y');
    }

    private static function isAssociative(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    private static function humanize(string $key): string
    {
        return ucwords(str_replace('_', ' ', $key));
    }
}
