<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\FollowUpRequest;
use App\Models\User;
use App\Services\Export\ConsultationHistoryQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Direct unit-style coverage of the shared history query service extracted in
 * Phase 5. The HTTP-level parity proof lives in
 * tests/Feature/ConsultationHistoryTest.php; this file exercises the service
 * on its own so Phase 6's exports can rely on it without going through a
 * controller.
 *
 * The service performs NO authorization — owner ids are supplied by the
 * caller. Route-level authorization is therefore asserted in the controller
 * feature tests, not here.
 */
function hqPatient(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'patient', 'user_type' => 'student'], $overrides));
}

function hqPhysician(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'physician', 'user_type' => 'staff'], $overrides));
}

function hqNurse(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'nurse', 'user_type' => 'staff'], $overrides));
}

function hqConsultation(array $overrides = []): Consultation
{
    return Consultation::forceCreate(array_merge([
        'patient_id' => null,
        'assigned_physician_id' => null,
        'assigned_nurse_id' => null,
        'type' => 'initial',
        'parent_consultation_id' => null,
        'concern_category' => 'general',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 3]],
        'online_reason' => 'Need consultation',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
        'submitted_at' => now(),
    ], $overrides));
}

/** All filters at their default 'all'. */
function hqAllFilters(array $overrides = []): array
{
    return array_merge([
        'date_filter' => 'all',
        'status' => 'all',
        'consultation_type' => 'all',
    ], $overrides);
}

/** @return array<int> */
function hqIds($builder): array
{
    return $builder->get()->pluck('request_id')->map(fn ($id) => (int) $id)->all();
}

// --- normalizeFilters ------------------------------------------------------

it('normalizes valid filter values unchanged', function () {
    $filters = ConsultationHistoryQuery::normalizeFilters('last_7_days', 'completed', 'follow_up');

    expect($filters)->toBe([
        'date_filter' => 'last_7_days',
        'status' => 'completed',
        'consultation_type' => 'follow_up',
    ]);
});

it('falls back to all for an unrecognized value in each filter', function (string $date, string $status, string $type) {
    $filters = ConsultationHistoryQuery::normalizeFilters($date, $status, $type);

    expect($filters)->toBe([
        'date_filter' => 'all',
        'status' => 'all',
        'consultation_type' => 'all',
    ]);
})->with([
    'dashboard vocabulary leaking in' => ['this_month', 'pending', 'initial'],
    'empty strings' => ['', '', ''],
    'sql-ish junk' => ["1' OR '1'='1", 'DROP TABLE', '../../etc'],
]);

it('falls back to all when a filter value is null', function () {
    $filters = ConsultationHistoryQuery::normalizeFilters(null, null, null);

    expect($filters)->toBe([
        'date_filter' => 'all',
        'status' => 'all',
        'consultation_type' => 'all',
    ]);
});

it('does not accept dashboard DateRange presets, which are a separate vocabulary', function (string $preset) {
    expect(ConsultationHistoryQuery::normalizeFilters($preset, 'all', 'all')['date_filter'])->toBe('all');
})->with(['this_week', 'this_month', 'this_year', 'custom']);

it('still accepts the history-only date values that DateRange does not have', function (string $value) {
    expect(ConsultationHistoryQuery::normalizeFilters($value, 'all', 'all')['date_filter'])->toBe($value);
})->with(['last_7_days', 'all', 'today', 'last_30_days']);

it('exposes exactly the pre-existing allowed filter vocabularies', function () {
    expect(ConsultationHistoryQuery::ALLOWED_DATE_FILTERS)->toBe(['today', 'last_7_days', 'last_30_days', 'all'])
        ->and(ConsultationHistoryQuery::ALLOWED_STATUS_FILTERS)->toBe(['completed', 'cancelled', 'rejected', 'all'])
        ->and(ConsultationHistoryQuery::ALLOWED_TYPE_FILTERS)->toBe(['follow_up', 'general', 'all']);
});

// --- forPatient ------------------------------------------------------------

it('scopes patient results to the given patient id only', function () {
    $a = hqPatient();
    $b = hqPatient();
    $mine = hqConsultation(['patient_id' => $a->user_id]);
    hqConsultation(['patient_id' => $b->user_id]);

    expect(hqIds(ConsultationHistoryQuery::forPatient((int) $a->user_id, hqAllFilters())))
        ->toBe([(int) $mine->request_id]);
});

