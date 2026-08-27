<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('Admin Dashboard') }}
        </h2>
    </x-slot>

    @vite(['resources/js/dashboards.js'])

    <div class="py-8">
        <div class="max-w-7xl mx-auto space-y-8 px-4 sm:px-6 lg:px-8">

            <div class="overflow-hidden rounded-3xl border border-brand-border bg-gradient-to-r from-brand-green-soft via-white to-brand-gold-soft shadow-sm">
                <div class="p-6 text-brand-green-deep sm:p-8">
                    <p class="text-xs font-bold uppercase tracking-[0.22em] text-brand-green">Administrator</p>
                    <h2 class="mt-2 text-2xl font-bold text-slate-900">
                        {{ __('Hello Admin ' . Auth::user()->first_name) }}
                    </h2>
                </div>
            </div>

            {{-- ================= FILTER (admin: filter sits at the top — only "in flight" below is unfiltered) ================= --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex-1">
                    <x-dash.filter-bar
                        :date-range="$dateRange"
                        :action="route('dashboard')"
                        scope-note="Applies to service-health, case-mix, and symptom analytics below. 'In flight now' always shows current state."
                    />
                </div>
                <x-dash.export-menu
                    route="admin.dashboard.export"
                    :date-range="$dateRange"
                />
            </div>

            {{-- ================= SERVICE HEALTH ================= --}}
            <x-dash.section id="service-health" title="Service Health">
                @php
                    $rate = $analytics['period']['completion_rate'];
                    $rateDisplay = $rate['rate'] === null ? '—' : $rate['rate'] . '%';
                    $rateSupporting = $rate['concluded'] > 0
                        ? $rate['completed'] . ' of ' . $rate['concluded'] . ' concluded requests'
                        : 'No concluded requests yet in this period.';
                @endphp
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <x-dash.stat
                        label="Total requests"
                        :value="$analytics['period']['total_requests']"
                        supporting="Submitted in this period"
                    />
                    <x-dash.stat
                        label="Completed"
                        :value="$analytics['period']['completed']"
                        supporting="Of requests submitted this period"
                    />
                    <x-dash.stat
                        label="Completion Rate"
                        :value="$rateDisplay"
                        :supporting="$rateSupporting"
                    >
                        <p class="mt-2 text-xs text-slate-400">
                            Completed consultations as a percentage of concluded requests (completed + rejected + cancelled). In-progress requests are excluded.
                        </p>
                    </x-dash.stat>
                    @php
                        $inFlight = $analytics['operational']['total_in_flight'];
                        $inFlightBreakdown = $analytics['operational']['in_flight_breakdown'];
                    @endphp
                    <x-dash.stat
                        label="In flight now"
                        :value="$inFlight"
                        aria-label="{{ $inFlight }} requests currently in flight"
                        supporting="Current state — not affected by the date filter above"
                    >
                        <div class="mt-3 flex flex-wrap gap-2 text-xs text-slate-500">
                            <span class="rounded-full bg-slate-100 px-2 py-0.5">Pending {{ $inFlightBreakdown['pending'] }}</span>
                            <span class="rounded-full bg-slate-100 px-2 py-0.5">Reviewed {{ $inFlightBreakdown['reviewed'] }}</span>
                            <span class="rounded-full bg-slate-100 px-2 py-0.5">Scheduled {{ $inFlightBreakdown['scheduled'] }}</span>
                            <span class="rounded-full bg-slate-100 px-2 py-0.5">Active {{ $inFlightBreakdown['active'] }}</span>
                        </div>
                    </x-dash.stat>
                </div>
            </x-dash.section>

            {{-- ================= DEMAND ================= --}}
            @php
                $volume = $analytics['charts']['volume_over_time'];
                $hasEnoughPointsForLine = count($volume['labels']) >= 4;
                $totalVolume = array_sum($volume['datasets'][0]['data'] ?? []);
            @endphp

            @if ($hasEnoughPointsForLine)
                <x-dash.chart
                    chart-id="admin-volume-chart"
                    type="line"
                    title="Request volume over time"
                    :labels="$volume['labels']"
                    :datasets="$volume['datasets']"
                    summary="Line chart of system-wide request volume by submission date for the selected period"
                    empty-message="No consultation requests in this period."
                    height="h-72"
                />
            @else
                <div class="rounded-xl border border-brand-border bg-white p-4">
                    <h3 class="text-sm font-semibold text-slate-900">Request volume over time</h3>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-slate-900">{{ $totalVolume }} {{ $totalVolume === 1 ? 'request' : 'requests' }}</p>
                    <p class="mt-1 text-xs text-slate-500">Too few days in this period for a trend line — showing the total instead.</p>
                </div>
            @endif

            {{-- ================= CASE MIX ================= --}}
            {{--
                Phase 5 finding M-4: the 7-category status chart (h-56) was
                grid-paired with two single-row split-bar charts (h-32) in a
                3-up row. CSS grid stretched all three cells to match the
                tallest, leaving visible dead whitespace under both
                split-bar charts. Status gets its own full-width row; the
                two naturally-equal-height split-bar charts share a row.
            --}}
            <x-dash.section id="case-mix" title="Case Mix">
                @php
                    $statusChart = $analytics['charts']['status_distribution'];
                    $statusLabelsForDisplay = array_map('ucfirst', $statusChart['labels']);
                @endphp
                <x-dash.chart
                    chart-id="admin-status-chart"
                    type="hbar-status"
                    title="Status distribution"
                    :labels="$statusLabelsForDisplay"
                    :datasets="[['label' => 'Requests', 'data' => $statusChart['datasets'][0]['data']]]"
                    summary="Horizontal bar chart of all requests by status for the selected period"
                    empty-message="No requests in this period."
                    height="h-56"
                />

                <div class="grid gap-4 lg:grid-cols-2">
                    @php
                        $priorityChart = $analytics['charts']['priority_distribution'];
                    @endphp
                    <x-dash.chart
                        chart-id="admin-priority-chart"
                        type="splitbar-priority"
                        title="Priority mix"
                        :labels="$priorityChart['labels']"
                        :datasets="$priorityChart['datasets']"
                        summary="Proportion of all requests that are High versus Normal priority"
                        empty-message="No requests in this period."
                        height="h-32"
                        footnote="Unclaimed pending requests carry the default Normal priority and have not yet been triaged by a nurse."
                    />

                    @php
                        $typeChart = $analytics['charts']['initial_vs_follow_up'];
                    @endphp
                    <x-dash.chart
                        chart-id="admin-type-chart"
                        type="splitbar-type"
                        title="Initial vs Follow-up"
                        :labels="$typeChart['labels']"
                        :datasets="$typeChart['datasets']"
                        summary="Proportion of all requests that are initial versus follow-up"
                        empty-message="No requests in this period."
                        height="h-32"
                    />
                </div>
            </x-dash.section>

            {{-- ================= SYMPTOM ANALYTICS ================= --}}
            @php
                $symptoms = $analytics['symptoms'];
                $standardizedLabels = array_column($symptoms['standardized'], 'name');
                $standardizedCounts = array_column($symptoms['standardized'], 'count');
                $severityLabels = ['1', '2', '3 (Default)', '4 (Severe)'];
                $severityCounts = array_values($symptoms['severity']['counts']);
                $customPercentDisplay = $symptoms['custom']['percentage'] === null
                    ? '—'
                    : $symptoms['custom']['percentage'] . '%';
                $customTermRows = collect($symptoms['custom']['terms'])
                    ->map(fn ($term) => [$term['name'], $term['count']])
                    ->all();
                if ($symptoms['custom']['suppressed_terms_count'] > 0) {
                    $customTermRows[] = [
                        'Low frequency (fewer than 3 reports)',
                        $symptoms['custom']['suppressed_terms_count'] . ' ' . ($symptoms['custom']['suppressed_terms_count'] === 1 ? 'term' : 'terms'),
                    ];
                }
            @endphp
            <x-dash.section
                id="symptom-analytics"
                title="What Patients Are Reporting"
                description="Based on initial requests only. Follow-up consultations repeat the original request's symptoms, so including them would count the same report more than once."
            >
                <x-dash.chart
                    chart-id="admin-symptoms-chart"
                    type="hbar"
                    title="Most reported symptoms"
                    :labels="$standardizedLabels"
                    :datasets="[['label' => 'Requests', 'data' => $standardizedCounts]]"
                    :summary="'Horizontal bar chart of the top standardized symptoms across ' . $symptoms['valid_requests'] . ' requests with recorded symptoms'"
                    empty-message="No symptom data recorded for this period."
                    :footnote="'Out of ' . $symptoms['valid_requests'] . ' initial requests with at least one recorded symptom.'"
                />

                <div class="grid gap-4 lg:grid-cols-2">
                    <x-dash.chart
                        chart-id="admin-severity-chart"
                        type="severity"
                        title="Symptom severity distribution"
                        :labels="$severityLabels"
                        :datasets="[['label' => 'Reports', 'data' => $severityCounts]]"
                        summary="Bar chart of symptom severity from 1 to 4, with 3 marked as the pre-selected default"
                        empty-message="No severity data recorded for this period."
                        footnote="Severity 3 is pre-selected when a symptom is added, so this bucket includes patients who did not change it — treat it as a mix of deliberate and default entries, not a peak of moderate cases. Severe (4) reports: {{ $symptoms['severity']['severe_count'] }}."
                        height="h-56"
                    />

                    <div class="rounded-xl border border-brand-border bg-white p-4">
                        <h3 class="text-sm font-semibold text-slate-900">Custom symptom usage</h3>
                        <p class="mt-2 text-3xl font-bold tabular-nums text-slate-900">{{ $customPercentDisplay }}</p>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ $symptoms['custom']['requests_with_custom'] }} of {{ $symptoms['valid_requests'] }} requests included a custom symptom.
                        </p>

                        <h4 class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">Candidates for standardization</h4>
                        <p class="mt-1 text-xs text-slate-500">
                            Custom symptoms reported 3 or more times. Some custom symptom terms are hidden when reported fewer than 3 times, to protect patient privacy.
                        </p>
                        <div class="mt-3">
                            <x-dash.table
                                :headers="['Term', 'Reports']"
                                :rows="$customTermRows"
                                caption="Custom symptom terms reported three or more times"
                                empty-message="No custom symptom has been reported 3 or more times in this period."
                            />
                        </div>
                    </div>
                </div>
            </x-dash.section>

        </div>
    </div>
</x-app-layout>
