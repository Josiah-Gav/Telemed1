<?php

namespace App\Support;

/**
 * Single source of truth for how a request status, priority level, or symptom
 * severity is presented as a badge (label + Tailwind classes + icon path).
 *
 * Two different renderers need these tokens, which is why they live here rather
 * than inline in the Blade component:
 *
 *  - <x-dash.badge> renders them server-side for the nurse inbox, both
 *    dashboards, and both consultation-history views.
 *  - The physician consultation inbox renders its table rows with Alpine's
 *    x-for so the AJAX table-only refresh can repopulate them, and a Blade
 *    component cannot re-render per Alpine row. PhysicianController
 *    serializes these tokens into each row's JSON instead, and the template
 *    just binds them.
 *
 * Keeping one copy means a colour change here reaches both renderers; a JS
 * copy of these maps would silently drift from the Blade one.
 */
final class StatusBadge
{
    /**
     * Heroicons outline path data, keyed by the name used in the maps below.
     */
    private const ICONS = [
        'clock' => 'M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z',
        'clipboard-check' => 'M9 12.75l1.5 1.5 3-3.75M9 5.25H7.5A2.25 2.25 0 005.25 7.5v11.25A2.25 2.25 0 007.5 21h9a2.25 2.25 0 002.25-2.25V7.5A2.25 2.25 0 0016.5 5.25H15M9 5.25v1.5A1.5 1.5 0 0010.5 8.25h3A1.5 1.5 0 0015 6.75v-1.5m-6 0h6',
        'calendar' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0V11.25A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5',
        'signal' => 'M9.348 14.652a3.75 3.75 0 010-5.304m5.304 0a3.75 3.75 0 010 5.304m-7.425 2.121a6.75 6.75 0 010-9.546m9.546 0a6.75 6.75 0 010 9.546M12 12h.008v.008H12V12z',
        'check-circle' => 'M9 12.75l1.5 1.5 3-3.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'x-circle' => 'M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'minus-circle' => 'M15 12H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z',
        'arrow-up-circle' => 'M8.25 9.75L12 6l3.75 3.75M12 6v12',
        'user-check' => 'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z',
    ];

    /**
     * `assigned` is a real Consultation::request_status — the physician inbox
     * queries reviewed/assigned/scheduled — but it was missing here, so those
     * rows previously rendered an empty badge.
     */
    private const STATUS_MAP = [
        'pending' => ['label' => 'Pending', 'classes' => 'bg-amber-100 text-amber-800', 'icon' => 'clock'],
        'reviewed' => ['label' => 'Reviewed', 'classes' => 'bg-cyan-100 text-cyan-800', 'icon' => 'clipboard-check'],
        'assigned' => ['label' => 'Assigned', 'classes' => 'bg-sky-100 text-sky-800', 'icon' => 'user-check'],
        'scheduled' => ['label' => 'Scheduled', 'classes' => 'bg-indigo-100 text-indigo-800', 'icon' => 'calendar'],
        'active' => ['label' => 'Active', 'classes' => 'bg-brand-green text-white', 'icon' => 'signal'],
        'completed' => ['label' => 'Completed', 'classes' => 'bg-slate-100 text-slate-700', 'icon' => 'check-circle'],
        'rejected' => ['label' => 'Rejected', 'classes' => 'bg-red-100 text-red-800', 'icon' => 'x-circle'],
        'cancelled' => ['label' => 'Cancelled', 'classes' => 'border border-dashed border-slate-300 bg-slate-50 text-slate-600', 'icon' => 'minus-circle'],
    ];

    private const PRIORITY_MAP = [
        'High' => ['label' => 'High Priority', 'classes' => 'bg-red-100 text-red-800', 'icon' => 'arrow-up-circle'],
        'Normal' => ['label' => 'Normal Priority', 'classes' => 'bg-slate-100 text-slate-600', 'icon' => null],
    ];

    /**
     * Severity is a 1-4 scale on each entry of Consultation::symptoms_desc.
     * Unlike status/priority it has no icon — the number carries the meaning,
     * so colour is never the only signal.
     */
    private const SEVERITY_MAP = [
        1 => ['label' => '1 - Very Mild', 'classes' => 'bg-green-100 text-green-800'],
        2 => ['label' => '2 - Mild', 'classes' => 'bg-yellow-100 text-yellow-800'],
        3 => ['label' => '3 - Moderate', 'classes' => 'bg-orange-100 text-orange-800'],
        4 => ['label' => '4 - Severe', 'classes' => 'bg-red-100 text-red-800'],
    ];

    private const NEUTRAL_SEVERITY = ['label' => 'N/A', 'classes' => 'bg-gray-100 text-gray-700'];

    /**
     * @return array{label: string, classes: string, icon_path: string|null}|null
     */
    public static function status(?string $status): ?array
    {
        return $status === null ? null : self::resolve(self::STATUS_MAP[$status] ?? null);
    }

    /**
     * @return array{label: string, classes: string, icon_path: string|null}|null
     */
    public static function priority(?string $priority): ?array
    {
        return $priority === null ? null : self::resolve(self::PRIORITY_MAP[$priority] ?? null);
    }

    /**
     * Always returns a badge — an unscored consultation still needs an "N/A"
     * cell rather than an empty one.
     *
     * @return array{label: string, classes: string, icon_path: null}
     */
    public static function severity(?int $severity): array
    {
        $config = self::SEVERITY_MAP[$severity] ?? self::NEUTRAL_SEVERITY;

        return [
            'label' => $config['label'],
            'classes' => $config['classes'],
            'icon_path' => null,
        ];
    }

    /**
     * The highest severity across a symptoms_desc payload, which is what the
     * inbox tables show: one badge per consultation, not per symptom. Returns
     * null when the payload carries no numeric severity at all.
     */
    public static function highestSeverity(mixed $symptoms): ?int
    {
        if (! is_array($symptoms)) {
            return null;
        }

        $values = collect($symptoms)
            ->map(fn ($item) => is_array($item) ? ($item['severity'] ?? null) : null)
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value)
            ->all();

        return $values === [] ? null : max($values);
    }

    /**
     * @param  array{label: string, classes: string, icon: string|null}|null  $config
     * @return array{label: string, classes: string, icon_path: string|null}|null
     */
    private static function resolve(?array $config): ?array
    {
        if ($config === null) {
            return null;
        }

        return [
            'label' => $config['label'],
            'classes' => $config['classes'],
            'icon_path' => $config['icon'] === null ? null : self::ICONS[$config['icon']],
        ];
    }
}