it('applies each patient date filter window', function (string $filter, int $daysAgo, bool $expected) {
    $patient = hqPatient();
    $consultation = hqConsultation(['patient_id' => $patient->user_id, 'submitted_at' => now()->subDays($daysAgo)]);

    $ids = hqIds(ConsultationHistoryQuery::forPatient((int) $patient->user_id, hqAllFilters(['date_filter' => $filter])));

    expect(in_array((int) $consultation->request_id, $ids, true))->toBe($expected);
})->with([
    'today includes today' => ['today', 0, true],
    'today excludes yesterday' => ['today', 1, false],
    'last_7_days includes day 6' => ['last_7_days', 6, true],
    'last_7_days excludes day 8' => ['last_7_days', 8, false],
    'last_30_days includes day 29' => ['last_30_days', 29, true],
    'last_30_days excludes day 31' => ['last_30_days', 31, false],
    'all includes very old' => ['all', 900, true],
]);

it('applies the patient status filter', function () {
    $patient = hqPatient();
    $cancelled = hqConsultation(['patient_id' => $patient->user_id, 'request_status' => 'cancelled']);
    hqConsultation(['patient_id' => $patient->user_id, 'request_status' => 'completed']);

    expect(hqIds(ConsultationHistoryQuery::forPatient((int) $patient->user_id, hqAllFilters(['status' => 'cancelled']))))
        ->toBe([(int) $cancelled->request_id]);
});

it('applies the patient consultation_type filter', function (string $type, string $kept, string $dropped) {
    $patient = hqPatient();
    $keptRow = hqConsultation(['patient_id' => $patient->user_id, 'type' => $kept]);
    hqConsultation(['patient_id' => $patient->user_id, 'type' => $dropped]);

    expect(hqIds(ConsultationHistoryQuery::forPatient((int) $patient->user_id, hqAllFilters(['consultation_type' => $type]))))
        ->toBe([(int) $keptRow->request_id]);
})->with([
    'follow_up' => ['follow_up', 'follow_up', 'initial'],
    'general' => ['general', 'initial', 'follow_up'],
]);

it('orders patient results by submitted_at descending', function () {
    $patient = hqPatient();
    $old = hqConsultation(['patient_id' => $patient->user_id, 'submitted_at' => now()->subDays(5)]);
    $new = hqConsultation(['patient_id' => $patient->user_id, 'submitted_at' => now()->subDay()]);

    expect(hqIds(ConsultationHistoryQuery::forPatient((int) $patient->user_id, hqAllFilters())))
        ->toBe([(int) $new->request_id, (int) $old->request_id]);
});

it('includes a non-concluded request whose session is completed', function () {
    $patient = hqPatient();
    $consultation = hqConsultation(['patient_id' => $patient->user_id, 'request_status' => 'active']);
    ConsultationSession::create([
        'request_id' => $consultation->request_id,
        'physician_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'a', 'plan' => 'p', 'recommendations' => 'r',
    ]);

    expect(hqIds(ConsultationHistoryQuery::forPatient((int) $patient->user_id, hqAllFilters())))
        ->toBe([(int) $consultation->request_id]);
});

it('does not eager-load relations for the patient query, matching the pre-existing page', function () {
    $patient = hqPatient();
    hqConsultation(['patient_id' => $patient->user_id]);

    $first = ConsultationHistoryQuery::forPatient((int) $patient->user_id, hqAllFilters())->get()->first();

    expect($first->relationLoaded('patient'))->toBeFalse()
        ->and($first->relationLoaded('nurse'))->toBeFalse();
});

it('preserves the defensive whereNull type branch in the general filter SQL', function () {
    // consultation_requests.type is NOT NULL with a default of 'initial', so
    // this branch is unreachable through a fixture. It is asserted at the SQL
    // level so the refactor cannot silently drop behavior inherited from the
    // original controllers.
    $sql = ConsultationHistoryQuery::forPatient(1, hqAllFilters(['consultation_type' => 'general']))->toSql();

    expect($sql)->toContain('"type" is null');
});

// --- rejectedFollowUpsForPatient -------------------------------------------

function hqRejectedFollowUp(User $patient, ?string $updatedAt = null): FollowUpRequest
{
    $source = hqConsultation(['patient_id' => $patient->user_id]);
    $session = ConsultationSession::create([
        'request_id' => $source->request_id,
        'physician_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'a', 'plan' => 'p', 'recommendations' => 'r',
    ]);

    $followUp = FollowUpRequest::create([
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'reason' => 'Still unwell.',
        'status' => 'rejected',
        'decision_notes' => 'Not indicated.',
    ]);

    if ($updatedAt !== null) {
        DB::table('follow_up_requests')->where('id', $followUp->id)->update(['updated_at' => $updatedAt]);
    }

    return $followUp;
}

it('returns only the given patient\'s rejected follow-up requests', function () {
    $a = hqPatient();
    $b = hqPatient();
    hqRejectedFollowUp($a);
    hqRejectedFollowUp($b);

    $rows = ConsultationHistoryQuery::rejectedFollowUpsForPatient((int) $a->user_id, hqAllFilters())->get();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->patient_id)->toBe((int) $a->user_id);
});

