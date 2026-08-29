<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\FollowUpRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * CHARACTERIZATION TESTS for the two consultation-history pages.
 *
 * These were written BEFORE the Phase 5 extraction of
 * App\Services\Export\ConsultationHistoryQuery and describe the behavior the
 * controllers had at that moment — including quirks and the deliberate
 * asymmetries between the patient and physician pages. They are the parity
 * proof for the refactor: they passed before the extraction and must pass
 * unchanged after it.
 *
 * They assert on observable output (view data, filter arrays, ordering,
 * loaded relations) rather than on how the query is built, so the same
 * assertions remain valid whether the logic lives in a controller or in the
 * shared service.
 *
 * Known asymmetries deliberately captured here, NOT bugs to fix in Phase 5:
 *  - Patient orders by submitted_at; physician orders by updated_at.
 *  - Patient eager-loads nothing; physician eager-loads patient/nurse/session.
 *  - Only physician supports `search`.
 *  - The patient page merges rejected FollowUpRequest rows into historyItems,
 *    filtered on updated_at (not submitted_at), where consultation_type=
 *    follow_up applies NO clause and any status other than 'rejected'
 *    eliminates them entirely.
 */
function histPatient(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'patient',
        'user_type' => 'student',
    ], $overrides));
}

function histPhysician(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'physician',
        'user_type' => 'staff',
    ], $overrides));
}

function histNurse(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'nurse',
        'user_type' => 'staff',
    ], $overrides));
}

function histConsultation(array $overrides = []): Consultation
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

/**
 * Eloquent overwrites updated_at on save, so physician-ordering tests set it
 * with a direct DB write after creation.
 */
function histSetUpdatedAt(Consultation $consultation, string $timestamp): void
{
    DB::table('consultation_requests')
        ->where('request_id', $consultation->request_id)
        ->update(['updated_at' => $timestamp]);
}

/** @return array<int> request_ids in the order the patient page returned them */
function patientHistoryIds($response): array
{
    return collect($response->viewData('consultations'))
        ->pluck('request_id')
        ->map(fn ($id) => (int) $id)
        ->all();
}

/** @return array<int> request_ids in the order the physician page returned them */
function physicianHistoryIds($response): array
{
    return collect($response->viewData('historyConsultations'))
        ->pluck('request_id')
        ->map(fn ($id) => (int) $id)
        ->all();
}

// =====================================================================
// PATIENT — date_filter
// =====================================================================

it('patient history date_filter=today returns only consultations submitted today', function () {
    $patient = histPatient();
    $today = histConsultation(['patient_id' => $patient->user_id, 'submitted_at' => now()]);
    histConsultation(['patient_id' => $patient->user_id, 'submitted_at' => now()->subDays(3)]);

    $response = $this->actingAs($patient)->get(route('consultations.history', ['date_filter' => 'today']));

    $response->assertOk();
    expect(patientHistoryIds($response))->toBe([(int) $today->request_id]);
});

it('patient history date_filter=last_7_days includes day 6 and excludes day 8', function () {
    $patient = histPatient();
    $inside = histConsultation(['patient_id' => $patient->user_id, 'submitted_at' => now()->subDays(6)]);
    histConsultation(['patient_id' => $patient->user_id, 'submitted_at' => now()->subDays(8)]);

    $response = $this->actingAs($patient)->get(route('consultations.history', ['date_filter' => 'last_7_days']));

    expect(patientHistoryIds($response))->toBe([(int) $inside->request_id]);
});

it('patient history date_filter=last_30_days includes day 29 and excludes day 31', function () {
    $patient = histPatient();
    $inside = histConsultation(['patient_id' => $patient->user_id, 'submitted_at' => now()->subDays(29)]);
    histConsultation(['patient_id' => $patient->user_id, 'submitted_at' => now()->subDays(31)]);

    $response = $this->actingAs($patient)->get(route('consultations.history', ['date_filter' => 'last_30_days']));

    expect(patientHistoryIds($response))->toBe([(int) $inside->request_id]);
});

