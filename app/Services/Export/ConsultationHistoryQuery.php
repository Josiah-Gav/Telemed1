<?php

namespace App\Services\Export;

use App\Models\Consultation;
use App\Models\FollowUpRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The single source of truth for consultation-history filtering, extracted
 * verbatim from ConsultationController::history() and
 * PhysicianController::consultationHistory(), which each carried their own
 * copy. The HTML pages call this, and the Phase 6 CSV/PDF exports will call
 * the same methods — that shared call is what makes an export provably
 * agree with the page it was exported from.
 *
 * Deliberately NOT DateRange. Dashboard analytics and consultation history
 * are separate date-filter vocabularies (`range/start/end` with presets and
 * a custom window vs. `date_filter` with today/last_7_days/last_30_days/all
 * and an open-ended start). Unifying them would change what the history
 * pages show, which is out of scope here — see CLAUDE.md.
 *
 * Every method returns a Builder rather than a Collection so the caller
 * chooses how to consume it: the controllers call ->get() exactly as they
 * did before, and a streaming export can call ->cursor() instead.
 *
 * This class performs NO authorization. Owner ids are passed in by the
 * controller, which remains responsible for authorizing the request
 * (authorizePhysician(), and the patient page's implicit auth()->id()
 * scoping). It also knows nothing about CSV, PDF, or Blade.
 *
 * The patient, physician, and nurse pages differ in ways that are
 * preserved, not reconciled: ordering column (submitted_at vs updated_at),
 * eager loading (none vs patient/nurse/consultationSession vs
 * patient/physician/consultationSession), and search (physician and nurse
 * only — patient/nurse for the physician page, patient/physician for the
 * nurse page, via applySearchFilter()'s $relations parameter).
 */
class ConsultationHistoryQuery
{
    public const ALLOWED_DATE_FILTERS = ['today', 'last_7_days', 'last_30_days', 'all'];

    public const ALLOWED_STATUS_FILTERS = ['completed', 'cancelled', 'rejected', 'all'];

    public const ALLOWED_TYPE_FILTERS = ['follow_up', 'general', 'all'];

    /**
     * Normalizes raw query-string input to the canonical filter array, with
     * every unrecognized value falling back to 'all' — the pre-existing
     * behavior in both controllers. Returns exactly the three shared keys;
     * the physician page appends its own 'search' key afterwards, since the
     * patient page's filter array must not gain one.
     *
     * @return array{date_filter: string, status: string, consultation_type: string}
     */
    public static function normalizeFilters(mixed $dateFilter, mixed $status, mixed $consultationType): array
    {
        return [
            'date_filter' => self::normalizeOne($dateFilter, self::ALLOWED_DATE_FILTERS),
            'status' => self::normalizeOne($status, self::ALLOWED_STATUS_FILTERS),
            'consultation_type' => self::normalizeOne($consultationType, self::ALLOWED_TYPE_FILTERS),
        ];
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function normalizeOne(mixed $value, array $allowed): string
    {
        $value = (string) ($value ?? 'all');

        return in_array($value, $allowed, true) ? $value : 'all';
    }

    /**
     * The patient's own concluded consultations, ordered newest-submitted
     * first. No eager loading — matching the pre-existing patient page,
     * which loads relations lazily from Blade.
     *
     * @param  array{date_filter: string, status: string, consultation_type: string}  $filters
     */
    public static function forPatient(int $patientId, array $filters): Builder
    {
        $query = Consultation::query()->where('patient_id', $patientId);

        self::applyConcludedOrSessionCompleted($query);
        self::applyStatusFilter($query, $filters['status'] ?? 'all');
        self::applyTypeFilter($query, $filters['consultation_type'] ?? 'all');
        self::applyDateFilter($query, $filters['date_filter'] ?? 'all', 'submitted_at');

        return $query->latest('submitted_at');
    }

    /**
     * The patient's rejected follow-up requests, which the patient history
     * page merges alongside consultations. A different model with its own
     * filter semantics, preserved exactly:
     *
     *  - dates are filtered on updated_at, not submitted_at;
     *  - any status filter other than 'rejected' eliminates every row;
     *  - consultation_type 'general' eliminates every row, while
     *    'follow_up' applies NO clause at all.
     *
     * @param  array{date_filter: string, status: string, consultation_type: string}  $filters
     */
    public static function rejectedFollowUpsForPatient(int $patientId, array $filters): Builder
    {
        $status = $filters['status'] ?? 'all';
        $type = $filters['consultation_type'] ?? 'all';

        $query = FollowUpRequest::query()
            ->with(['consultation.request'])
            ->where('patient_id', $patientId)
            ->where('status', 'rejected');

        if ($status !== 'all' && $status !== 'rejected') {
            $query->whereRaw('1 = 0');
        }

        if ($type === 'general') {
            $query->whereRaw('1 = 0');
        }

        self::applyDateFilter($query, $filters['date_filter'] ?? 'all', 'updated_at');

        return $query->latest('updated_at');
    }

    /**
     * The physician's own concluded consultations, ordered by updated_at
     * (not submitted_at) and eager-loading the relations the physician
     * history table renders. Supports the search filter, which the patient
     * page does not have.
     *
     * @param  array{date_filter: string, status: string, consultation_type: string, search?: string}  $filters
     */
    public static function forPhysician(int $physicianId, array $filters): Builder
    {
        $query = Consultation::with(['patient', 'nurse', 'consultationSession'])
            ->where('assigned_physician_id', $physicianId);

        self::applyConcludedOrSessionCompleted($query);
        self::applyStatusFilter($query, $filters['status'] ?? 'all');
        self::applyTypeFilter($query, $filters['consultation_type'] ?? 'all');
        self::applyDateFilter($query, $filters['date_filter'] ?? 'all', 'submitted_at');
        self::applySearchFilter($query, (string) ($filters['search'] ?? ''));

        return $query->orderByDesc('updated_at');
    }

    /**
     * The nurse's own concluded consultations — scoped via
     * Consultation::scopeForNurse() (assigned_nurse_id), which stays set for
     * the lifetime of a request and is inherited verbatim by any follow-up
     * spawned from it, so a nurse's history includes follow-ups without any
     * extra querying here. Mirrors forPhysician() otherwise: same concluded/
     * status/type/date filters on submitted_at, same updated_at ordering,
     * and search — but against patient-or-physician rather than
     * patient-or-nurse, since every row's nurse is the viewer.
     *
     * @param  array{date_filter: string, status: string, consultation_type: string, search?: string}  $filters
     */
    public static function forNurse(int $nurseId, array $filters): Builder
    {
        $query = Consultation::with(['patient', 'physician', 'consultationSession'])
            ->forNurse($nurseId);

        self::applyConcludedOrSessionCompleted($query);
        self::applyStatusFilter($query, $filters['status'] ?? 'all');
        self::applyTypeFilter($query, $filters['consultation_type'] ?? 'all');
        self::applyDateFilter($query, $filters['date_filter'] ?? 'all', 'submitted_at');
        self::applySearchFilter($query, (string) ($filters['search'] ?? ''), ['patient', 'physician']);

        return $query->orderByDesc('updated_at');
    }

    /**
     * Decorates each row of an already-fetched forPhysician() result with
     * has_existing_follow_up: true when a follow_up-type Consultation already
     * exists whose parent_consultation_id points at this row's
     * ConsultationSession. Mutates the given models in place via
     * setAttribute(), exactly as PhysicianController::consultationHistory()
     * did before Phase 6 — moved here verbatim (not reimplemented) so the
     * physician export can reproduce the identical value the HTML page
     * shows, from one implementation instead of two.
     *
     * Requires the whole result set materialized first (a Collection, not a
     * Builder): it plucks every row's session id in one pass, runs a single
     * second query for all of them, then decorates every row in a second
     * pass. This is also why forPhysician() itself is consumed via ->get()
     * rather than ->cursor() by every caller — a lazy cursor cannot support
     * this two-pass shape without being fully consumed first anyway.
     */
    public static function decorateHasExistingFollowUp(Collection $historyConsultations): void
    {
        $sourceSessionIds = $historyConsultations
            ->pluck('consultationSession.id')
            ->filter()
            ->values();

        $parentSessionIdsWithFollowUp = Consultation::query()
            ->where('type', 'follow_up')
            ->whereIn('parent_consultation_id', $sourceSessionIds)
            ->pluck('parent_consultation_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $historyConsultations->each(function (Consultation $consultation) use ($parentSessionIdsWithFollowUp) {
            $sessionId = (int) ($consultation->consultationSession?->id ?? 0);
            $consultation->setAttribute('has_existing_follow_up', $sessionId > 0 && $parentSessionIdsWithFollowUp->contains($sessionId));
        });
    }

    /**
     * A request belongs in history when it reached a terminal request_status
     * OR its clinical session is completed. The OR is load-bearing: an
     * 'active' request whose session completed still belongs in history.
     * Consultation::scopeCompleted() documents the same rule and explicitly
     * defers to these history pages.
     */
    private static function applyConcludedOrSessionCompleted(Builder $query): void
    {
        $query->where(function ($query) {
            $query->whereIn('request_status', ['completed', 'rejected', 'cancelled'])
                ->orWhereHas('consultationSession', function ($sessionQuery) {
                    $sessionQuery->where('consultation_status', 'completed');
                });
        });
    }

    private static function applyStatusFilter(Builder $query, string $status): void
    {
        if ($status !== 'all') {
            $query->where('request_status', $status);
        }
    }

    /**
     * 'general' means "not a follow-up". The whereNull('type') branch is
     * preserved from the original controllers for legacy rows; note that
     * consultation_requests.type is NOT NULL with a default of 'initial',
     * so it is unreachable under the current schema and is kept only to
     * avoid changing behavior in this refactor.
     */
    private static function applyTypeFilter(Builder $query, string $type): void
    {
        if ($type === 'follow_up') {
            $query->where('type', 'follow_up');

            return;
        }

        if ($type === 'general') {
            $query->where(function ($typeQuery) {
                $typeQuery->whereNull('type')->orWhere('type', '!=', 'follow_up');
            });
        }
    }

    /**
     * Open-ended windows, exactly as the controllers had them: 'today' is an
     * exact date match, while last_7_days/last_30_days are lower bounds from
     * the start of that day with no upper bound.
     */
    private static function applyDateFilter(Builder $query, string $dateFilter, string $column): void
    {
        match ($dateFilter) {
            'today' => $query->whereDate($column, now()->toDateString()),
            'last_7_days' => $query->where($column, '>=', now()->subDays(7)->startOfDay()),
            'last_30_days' => $query->where($column, '>=', now()->subDays(30)->startOfDay()),
            default => null,
        };
    }

    /**
     * Matches the term against the first/last name of each given relation.
     * Defaults to patient/nurse — the physician page's original, unchanged
     * behavior — so every existing caller keeps working exactly as before;
     * the nurse page passes ['patient', 'physician'] instead, since every
     * row's nurse is the viewer.
     *
     * @param  list<string>  $relations
     */
    private static function applySearchFilter(Builder $query, string $search, array $relations = ['patient', 'nurse']): void
    {
        if ($search === '') {
            return;
        }

        $query->where(function ($searchQuery) use ($search, $relations) {
            foreach ($relations as $relation) {
                $searchQuery->orWhereHas($relation, function ($relationQuery) use ($search) {
                    $relationQuery->where('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%');
                });
            }
        });
    }
}