it('excludes follow-up requests that are not rejected', function (string $status) {
    $patient = hqPatient();
    $followUp = hqRejectedFollowUp($patient);
    $followUp->update(['status' => $status]);

    expect(ConsultationHistoryQuery::rejectedFollowUpsForPatient((int) $patient->user_id, hqAllFilters())->get())
        ->toHaveCount(0);
})->with(['pending', 'forwarded', 'approved', 'cancelled']);

it('eliminates rejected follow-ups for a non-rejected status filter', function (string $status) {
    $patient = hqPatient();
    hqRejectedFollowUp($patient);

    expect(ConsultationHistoryQuery::rejectedFollowUpsForPatient((int) $patient->user_id, hqAllFilters(['status' => $status]))->get())
        ->toHaveCount(0);
})->with(['completed', 'cancelled']);

it('keeps rejected follow-ups when the status filter is rejected or all', function (string $status) {
    $patient = hqPatient();
    hqRejectedFollowUp($patient);

    expect(ConsultationHistoryQuery::rejectedFollowUpsForPatient((int) $patient->user_id, hqAllFilters(['status' => $status]))->get())
        ->toHaveCount(1);
})->with(['rejected', 'all']);

it('eliminates rejected follow-ups when consultation_type is general', function () {
    $patient = hqPatient();
    hqRejectedFollowUp($patient);

    expect(ConsultationHistoryQuery::rejectedFollowUpsForPatient((int) $patient->user_id, hqAllFilters(['consultation_type' => 'general']))->get())
        ->toHaveCount(0);
});

it('applies no clause at all for consultation_type follow_up on rejected follow-ups', function () {
    $patient = hqPatient();
    hqRejectedFollowUp($patient);

    expect(ConsultationHistoryQuery::rejectedFollowUpsForPatient((int) $patient->user_id, hqAllFilters(['consultation_type' => 'follow_up']))->get())
        ->toHaveCount(1);
});

it('filters rejected follow-ups on updated_at rather than submitted_at', function () {
    $patient = hqPatient();
    hqRejectedFollowUp($patient, now()->subDays(20)->toDateTimeString());

    expect(ConsultationHistoryQuery::rejectedFollowUpsForPatient((int) $patient->user_id, hqAllFilters(['date_filter' => 'last_7_days']))->get())
        ->toHaveCount(0);
});

it('eager-loads consultation.request on rejected follow-ups', function () {
    $patient = hqPatient();
    hqRejectedFollowUp($patient);

    $row = ConsultationHistoryQuery::rejectedFollowUpsForPatient((int) $patient->user_id, hqAllFilters())->get()->first();

    expect($row->relationLoaded('consultation'))->toBeTrue();
});

// --- forPhysician ----------------------------------------------------------

it('scopes physician results to the given physician id only', function () {
    $a = hqPhysician();
    $b = hqPhysician();
    $mine = hqConsultation(['patient_id' => hqPatient()->user_id, 'assigned_physician_id' => $a->user_id]);
    hqConsultation(['patient_id' => hqPatient()->user_id, 'assigned_physician_id' => $b->user_id]);

    expect(hqIds(ConsultationHistoryQuery::forPhysician((int) $a->user_id, hqAllFilters())))
        ->toBe([(int) $mine->request_id]);
});

it('applies each physician date filter window on submitted_at', function (string $filter, int $daysAgo, bool $expected) {
    $physician = hqPhysician();
    $consultation = hqConsultation([
        'patient_id' => hqPatient()->user_id,
        'assigned_physician_id' => $physician->user_id,
        'submitted_at' => now()->subDays($daysAgo),
    ]);

    $ids = hqIds(ConsultationHistoryQuery::forPhysician((int) $physician->user_id, hqAllFilters(['date_filter' => $filter])));

    expect(in_array((int) $consultation->request_id, $ids, true))->toBe($expected);
})->with([
    'today includes today' => ['today', 0, true],
    'today excludes yesterday' => ['today', 1, false],
    'last_7_days includes day 6' => ['last_7_days', 6, true],
    'last_7_days excludes day 8' => ['last_7_days', 8, false],
    'last_30_days includes day 29' => ['last_30_days', 29, true],
    'last_30_days excludes day 31' => ['last_30_days', 31, false],
    'all includes very old' => ['all', 900, true],
]);

it('applies the physician status and type filters', function () {
    $physician = hqPhysician();
    $match = hqConsultation([
        'patient_id' => hqPatient()->user_id,
        'assigned_physician_id' => $physician->user_id,
        'request_status' => 'rejected',
        'type' => 'follow_up',
    ]);
    hqConsultation([
        'patient_id' => hqPatient()->user_id,
        'assigned_physician_id' => $physician->user_id,
        'request_status' => 'completed',
        'type' => 'initial',
    ]);

    $filters = hqAllFilters(['status' => 'rejected', 'consultation_type' => 'follow_up']);

    expect(hqIds(ConsultationHistoryQuery::forPhysician((int) $physician->user_id, $filters)))
        ->toBe([(int) $match->request_id]);
});