it('patient history date_filter=all returns consultations of any age', function () {
    $patient = histPatient();
    histConsultation(['patient_id' => $patient->user_id, 'submitted_at' => now()->subYears(3)]);
    histConsultation(['patient_id' => $patient->user_id, 'submitted_at' => now()]);

    $response = $this->actingAs($patient)->get(route('consultations.history', ['date_filter' => 'all']));

    expect(patientHistoryIds($response))->toHaveCount(2);
});

// =====================================================================
// PATIENT — status / type / fallbacks / ownership / ordering
// =====================================================================

it('patient history filters by each concluded status', function (string $status) {
    $patient = histPatient();
    $match = histConsultation(['patient_id' => $patient->user_id, 'request_status' => $status]);
    $other = $status === 'completed' ? 'cancelled' : 'completed';
    histConsultation(['patient_id' => $patient->user_id, 'request_status' => $other]);

    $response = $this->actingAs($patient)->get(route('consultations.history', ['status' => $status]));

    expect(patientHistoryIds($response))->toBe([(int) $match->request_id]);
})->with(['completed', 'cancelled', 'rejected']);

it('patient history consultation_type=follow_up returns only follow-ups', function () {
    $patient = histPatient();
    $followUp = histConsultation(['patient_id' => $patient->user_id, 'type' => 'follow_up']);
    histConsultation(['patient_id' => $patient->user_id, 'type' => 'initial']);

    $response = $this->actingAs($patient)->get(route('consultations.history', ['consultation_type' => 'follow_up']));

    expect(patientHistoryIds($response))->toBe([(int) $followUp->request_id]);
});

it('patient history consultation_type=general returns non-follow-up consultations', function () {
    // NOTE: the controllers also carry a whereNull('type') branch for legacy
    // rows, but consultation_requests.type is NOT NULL with a default of
    // 'initial', so a NULL type cannot be inserted and that branch is
    // unreachable under the current schema. It is preserved verbatim by the
    // refactor and locked by a SQL-level assertion in
    // tests/Feature/Export/ConsultationHistoryQueryTest.php instead.
    $patient = histPatient();
    $initial = histConsultation(['patient_id' => $patient->user_id, 'type' => 'initial']);
    histConsultation(['patient_id' => $patient->user_id, 'type' => 'follow_up']);

    $response = $this->actingAs($patient)->get(route('consultations.history', ['consultation_type' => 'general']));

    expect(patientHistoryIds($response))->toBe([(int) $initial->request_id]);
});

it('patient history falls back to all for every invalid filter value', function () {
    $patient = histPatient();
    histConsultation(['patient_id' => $patient->user_id]);

    $response = $this->actingAs($patient)->get(route('consultations.history', [
        'date_filter' => 'this_month',
        'status' => 'pending',
        'consultation_type' => 'nonsense',
    ]));

    $response->assertOk();
    expect($response->viewData('filters'))->toBe([
        'date_filter' => 'all',
        'status' => 'all',
        'consultation_type' => 'all',
    ]);
    expect(patientHistoryIds($response))->toHaveCount(1);
});

it('patient history defaults every filter to all when none are supplied', function () {
    $patient = histPatient();

    $response = $this->actingAs($patient)->get(route('consultations.history'));

    expect($response->viewData('filters'))->toBe([
        'date_filter' => 'all',
        'status' => 'all',
        'consultation_type' => 'all',
    ]);
});

it('patient history never returns another patient\'s consultations', function () {
    $patientA = histPatient();
    $patientB = histPatient();
    $mine = histConsultation(['patient_id' => $patientA->user_id]);
    histConsultation(['patient_id' => $patientB->user_id]);

    $response = $this->actingAs($patientA)->get(route('consultations.history'));

    expect(patientHistoryIds($response))->toBe([(int) $mine->request_id]);
});

