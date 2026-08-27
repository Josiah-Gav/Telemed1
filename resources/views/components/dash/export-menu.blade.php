@props([
    'route',
    'routeParams' => [],
    'dateRange' => null,
    'queryParams' => [],
    'label' => 'Export Dashboard',
])

{{--
    Exports the current page exactly as currently filtered — reuses the
    existing x-dropdown/x-dropdown-link components (see
    resources/views/layouts/app.blade.php's account menu for the same
    pattern) rather than introducing a new dropdown implementation.

    Two ways to supply the current filter state, because dashboards and
    consultation-history pages use two different, deliberately unreconciled
    filter vocabularies (see CLAUDE.md / ConsultationHistoryQuery's own
    class docblock on why they stay separate):

     - :date-range — the original, dashboard-only path. Reads range/start/end
       straight from the same $dateRange object x-dash.filter-bar renders,
       so "what you're looking at" and "what you export" can never disagree.
       Only a resolved preset of 'custom' carries start/end in the URL,
       mirroring filter-bar's own x-if="preset === 'custom'" — for every
       other preset the backend (DateRange::fromInput) recomputes the same
       bounds from the preset alone, so passing stale start/end would be
       meaningless. Unchanged from the original dashboard-only version of
       this component; every existing dashboard call site keeps working
       exactly as before.

     - :query-params — a plain, already-built array, for callers whose
       filter shape isn't a DateRange (consultation-history's
       date_filter/status/consultation_type/search). The caller builds this
       from the same $filters array the page itself renders from, so it can
       never drift from what the page is actually showing either.

    The export routes and their authorization are untouched — this
    component only builds the URL.
--}}
@php
    $resolvedQuery = $queryParams;

    if ($dateRange) {
        $resolvedQuery = ['range' => $dateRange->preset];

        if ($dateRange->preset === 'custom') {
            $resolvedQuery['start'] = $dateRange->start->toDateString();
            $resolvedQuery['end'] = $dateRange->end->toDateString();
        }
    }

    $csvUrl = route($route, array_merge($routeParams, $resolvedQuery, ['format' => 'csv']));
    $pdfUrl = route($route, array_merge($routeParams, $resolvedQuery, ['format' => 'pdf']));
@endphp

<x-dropdown align="right" width="48">
    <x-slot name="trigger">
        <button
            type="button"
            class="inline-flex min-h-11 items-center gap-1.5 rounded-lg bg-brand-green px-3 py-2 text-sm font-semibold text-white transition hover:bg-brand-green-deep"
            aria-haspopup="true"
            x-bind:aria-expanded="open"
        >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
            </svg>
            {{ __('Export') }}
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="h-3 w-3" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m19 9-7 7-7-7" />
            </svg>
        </button>
    </x-slot>

    <x-slot name="content">
        <div class="border-b border-gray-100 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
            {{ __($label) }}
        </div>
        <x-dropdown-link :href="$csvUrl">
            {{ __('Export as CSV') }}
        </x-dropdown-link>
        <x-dropdown-link :href="$pdfUrl">
            {{ __('Export as PDF') }}
        </x-dropdown-link>
    </x-slot>
</x-dropdown>