it('searches physician results by patient or nurse name', function (string $term) {
    $physician = hqPhysician();
    $nurse = hqNurse(['first_name' => 'Nina', 'last_name' => 'Lopez']);
    $match = hqConsultation([
        'patient_id' => hqPatient(['first_name' => 'Mario', 'last_name' => 'Santos'])->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => $nurse->user_id,
    ]);
    hqConsultation([
        'patient_id' => hqPatient(['first_name' => 'Lara', 'last_name' => 'Cruz'])->user_id,
        'assigned_physician_id' => $physician->user_id,
    ]);

    expect(hqIds(ConsultationHistoryQuery::forPhysician((int) $physician->user_id, hqAllFilters(['search' => $term]))))
        ->toBe([(int) $match->request_id]);
})->with(['Mario', 'Santos', 'Nina', 'Lopez']);

it('treats an empty or missing search as no search clause', function (array $filters) {
    $physician = hqPhysician();
    hqConsultation(['patient_id' => hqPatient()->user_id, 'assigned_physician_id' => $physician->user_id]);
    hqConsultation(['patient_id' => hqPatient()->user_id, 'assigned_physician_id' => $physician->user_id]);

    expect(hqIds(ConsultationHistoryQuery::forPhysician((int) $physician->user_id, hqAllFilters($filters))))
        ->toHaveCount(2);
})->with([
    'empty string' => [['search' => '']],
    'key absent' => [[]],
]);

it('orders physician results by updated_at descending, not submitted_at', function () {
    $physician = hqPhysician();
    $a = hqConsultation(['patient_id' => hqPatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'submitted_at' => now()->subDay()]);
    $b = hqConsultation(['patient_id' => hqPatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'submitted_at' => now()->subDays(5)]);

    DB::table('consultation_requests')->where('request_id', $a->request_id)->update(['updated_at' => now()->subDays(9)]);
    DB::table('consultation_requests')->where('request_id', $b->request_id)->update(['updated_at' => now()->subDays(2)]);

    expect(hqIds(ConsultationHistoryQuery::forPhysician((int) $physician->user_id, hqAllFilters())))
        ->toBe([(int) $b->request_id, (int) $a->request_id]);
});

it('eager-loads patient, nurse and consultationSession for the physician query', function () {
    $physician = hqPhysician();
    hqConsultation([
        'patient_id' => hqPatient()->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => hqNurse()->user_id,
    ]);

    $first = ConsultationHistoryQuery::forPhysician((int) $physician->user_id, hqAllFilters())->get()->first();

    expect($first->relationLoaded('patient'))->toBeTrue()
        ->and($first->relationLoaded('nurse'))->toBeTrue()
        ->and($first->relationLoaded('consultationSession'))->toBeTrue();
});

it('returns a Builder so a caller can stream instead of materializing', function () {
    $patient = hqPatient();

    expect(ConsultationHistoryQuery::forPatient((int) $patient->user_id, hqAllFilters()))
        ->toBeInstanceOf(Builder::class)
        ->and(ConsultationHistoryQuery::forPhysician(1, hqAllFilters()))
        ->toBeInstanceOf(Builder::class)
        ->and(ConsultationHistoryQuery::rejectedFollowUpsForPatient(1, hqAllFilters()))
        ->toBeInstanceOf(Builder::class);
});

// --- Cross-role isolation --------------------------------------------------

it('never lets a patient id retrieve consultations owned by another patient', function () {
    $a = hqPatient();
    $b = hqPatient();
    hqConsultation(['patient_id' => $b->user_id]);

    expect(hqIds(ConsultationHistoryQuery::forPatient((int) $a->user_id, hqAllFilters())))->toBe([]);
});

it('never lets a physician id retrieve consultations assigned to another physician', function () {
    $a = hqPhysician();
    $b = hqPhysician();
    hqConsultation(['patient_id' => hqPatient()->user_id, 'assigned_physician_id' => $b->user_id]);

    expect(hqIds(ConsultationHistoryQuery::forPhysician((int) $a->user_id, hqAllFilters())))->toBe([]);
});

it('does not let the patient query see consultations merely assigned to that user as physician', function () {
    $user = hqPhysician();
    hqConsultation(['patient_id' => hqPatient()->user_id, 'assigned_physician_id' => $user->user_id]);

    expect(hqIds(ConsultationHistoryQuery::forPatient((int) $user->user_id, hqAllFilters())))->toBe([]);
});