it('patient history orders consultations by submitted_at descending', function () {
    $patient = histPatient();
    $oldest = histConsultation(['patient_id' => $patient->user_id, 'submitted_at' => now()->subDays(5)]);
    $newest = histConsultation(['patient_id' => $patient->user_id, 'submitted_at' => now()->subDay()]);
    $middle = histConsultation(['patient_id' => $patient->user_id, 'submitted_at' => now()->subDays(3)]);

    $response = $this->actingAs($patient)->get(route('consultations.history'));

    expect(patientHistoryIds($response))->toBe([
        (int) $newest->request_id,
        (int) $middle->request_id,
        (int) $oldest->request_id,
    ]);
});

it('patient history includes an in-flight request whose session is completed', function () {
    $patient = histPatient();
    // request_status is NOT concluded, but the session is completed — the
    // base predicate is an OR, so this row must still appear.
    $consultation = histConsultation(['patient_id' => $patient->user_id, 'request_status' => 'active']);
    ConsultationSession::create([
        'request_id' => $consultation->request_id,
        'physician_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'a',
        'plan' => 'p',
        'recommendations' => 'r',
    ]);

    $response = $this->actingAs($patient)->get(route('consultations.history'));

    expect(patientHistoryIds($response))->toBe([(int) $consultation->request_id]);
});

it('patient history excludes an in-flight request with no completed session', function () {
    $patient = histPatient();
    histConsultation(['patient_id' => $patient->user_id, 'request_status' => 'pending']);

    $response = $this->actingAs($patient)->get(route('consultations.history'));

    expect(patientHistoryIds($response))->toBe([]);
});

// =====================================================================
// PATIENT — rejected follow-up requests merged into historyItems
// =====================================================================

function histRejectedFollowUp(User $patient, ?string $updatedAt = null): FollowUpRequest
{
    $source = histConsultation(['patient_id' => $patient->user_id]);
    $session = ConsultationSession::create([
        'request_id' => $source->request_id,
        'physician_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'a',
        'plan' => 'p',
        'recommendations' => 'r',
    ]);

    $followUp = FollowUpRequest::create([
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'reason' => 'Still unwell.',
        'status' => 'rejected',
        'decision_notes' => 'Not clinically indicated.',
    ]);

    if ($updatedAt !== null) {
        DB::table('follow_up_requests')->where('id', $followUp->id)->update(['updated_at' => $updatedAt]);
        $followUp->refresh();
    }

    return $followUp;
}

it('patient history merges rejected follow-up requests into historyItems', function () {
    $patient = histPatient();
    histRejectedFollowUp($patient);

    $response = $this->actingAs($patient)->get(route('consultations.history'));

    $types = collect($response->viewData('historyItems'))->pluck('type')->all();

    expect($response->viewData('rejectedFollowUpRequests'))->toHaveCount(1)
        ->and($types)->toContain('rejected_follow_up_request')
        ->and($types)->toContain('consultation');
});

it('patient history sorts merged historyItems by their synthetic sort_at descending', function () {
    $patient = histPatient();
    // Consultation sorts on submitted_at; follow-up sorts on updated_at.
    histConsultation(['patient_id' => $patient->user_id, 'submitted_at' => now()->subDays(10)]);
    histRejectedFollowUp($patient, now()->subDays(1)->toDateTimeString());

    $response = $this->actingAs($patient)->get(route('consultations.history'));

    $items = collect($response->viewData('historyItems'));
    $timestamps = $items->map(fn ($i) => optional($i['sort_at'])->timestamp ?? 0)->all();
    $sorted = $timestamps;
    rsort($sorted);

    expect($timestamps)->toBe($sorted);
});

