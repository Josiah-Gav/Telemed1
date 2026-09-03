<?php

namespace App\Services;

use App\Models\Consultation;
use App\Models\FollowUpRequest;
use App\Models\User;
use App\Support\DateRange;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Composes dashboard analytics for each role from the reusable definitions
 * on Consultation (scopeCompleted, scopeConcluded, etc.) and SymptomAnalytics.
 * Controllers stay thin: authorize, build a DateRange from the request, call
 * one method here, pass the result to the view. No business logic belongs
 * in a controller or a Blade view (Phase 1 §A-03).
 *
 * Every result is split into:
 *  - 'operational': current/point-in-time state, deliberately NOT filtered
 *    by the DateRange — the nurse's shared queue, "active now", etc. must
 *    render identically no matter what historical period is selected.
 *  - 'period': historical metrics scoped by the DateRange's submitted_at
 *    window.
 *  - 'charts': Chart.js-shaped {labels, datasets} series, period-scoped.
 *  - 'symptoms' (admin and physician only): SymptomAnalytics output.
 */
class DashboardAnalyticsService
{
    public function __construct(private readonly SymptomAnalytics $symptomAnalytics) {}

    public function forNurse(User $nurse, DateRange $range): array
    {
        $nurseId = (int) $nurse->user_id;

        $openCaseCounts = Consultation::forNurse($nurseId)
            ->whereIn('request_status', ['reviewed', 'scheduled', 'active'])
            ->select('request_status', DB::raw('count(*) as aggregate'))
            ->groupBy('request_status')
            ->pluck('aggregate', 'request_status');

        $periodQuery = fn (): Builder => Consultation::forNurse($nurseId)
            ->submittedBetween($range->start, $range->end);

        return [
            'operational' => [
                'unclaimed_pending' => Consultation::pending()->unclaimed()->count(),
                'unclaimed_high_priority' => Consultation::pending()->unclaimed()->highPriority()->count(),
                'follow_ups_awaiting_triage' => FollowUpRequest::where('status', 'pending')->count(),
                'my_open_cases' => [
                    'total' => (int) $openCaseCounts->sum(),
                    'reviewed' => (int) ($openCaseCounts['reviewed'] ?? 0),
                    'scheduled' => (int) ($openCaseCounts['scheduled'] ?? 0),
                    'active' => (int) ($openCaseCounts['active'] ?? 0),
                ],
                'my_active' => Consultation::forNurse($nurseId)->active()->count(),
            ],
            'period' => [
                // assigned_nurse_id is only ever set by claimByNurse() (which
                // always moves request_status to 'reviewed') for initial
                // requests; a follow-up inherits the column without going
                // through nurse review. Scoping to initial() therefore gives
                // a reliable count of requests this nurse actually reviewed,
                // even though request_status may have moved on since.
                'my_reviewed_requests' => (clone $periodQuery())->initial()->count(),
                'my_completed' => (clone $periodQuery())->completed()->count(),
            ],
            'charts' => $this->buildCharts($periodQuery, $range),
            'filters' => $this->filtersPayload($range),
        ];
    }

    public function forPhysician(User $physician, DateRange $range): array
    {
        $physicianId = (int) $physician->user_id;

        $periodQuery = fn (): Builder => Consultation::forPhysician($physicianId)
            ->submittedBetween($range->start, $range->end);

        $completed = (clone $periodQuery())->completed()->count();
        $concluded = (clone $periodQuery())->concluded()->count();

        // Same initial()-only scoping as forAdmin() and for the same reason:
        // a follow-up copies symptoms_desc verbatim from its parent, so
        // including it here would double-count that patient's report.
        $symptomRows = (clone $periodQuery())->initial()->pluck('symptoms_desc');
        $symptomSummary = $this->symptomAnalytics->summarize($symptomRows);
        $symptomSummary['standardized'] = array_slice($symptomSummary['standardized'], 0, 10);

        return [
            'operational' => [
                'active_now' => Consultation::forPhysician($physicianId)->active()->count(),
                'scheduled_ahead' => Consultation::forPhysician($physicianId)
                    ->where('request_status', 'scheduled')
                    ->count(),
            ],
            'period' => [
                'completed' => $completed,
                'completion_rate' => $this->completionRate($completed, $concluded),
            ],
            'charts' => $this->buildCharts($periodQuery, $range),
            'symptoms' => $symptomSummary,
            'filters' => $this->filtersPayload($range),
        ];
    }

