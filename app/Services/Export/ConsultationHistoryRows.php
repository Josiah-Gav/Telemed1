<?php

namespace App\Services\Export;

use App\Models\Consultation;
use App\Models\FollowUpRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Flattens already-fetched consultation-history results (from
 * ConsultationHistoryQuery, via ConsultationController::history() and
 * PhysicianController::consultationHistory()) into CSV/PDF row data — the
 * Phase 6 counterpart to DashboardExportRows. Same contract: a pure mapper.
 * It does not query the database, resolve filters, authorize, or invent a
 * field the history UI doesn't already show. The controller fetches via
 * ConsultationHistoryQuery, this class only reshapes what was fetched.
 *
 * Two roles, two column sets, deliberately not unified into one generic
 * schema — the patient and physician history pages show different fields
 * (see each method's docblock), and forcing them into a shared shape would
 * either invent data neither page displays or silently drop data one does.
 *
 * Clinical fields (assessment, plan, recommendations, diagnosis,
 * prescription metadata, attachments) are never touched here — neither
 * history page shows them, so neither export does.
 */
class ConsultationHistoryRows
{
    /**
     * PDF-only. History exports are not aggregates — a busy patient or
     * physician can have an unbounded number of rows — and dompdf holds the
     * entire rendered document in memory, so an uncapped PDF is a real
     * resource risk in a way an uncapped CSV (streamed row-by-row) is not.
     * CSV therefore stays uncapped; only the PDF row list is truncated, and
     * only at this rendering boundary — never inside the query itself,
     * which would silently change which rows even the capped set contains.
     */
    public const PDF_ROW_CAP = 500;

    /**
     * Merges consultations and rejected follow-up requests into the same
     * historyItems shape ConsultationController::history() builds for the
     * patient view, sorted by the same synthetic sort_at (submitted_at for a
     * consultation, updated_at for a rejected follow-up). Extracted here so
     * the HTML controller and the CSV/PDF export build this list from one
     * implementation — see ConsultationController::history(), which now
     * calls this instead of carrying its own copy.
     *
     * @param  Collection<int, Consultation>  $consultations
     * @param  Collection<int, FollowUpRequest>  $rejectedFollowUpRequests
     * @return Collection<int, array{type: string, sort_at: mixed, consultation?: Consultation, follow_up_request?: FollowUpRequest}>
     */
    public static function mergePatientHistoryItems(Collection $consultations, Collection $rejectedFollowUpRequests): Collection
    {
        return $consultations
            ->map(fn (Consultation $consultation) => [
                'type' => 'consultation',
                'sort_at' => $consultation->submitted_at,
                'consultation' => $consultation,
            ])
            ->concat(
                $rejectedFollowUpRequests->map(fn (FollowUpRequest $followUpRequest) => [
                    'type' => 'rejected_follow_up_request',
                    'sort_at' => $followUpRequest->updated_at,
                    'follow_up_request' => $followUpRequest,
                ])
            )
            ->sortByDesc(fn (array $item) => optional($item['sort_at'])->timestamp ?? 0)
            ->values();
    }

    public const PATIENT_HEADERS = [
        'Record Type',
        'Consultation Type',
        'Concern Category',
        'Status',
        'Submitted At',
        'Completed At',
        'Updated At',
        'Assigned Nurse',
        'Assigned Physician',
        'Symptoms',
        'Rejection Reason',
    ];

    /**
     * One rectangular row per historyItem — a 'consultation' row and a
     * 'rejected_follow_up_request' row share the same 11 columns (matching
     * PATIENT_HEADERS) because they are two different models merged into one
     * file, distinguished by the leading Record Type column. Only fields
     * that model actually has, and that the patient history page actually
     * shows, are populated; every other cell is left blank rather than
     * guessed at:
     *
     *  Consultation row: Consultation Type, Concern Category, Status,
     *  Submitted At, Completed At (via consultationSession, may be blank),
     *  Updated At, Assigned Nurse, Assigned Physician, Symptoms, and
     *  Rejection Reason (populated only when request_status is rejected —
     *  matches the "Decision for Rejection" block on the HTML page).
     *
     *  Rejected-follow-up row: Consultation Type is fixed to "Follow-up",
     *  Concern Category is read from the originating consultation (the
     *  page's own title-building logic does the same), Status is fixed to
     *  "Rejected" (the underlying query already filters to
     *  status=rejected), Updated At, and Rejection Reason is the
     *  FollowUpRequest's decision_notes — the same field the HTML page
     *  labels "Decision for Rejection"/"Decision Notes". Submitted At,
     *  Completed At, Assigned Nurse, Assigned Physician and Symptoms are
     *  consultation-only fields and stay blank, since FollowUpRequest
     *  carries none of them and the page does not display any for this
     *  row type either.
     *
     * @param  Collection<int, array{type: string, sort_at: mixed, consultation?: Consultation, follow_up_request?: FollowUpRequest}>  $historyItems
     * @return list<list<string>>
     */
    public static function patientRows(Collection $historyItems): array
    {
        return $historyItems->map(function (array $item) {
            return $item['type'] === 'consultation'
                ? self::patientConsultationRow($item['consultation'])
                : self::patientRejectedFollowUpRow($item['follow_up_request']);
        })->values()->all();
    }

    private static function patientConsultationRow(Consultation $consultation): array
    {
        return [
            'Consultation',
            $consultation->type === 'follow_up' ? 'Follow-up' : 'General',
            (string) $consultation->concern_category,
            ucfirst((string) $consultation->request_status),
            self::formatDateTime($consultation->submitted_at),
            self::formatDateTime($consultation->consultationSession?->completed_at),
            self::formatDateTime($consultation->updated_at),
            self::relationName($consultation->nurse),
            self::relationName($consultation->physician),
            self::flattenSymptoms($consultation->symptoms_desc),
            $consultation->request_status === 'rejected' ? (string) $consultation->rejection_reason : '',
        ];
    }

    private static function patientRejectedFollowUpRow(FollowUpRequest $followUpRequest): array
    {
        $sourceConsultation = $followUpRequest->consultation?->request;

        return [
            'Rejected Follow-up Request',
            'Follow-up',
            (string) ($sourceConsultation?->concern_category ?? ''),
            'Rejected',
            '',
            '',
            self::formatDateTime($followUpRequest->updated_at),
            '',
            '',
            '',
            (string) $followUpRequest->decision_notes,
        ];
    }

    public const PHYSICIAN_HEADERS = [
        'Patient',
        'Symptoms',
        'Assigned Nurse',
        'Consultation Type',
        'Status',
        'Completed At',
        'Updated At',
        'Has Existing Follow-up',
    ];

    /**
     * Mirrors resources/views/physician/partials/consultation_history_table.blade.php
     * column-for-column: patient name, flattened symptoms, assigned nurse,
     * consultation type label, status, and the same Completed At fallback
     * the partial uses (session completed_at, else the request's updated_at).
     * has_existing_follow_up must already be decorated on each row via
     * ConsultationHistoryQuery::decorateHasExistingFollowUp() before calling
     * this — it is read, never computed, here.
     *
     * @param  Collection<int, Consultation>  $historyConsultations
     * @return list<list<string>>
     */
    public static function physicianRows(Collection $historyConsultations): array
    {
        return $historyConsultations->map(function (Consultation $consultation) {
            $completedAt = $consultation->consultationSession?->completed_at ?? $consultation->updated_at;

            return [
                self::relationName($consultation->patient) ?: 'Unknown Patient',
                self::flattenSymptoms($consultation->symptoms_desc),
                self::relationName($consultation->nurse) ?: 'Unassigned',
                $consultation->type === 'follow_up' ? 'Follow-up' : 'General',
                ucfirst((string) $consultation->request_status),
                self::formatDateTime($completedAt),
                self::formatDateTime($consultation->updated_at),
                ((bool) ($consultation->has_existing_follow_up ?? false)) ? 'Yes' : 'No',
            ];
        })->values()->all();
    }

    public const NURSE_HEADERS = [
        'Patient',
        'Symptoms',
        'Concern Category',
        'Priority',
        'Assigned Physician',
        'Consultation Type',
        'Status',
        'Completed At',
        'Updated At',
    ];

    /**
     * Mirrors resources/views/nurse/partials/consultation_history_table.blade.php
     * column-for-column. No has_existing_follow_up column — that decoration
     * exists only to drive the physician page's "Schedule Follow-up" button,
     * which nurses cannot do — and no clinical fields, matching this class's
     * rule that neither history page shows assessment/plan/diagnosis, so
     * neither export does.
     *
     * @param  Collection<int, Consultation>  $historyConsultations
     * @return list<list<string>>
     */
    public static function nurseRows(Collection $historyConsultations): array
    {
        return $historyConsultations->map(function (Consultation $consultation) {
            $completedAt = $consultation->consultationSession?->completed_at ?? $consultation->updated_at;

            return [
                self::relationName($consultation->patient) ?: 'Unknown Patient',
                self::flattenSymptoms($consultation->symptoms_desc),
                (string) $consultation->concern_category,
                (string) $consultation->priority_level,
                self::relationName($consultation->physician) ?: 'Unassigned',
                $consultation->type === 'follow_up' ? 'Follow-up' : 'General',
                ucfirst((string) $consultation->request_status),
                self::formatDateTime($completedAt),
                self::formatDateTime($consultation->updated_at),
            ];
        })->values()->all();
    }

    /**
     * Assembles a title row, the meta key/value rows, a blank separator, the
     * header row, and the data rows into one flat list ready for
     * CsvDownload::stream() — the same stacked-report shape
     * DashboardExportRows::toCsvRows() uses. An empty result set gets one
     * explicit "no records" row instead of silently ending after the
     * header, satisfying the empty-export requirement without a caller
     * needing to special-case it.
     *
     * @param  list<array{0: string, 1: string}>  $metaRows
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @return list<list<string>>
     */
    public static function toCsvRows(string $title, array $metaRows, array $headers, array $rows): array
    {
        $csvRows = [[$title]];

        foreach ($metaRows as $row) {
            $csvRows[] = $row;
        }

        $csvRows[] = [];
        $csvRows[] = $headers;

        if ($rows === []) {
            $csvRows[] = ['No records matched the selected filters.'];

            return $csvRows;
        }

        foreach ($rows as $row) {
            $csvRows[] = $row;
        }

        return $csvRows;
    }

    /**
     * Human-readable rows for the currently-applied filters — used in both
     * the CSV meta block and the PDF letterhead, so a downloaded file always
     * states what it was filtered by. 'search' is included only when it was
     * actually applied (physician exports only; empty/whitespace-only search
     * is "no search", matching ConsultationHistoryQuery::applySearchFilter()).
     *
     * @param  array{date_filter?: string, status?: string, consultation_type?: string, search?: string}  $filters
     * @return list<array{0: string, 1: string}>
     */
    public static function filterSummaryRows(array $filters): array
    {
        $rows = [
            ['Date Range', self::timelineLabel($filters['date_filter'] ?? 'all')],
            ['Status', self::humanizeStatus($filters['status'] ?? 'all')],
            ['Consultation Type', self::humanizeType($filters['consultation_type'] ?? 'all')],
        ];

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $rows[] = ['Search', $search];
        }

        return $rows;
    }

    /**
     * Human-readable label for the resolved date_filter — the same
     * vocabulary filterSummaryRows() above renders into the metadata block,
     * promoted to public so export filenames/titles can reuse it directly
     * rather than a second copy ever drifting from what the metadata says.
     * Unlike the dashboard's DateRange, history's date_filter has no
     * 'custom' value (ConsultationHistoryQuery::ALLOWED_DATE_FILTERS is
     * today/last_7_days/last_30_days/all only), so there is no date-range
     * formatting case to handle here.
     */
    public static function timelineLabel(string $value): string
    {
        return match ($value) {
            'today' => 'Today',
            'last_7_days' => 'Last 7 Days',
            'last_30_days' => 'Last 30 Days',
            default => 'All',
        };
    }

    private static function humanizeStatus(string $value): string
    {
        return $value === 'all' ? 'All' : ucfirst($value);
    }

    private static function humanizeType(string $value): string
    {
        return match ($value) {
            'follow_up' => 'Follow-up',
            'general' => 'General',
            default => 'All',
        };
    }

    private static function relationName(?User $user): string
    {
        if (! $user) {
            return '';
        }

        return trim($user->first_name.' '.$user->last_name);
    }

    private static function formatDateTime(mixed $value): string
    {
        if (! $value) {
            return '';
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i') : (string) $value;
    }

    /**
     * Flattens symptoms_desc into a single readable string, mirroring the
     * defensive pattern already duplicated across the physician history
     * partial, DashboardController::getConsultationSummary(), and
     * NurseController::serializeConsultations() — the same "is_array ? pluck
     * name : use as-is, filter blanks, comma-join" logic those three already
     * apply independently. This is that logic given ONE home rather than a
     * fourth copy, not a new parsing strategy: never throws, and any entry
     * that isn't a usable array/string is simply skipped.
     *
     * History exports are row-level and carry every symptom a request has —
     * unlike the admin dashboard's SymptomAnalytics/DashboardExportRows
     * path, the k=3 custom-term suppression does NOT apply here, because
     * this is not an aggregate.
     */
    private static function flattenSymptoms(mixed $symptomsDesc): string
    {
        if (is_string($symptomsDesc) && trim($symptomsDesc) !== '') {
            return trim($symptomsDesc);
        }

        if (! is_array($symptomsDesc) || $symptomsDesc === []) {
            return '';
        }

        return collect($symptomsDesc)
            ->map(function ($entry) {
                if (is_array($entry)) {
                    $name = $entry['name'] ?? null;

                    return is_string($name) && trim($name) !== '' ? trim($name) : null;
                }

                return is_string($entry) && trim($entry) !== '' ? trim($entry) : null;
            })
            ->filter()
            ->implode(', ');
    }
}