it('patient history keeps rejected follow-ups when status=rejected', function () {
    $patient = histPatient();
    histRejectedFollowUp($patient);

    $response = $this->actingAs($patient)->get(route('consultations.history', ['status' => 'rejected']));

    expect($response->viewData('rejectedFollowUpRequests'))->toHaveCount(1);
});

it('patient history eliminates rejected follow-ups for any non-rejected status filter', function (string $status) {
    $patient = histPatient();
    histRejectedFollowUp($patient);

    $response = $this->actingAs($patient)->get(route('consultations.history', ['status' => $status]));

    expect($response->viewData('rejectedFollowUpRequests'))->toHaveCount(0);
})->with(['completed', 'cancelled']);

it('patient history eliminates rejected follow-ups when consultation_type=general', function () {
    $patient = histPatient();
    histRejectedFollowUp($patient);

    $response = $this->actingAs($patient)->get(route('consultations.history', ['consultation_type' => 'general']));

    expect($response->viewData('rejectedFollowUpRequests'))->toHaveCount(0);
});

it('patient history keeps rejected follow-ups when consultation_type=follow_up, which applies no clause', function () {
    $patient = histPatient();
    histRejectedFollowUp($patient);

    $response = $this->actingAs($patient)->get(route('consultations.history', ['consultation_type' => 'follow_up']));

    expect($response->viewData('rejectedFollowUpRequests'))->toHaveCount(1);
});

it('patient history filters rejected follow-ups on updated_at, not submitted_at', function () {
    $patient = histPatient();
    // updated_at well outside the 7-day window.
    histRejectedFollowUp($patient, now()->subDays(20)->toDateTimeString());

    $response = $this->actingAs($patient)->get(route('consultations.history', ['date_filter' => 'last_7_days']));

    expect($response->viewData('rejectedFollowUpRequests'))->toHaveCount(0);
});

it('patient history never returns another patient\'s rejected follow-up requests', function () {
    $patientA = histPatient();
    $patientB = histPatient();
    histRejectedFollowUp($patientB);

    $response = $this->actingAs($patientA)->get(route('consultations.history'));

    expect($response->viewData('rejectedFollowUpRequests'))->toHaveCount(0);
});

// =====================================================================
// PHYSICIAN — date_filter
// =====================================================================

it('physician history date_filter=today returns only consultations submitted today', function () {
    $physician = histPhysician();
    $today = histConsultation(['patient_id' => histPatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'submitted_at' => now()]);
    histConsultation(['patient_id' => histPatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'submitted_at' => now()->subDays(3)]);

    $response = $this->actingAs($physician)->get(route('physician.consultation_history', [
        'physician' => $physician->user_id, 'date_filter' => 'today',
    ]));

    $response->assertOk();
    expect(physicianHistoryIds($response))->toBe([(int) $today->request_id]);
});

it('physician history date_filter=last_7_days includes day 6 and excludes day 8', function () {
    $physician = histPhysician();
    $inside = histConsultation(['patient_id' => histPatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'submitted_at' => now()->subDays(6)]);
    histConsultation(['patient_id' => histPatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'submitted_at' => now()->subDays(8)]);

    $response = $this->actingAs($physician)->get(route('physician.consultation_history', [
        'physician' => $physician->user_id, 'date_filter' => 'last_7_days',
    ]));

    expect(physicianHistoryIds($response))->toBe([(int) $inside->request_id]);
});

it('physician history date_filter=last_30_days includes day 29 and excludes day 31', function () {
    $physician = histPhysician();
    $inside = histConsultation(['patient_id' => histPatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'submitted_at' => now()->subDays(29)]);
    histConsultation(['patient_id' => histPatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'submitted_at' => now()->subDays(31)]);

    $response = $this->actingAs($physician)->get(route('physician.consultation_history', [
        'physician' => $physician->user_id, 'date_filter' => 'last_30_days',
    ]));

    expect(physicianHistoryIds($response))->toBe([(int) $inside->request_id]);
});