    public function forAdmin(DateRange $range): array
    {
        $periodQuery = fn (): Builder => Consultation::query()
            ->submittedBetween($range->start, $range->end);

        $completed = (clone $periodQuery())->completed()->count();
        $concluded = (clone $periodQuery())->concluded()->count();

        // Symptom analytics: initial requests only (excludes follow-ups,
        // which copy symptoms_desc verbatim from the parent — see
        // SymptomAnalytics' class docblock). Select only the one column
        // needed rather than hydrating full models.
        $symptomRows = (clone $periodQuery())->initial()->pluck('symptoms_desc');
        $symptomSummary = $this->symptomAnalytics->summarize($symptomRows);
        $symptomSummary['standardized'] = array_slice($symptomSummary['standardized'], 0, 10);

        // Phase 5 finding H-1: "In flight now" must cover every in-flight
        // status (pending/reviewed/scheduled/active), not just pending and
        // active — a request sitting in 'reviewed' (nurse-claimed) or
        // 'scheduled' (physician-booked) is still in-flight and was
        // previously invisible to the admin. One grouped query gives both
        // the breakdown and the total (their sum), so they can never
        // disagree with each other or with Consultation::inFlight().
        $inFlightCounts = Consultation::inFlight()
            ->select('request_status', DB::raw('count(*) as aggregate'))
            ->groupBy('request_status')
            ->pluck('aggregate', 'request_status');

        $inFlightBreakdown = [
            'pending' => (int) ($inFlightCounts['pending'] ?? 0),
            'reviewed' => (int) ($inFlightCounts['reviewed'] ?? 0),
            'scheduled' => (int) ($inFlightCounts['scheduled'] ?? 0),
            'active' => (int) ($inFlightCounts['active'] ?? 0),
        ];

        return [
            'operational' => [
                'total_pending' => Consultation::pending()->count(),
                'total_active' => Consultation::active()->count(),
                'total_in_flight' => array_sum($inFlightBreakdown),
                'in_flight_breakdown' => $inFlightBreakdown,
            ],
            'period' => [
                'total_requests' => (clone $periodQuery())->count(),
                'completed' => $completed,
                'concluded' => $concluded,
                'completion_rate' => $this->completionRate($completed, $concluded),
            ],
            'charts' => $this->buildCharts($periodQuery, $range),
            'symptoms' => $symptomSummary,
            'filters' => $this->filtersPayload($range),
        ];
    }

    /**
     * completion_rate = completed / concluded * 100, where concluded =
     * completed + rejected + cancelled. In-flight requests are excluded
     * from both sides deliberately — Phase 1 evaluated "completed / total
     * submitted requests" and rejected it: an in-flight request counted
     * against the denominator would make the rate measure how recently the
     * dashboard was filtered, not service performance. Zero concluded
     * requests returns a null rate rather than dividing by zero; callers
     * must render that as "—", never "0%".
     */
    private function completionRate(int $completed, int $concluded): array
    {
        return [
            'completed' => $completed,
            'concluded' => $concluded,
            'rate' => $concluded > 0 ? round(($completed / $concluded) * 100, 1) : null,
        ];
    }

    /**
     * @param  Closure(): Builder  $scopedQuery  Returns a fresh, already
     *                                           role- and date-scoped builder each call — a Builder can't be
     *                                           safely reused across multiple independent aggregate queries.
     */
    private function buildCharts(Closure $scopedQuery, DateRange $range): array
    {
        return [
            'volume_over_time' => $this->volumeOverTime($scopedQuery, $range),
            'status_distribution' => $this->statusDistribution($scopedQuery),
            'priority_distribution' => $this->priorityDistribution($scopedQuery),
            'initial_vs_follow_up' => $this->initialVsFollowUp($scopedQuery),
        ];
    }

    /**
     * Daily buckets across the whole range, zero-filled — an empty day must
     * read as zero, not be silently omitted from the axis (Phase 1 §03).
     *
     * ponytail: always daily granularity, even across a multi-year custom
     * range. Coarser week/month bucketing for very wide ranges is a
     * frontend rendering concern (Phase 4), not a correctness concern here
     * — upgrade if a chart ever needs to render more than ~730 points.
     */
    private function volumeOverTime(Closure $scopedQuery, DateRange $range): array
    {
        $countsByDay = (clone $scopedQuery())
            ->selectRaw('DATE(submitted_at) as day, COUNT(*) as aggregate')
            ->groupBy('day')
            ->pluck('aggregate', 'day');

        $labels = [];
        $data = [];
        $cursor = $range->start->copy()->startOfDay();
        $end = $range->end->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->format('Y-m-d');
            $labels[] = $key;
            $data[] = (int) ($countsByDay[$key] ?? 0);
            $cursor->addDay();
        }

        return [
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Requests', 'data' => $data],
            ],
        ];
    }

    private function statusDistribution(Closure $scopedQuery): array
    {
        $counts = (clone $scopedQuery())
            ->select('request_status', DB::raw('count(*) as aggregate'))
            ->groupBy('request_status')
            ->pluck('aggregate', 'request_status');

        $labels = Consultation::MEANINGFUL_STATUSES;
        $data = array_map(fn (string $status) => (int) ($counts[$status] ?? 0), $labels);

        return ['labels' => $labels, 'datasets' => [['label' => 'Requests', 'data' => $data]]];
    }

    private function priorityDistribution(Closure $scopedQuery): array
    {
        $counts = (clone $scopedQuery())
            ->select('priority_level', DB::raw('count(*) as aggregate'))
            ->groupBy('priority_level')
            ->pluck('aggregate', 'priority_level');

        $labels = Consultation::PRIORITY_LEVELS;
        $data = array_map(fn (string $priority) => (int) ($counts[$priority] ?? 0), $labels);

        return ['labels' => $labels, 'datasets' => [['label' => 'Requests', 'data' => $data]]];
    }

    private function initialVsFollowUp(Closure $scopedQuery): array
    {
        $data = [
            (clone $scopedQuery())->initial()->count(),
            (clone $scopedQuery())->followUp()->count(),
        ];

        return [
            'labels' => ['Initial', 'Follow-up'],
            'datasets' => [['label' => 'Requests', 'data' => $data]],
        ];
    }

    private function filtersPayload(DateRange $range): array
    {
        return [
            'preset' => $range->preset,
            'start' => $range->start->toDateString(),
            'end' => $range->end->toDateString(),
        ];
    }
}