it('physician history date_filter=all returns consultations of any age', function () {
    $physician = histPhysician();
    histConsultation(['patient_id' => histPatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'submitted_at' => now()->subYears(3)]);
    histConsultation(['patient_id' => histPatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'submitted_at' => now()]);

    $response = $this->actingAs($physician)->get(route('physician.consultation_history', [
        'physician' => $physician->user_id, 'date_filter' => 'all',
    ]));

    expect(physicianHistoryIds($response))->toHaveCount(2);
});

// =====================================================================
// PHYSICIAN — status / type / search / fallbacks / ownership / ordering
// =====================================================================

it('physician history filters by each concluded status', function (string $status) {
    $physician = histPhysician();
    $match = histConsultation(['patient_id' => histPatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'request_status' => $status]);
    $other = $status === 'completed' ? 'cancelled' : 'completed';
    histConsultation(['patient_id' => histPatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'request_status' => $other]);

    $response = $this->actingAs($physician)->get(route('physician.consultation_history', [
        'physician' => $physician->user_id, 'status' => $status,
    ]));

    expect(physicianHistoryIds($response))->toBe([(int) $match->request_id]);
})->with(['completed', 'cancelled', 'rejected']);

it('physician history consultation_type=general returns non-follow-up consultations', function () {
    // See the patient-side equivalent above for why the whereNull('type')
    // branch cannot be exercised through a fixture.
    $physician = histPhysician();
    $initial = histConsultation(['patient_id' => histPatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'type' => 'initial']);
    histConsultation(['patient_id' => histPatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'type' => 'follow_up']);

    $response = $this->actingAs($physician)->get(route('physician.consultation_history', [
        'physician' => $physician->user_id, 'consultation_type' => 'general',
    ]));

    expect(physicianHistoryIds($response))->toBe([(int) $initial->request_id]);
});

it('physician history searches on patient first and last name', function (string $term) {
    $physician = histPhysician();
    $target = histPatient(['first_name' => 'Mario', 'last_name' => 'Santos']);
    $other = histPatient(['first_name' => 'Lara', 'last_name' => 'Cruz']);
    $match = histConsultation(['patient_id' => $target->user_id, 'assigned_physician_id' => $physician->user_id]);
    histConsultation(['patient_id' => $other->user_id, 'assigned_physician_id' => $physician->user_id]);

    $response = $this->actingAs($physician)->get(route('physician.consultation_history', [
        'physician' => $physician->user_id, 'search' => $term,
    ]));

    expect(physicianHistoryIds($response))->toBe([(int) $match->request_id]);
})->with(['Mario', 'Santos']);

it('physician history searches on nurse name as well as patient name', function () {
    $physician = histPhysician();
    $nurse = histNurse(['first_name' => 'Nina', 'last_name' => 'Lopez']);
    $match = histConsultation([
        'patient_id' => histPatient(['first_name' => 'Zed', 'last_name' => 'Zulu'])->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => $nurse->user_id,
    ]);
    histConsultation(['patient_id' => histPatient(['first_name' => 'Lara', 'last_name' => 'Cruz'])->user_id, 'assigned_physician_id' => $physician->user_id]);

    $response = $this->actingAs($physician)->get(route('physician.consultation_history', [
        'physician' => $physician->user_id, 'search' => 'Nina',
    ]));

    expect(physicianHistoryIds($response))->toBe([(int) $match->request_id]);
});

it('physician history trims surrounding whitespace from the search term', function () {
    $physician = histPhysician();
    $target = histPatient(['first_name' => 'Mario', 'last_name' => 'Santos']);
    $match = histConsultation(['patient_id' => $target->user_id, 'assigned_physician_id' => $physician->user_id]);
    histConsultation(['patient_id' => histPatient(['first_name' => 'Lara', 'last_name' => 'Cruz'])->user_id, 'assigned_physician_id' => $physician->user_id]);

    $response = $this->actingAs($physician)->get(route('physician.consultation_history', [
        'physician' => $physician->user_id, 'search' => '  Mario  ',
    ]));

    expect(physicianHistoryIds($response))->toBe([(int) $match->request_id])
        ->and($response->viewData('filters')['search'])->toBe('Mario');
});

it('physician history treats a whitespace-only search as no search at all', function () {
    $physician = histPhysician();
    histConsultation(['patient_id' => histPatient()->user_id, 'assigned_physician_id' => $physician->user_id]);
    histConsultation(['patient_id' => histPatient()->user_id, 'assigned_physician_id' => $physician->user_id]);

    $response = $this->actingAs($physician)->get(route('physician.consultation_history', [
        'physician' => $physician->user_id, 'search' => '   ',
    ]));

    expect(physicianHistoryIds($response))->toHaveCount(2)
        ->and($response->viewData('filters')['search'])->toBe('');
});

it('physician history falls back to all for every invalid filter value and keeps the search key', function () {
    $physician = histPhysician();
    histConsultation(['patient_id' => histPatient()->user_id, 'assigned_physician_id' => $physician->user_id]);

    $response = $this->actingAs($physician)->get(route('physician.consultation_history', [
        'physician' => $physician->user_id,
        'date_filter' => 'this_year',
        'status' => 'active',
        'consultation_type' => 'bogus',
    ]));

    expect($response->viewData('filters'))->toBe([
        'date_filter' => 'all',
        'status' => 'all',
        'consultation_type' => 'all',
        'search' => '',
    ]);
    expect(physicianHistoryIds($response))->toHaveCount(1);
});

it('physician history never returns another physician\'s consultations', function () {
    $physicianA = histPhysician();
    $physicianB = histPhysician();
    $mine = histConsultation(['patient_id' => histPatient()->user_id, 'assigned_physician_id' => $physicianA->user_id]);
    histConsultation(['patient_id' => histPatient()->user_id, 'assigned_physician_id' => $physicianB->user_id]);

    $response = $this->actingAs($physicianA)->get(route('physician.consultation_history', [
        'physician' => $physicianA->user_id,
    ]));

    expect(physicianHistoryIds($response))->toBe([(int) $mine->request_id]);
});

it('physician history orders consultations by updated_at descending, not submitted_at', function () {
    $physician = histPhysician();
    // submitted_at order is deliberately the REVERSE of updated_at order, so
    // this test fails if the ordering column is ever changed.
    $a = histConsultation(['patient_id' => histPatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'submitted_at' => now()->subDays(1)]);
    $b = histConsultation(['patient_id' => histPatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'submitted_at' => now()->subDays(5)]);

    histSetUpdatedAt($a, now()->subDays(9)->toDateTimeString());
    histSetUpdatedAt($b, now()->subDays(2)->toDateTimeString());

    $response = $this->actingAs($physician)->get(route('physician.consultation_history', [
        'physician' => $physician->user_id,
    ]));

    expect(physicianHistoryIds($response))->toBe([(int) $b->request_id, (int) $a->request_id]);
});

it('physician history eager-loads patient, nurse and consultationSession relations', function () {
    $physician = histPhysician();
    $consultation = histConsultation([
        'patient_id' => histPatient()->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => histNurse()->user_id,
    ]);
    ConsultationSession::create([
        'request_id' => $consultation->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'completed',
        'assessment' => 'a',
        'plan' => 'p',
        'recommendations' => 'r',
    ]);

    $response = $this->actingAs($physician)->get(route('physician.consultation_history', [
        'physician' => $physician->user_id,
    ]));

    $first = collect($response->viewData('historyConsultations'))->first();

    expect($first->relationLoaded('patient'))->toBeTrue()
        ->and($first->relationLoaded('nurse'))->toBeTrue()
        ->and($first->relationLoaded('consultationSession'))->toBeTrue();
});

it('physician history decorates each row with has_existing_follow_up', function () {
    $physician = histPhysician();
    $patient = histPatient();

    $withFollowUp = histConsultation(['patient_id' => $patient->user_id, 'assigned_physician_id' => $physician->user_id]);
    $session = ConsultationSession::create([
        'request_id' => $withFollowUp->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'completed',
        'assessment' => 'a',
        'plan' => 'p',
        'recommendations' => 'r',
    ]);
    histConsultation([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'type' => 'follow_up',
        'parent_consultation_id' => $session->id,
        'request_status' => 'scheduled',
    ]);

    $withoutFollowUp = histConsultation(['patient_id' => $patient->user_id, 'assigned_physician_id' => $physician->user_id]);
    ConsultationSession::create([
        'request_id' => $withoutFollowUp->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'completed',
        'assessment' => 'a',
        'plan' => 'p',
        'recommendations' => 'r',
    ]);

    $response = $this->actingAs($physician)->get(route('physician.consultation_history', [
        'physician' => $physician->user_id,
    ]));

    $byId = collect($response->viewData('historyConsultations'))->keyBy('request_id');

    expect($byId[$withFollowUp->request_id]->has_existing_follow_up)->toBeTrue()
        ->and($byId[$withoutFollowUp->request_id]->has_existing_follow_up)->toBeFalse();
});

it('physician history returns the partial as JSON for an ajax request', function () {
    $physician = histPhysician();
    histConsultation(['patient_id' => histPatient()->user_id, 'assigned_physician_id' => $physician->user_id]);

    $this->actingAs($physician)
        ->get(
            route('physician.consultation_history', ['physician' => $physician->user_id]),
            ['X-Requested-With' => 'XMLHttpRequest']
        )
        ->assertOk()
        ->assertJsonStructure(['html']);
});

// =====================================================================
// AUTHORIZATION — must be unchanged by the refactor
// =====================================================================

it('physician history refuses another physician\'s route parameter', function () {
    $physicianA = histPhysician();
    $physicianB = histPhysician();

    $this->actingAs($physicianA)
        ->get(route('physician.consultation_history', ['physician' => $physicianB->user_id]))
        ->assertForbidden();
});

it('physician history refuses a nurse', function () {
    $nurse = histNurse();
    $physician = histPhysician();

    $this->actingAs($nurse)
        ->get(route('physician.consultation_history', ['physician' => $physician->user_id]))
        ->assertForbidden();
});

it('patient history returns an empty result for a non-patient rather than another role\'s data', function () {
    // Characterizes CURRENT behavior: the patient history route has no role
    // check — it is scoped purely by patient_id — so a physician hitting it
    // sees their own (empty) patient-scoped list rather than a 403.
    $physician = histPhysician();
    histConsultation(['patient_id' => histPatient()->user_id]);

    $response = $this->actingAs($physician)->get(route('consultations.history'));

    $response->assertOk();
    expect(patientHistoryIds($response))->toBe([]);
});

// =====================================================================
// NURSE — Phase 2: NurseController::consultationHistory()
// =====================================================================

/** @return array<int> request_ids in the order the nurse page returned them */
function nurseHistoryIds($response): array
{
    return collect($response->viewData('historyConsultations'))
        ->pluck('request_id')
        ->map(fn ($id) => (int) $id)
        ->all();
}

it('authenticated nurse can access their own history', function () {
    $nurse = histNurse();
    $match = histConsultation(['patient_id' => histPatient()->user_id, 'assigned_nurse_id' => $nurse->user_id]);

    $response = $this->actingAs($nurse)->get(route('nurse.consultation_history', ['nurse' => $nurse->user_id]));

    $response->assertOk()->assertViewIs('nurse.consultation_history');
    expect(nurseHistoryIds($response))->toBe([(int) $match->request_id]);
});

it('nurse history refuses another nurse\'s route parameter', function () {
    $nurseA = histNurse();
    $nurseB = histNurse();

    $this->actingAs($nurseA)
        ->get(route('nurse.consultation_history', ['nurse' => $nurseB->user_id]))
        ->assertForbidden();
});

it('nurse history refuses a physician', function () {
    $physician = histPhysician();
    $nurse = histNurse();

    $this->actingAs($physician)
        ->get(route('nurse.consultation_history', ['nurse' => $nurse->user_id]))
        ->assertForbidden();
});

it('nurse history refuses a patient', function () {
    $patient = histPatient();
    $nurse = histNurse();

    $this->actingAs($patient)
        ->get(route('nurse.consultation_history', ['nurse' => $nurse->user_id]))
        ->assertForbidden();
});

it('nurse history refuses an admin', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $nurse = histNurse();

    $this->actingAs($admin)
        ->get(route('nurse.consultation_history', ['nurse' => $nurse->user_id]))
        ->assertForbidden();
});

it('nurse history redirects a guest to login', function () {
    $nurse = histNurse();

    $this->get(route('nurse.consultation_history', ['nurse' => $nurse->user_id]))
        ->assertRedirect(route('login'));
});

it('nurse history returns the partial as JSON for an ajax request', function () {
    $nurse = histNurse();
    histConsultation(['patient_id' => histPatient()->user_id, 'assigned_nurse_id' => $nurse->user_id]);

    $this->actingAs($nurse)
        ->get(
            route('nurse.consultation_history', ['nurse' => $nurse->user_id]),
            ['X-Requested-With' => 'XMLHttpRequest']
        )
        ->assertOk()
        ->assertJsonStructure(['html']);
});

it('nurse history renders the empty state when the nurse has no history', function () {
    $nurse = histNurse();

    $response = $this->actingAs($nurse)->get(route('nurse.consultation_history', ['nurse' => $nurse->user_id]));

    $response->assertOk();
    expect(nurseHistoryIds($response))->toBe([]);
    $response->assertSee('No consultation history found for the selected filters.');
});

it('nurse history includes a follow-up consultation inherited from the nurse\'s original request', function () {
    $nurse = histNurse();
    $followUp = histConsultation([
        'patient_id' => histPatient()->user_id,
        'assigned_nurse_id' => $nurse->user_id,
        'type' => 'follow_up',
    ]);

    $response = $this->actingAs($nurse)->get(route('nurse.consultation_history', [
        'nurse' => $nurse->user_id,
        'consultation_type' => 'follow_up',
    ]));

    expect(nurseHistoryIds($response))->toBe([(int) $followUp->request_id]);
});

it('nurse history filters by date_filter, status, and consultation_type together', function () {
    $nurse = histNurse();
    $match = histConsultation([
        'patient_id' => histPatient()->user_id,
        'assigned_nurse_id' => $nurse->user_id,
        'request_status' => 'completed',
        'type' => 'initial',
        'submitted_at' => now(),
    ]);
    histConsultation([
        'patient_id' => histPatient()->user_id,
        'assigned_nurse_id' => $nurse->user_id,
        'request_status' => 'cancelled',
        'type' => 'initial',
        'submitted_at' => now(),
    ]);

    $response = $this->actingAs($nurse)->get(route('nurse.consultation_history', [
        'nurse' => $nurse->user_id,
        'date_filter' => 'today',
        'status' => 'completed',
        'consultation_type' => 'general',
    ]));

    expect(nurseHistoryIds($response))->toBe([(int) $match->request_id]);
});

it('nurse history never returns another nurse\'s consultations', function () {
    $nurseA = histNurse();
    $nurseB = histNurse();
    histConsultation(['patient_id' => histPatient()->user_id, 'assigned_nurse_id' => $nurseB->user_id]);

    $response = $this->actingAs($nurseA)->get(route('nurse.consultation_history', ['nurse' => $nurseA->user_id]));

    expect(nurseHistoryIds($response))->toBe([]);
});
