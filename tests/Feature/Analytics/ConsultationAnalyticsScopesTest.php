<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * These scopes are the canonical, reusable definitions behind every dashboard
 * metric (Phase 1 analytics blueprint, DEF-01/DEF-02). Every test here proves
 * a business rule, not just that a number comes back.
 */
function analyticsPatient(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'patient',
        'user_type' => 'student',
    ], $overrides));
}

function analyticsNurse(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'nurse',
        'user_type' => 'staff',
    ], $overrides));
}

function analyticsPhysician(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ], $overrides));
}

function analyticsRequest(User $patient, array $overrides = []): Consultation
{
    return Consultation::forceCreate(array_merge([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => null,
        'assigned_nurse_id' => null,
        'type' => 'initial',
        'parent_consultation_id' => null,
        'concern_category' => 'general',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 3]],
        'online_reason' => 'Need consultation',
        'request_status' => 'pending',
        'priority_level' => 'Normal',
        'file_attachments' => null,
        'submitted_at' => now(),
    ], $overrides));
}

function analyticsSession(Consultation $request, array $overrides = []): ConsultationSession
{
    return ConsultationSession::create(array_merge([
        'request_id' => $request->request_id,
        'physician_id' => $request->assigned_physician_id,
        'consultation_status' => 'active',
        'assessment' => 'Initial assessment pending.',
        'plan' => 'Plan to be documented during consultation.',
        'recommendations' => 'Recommendations to follow after evaluation.',
    ], $overrides));
}

// --- completed: OR-based definition ---------------------------------------

it('treats a request as completed when request_status is completed', function () {
    $request = analyticsRequest(analyticsPatient(), ['request_status' => 'completed']);

    expect(Consultation::completed()->whereKey($request->request_id)->exists())->toBeTrue();
});

it('treats a request as completed when only the session status is completed', function () {
    $request = analyticsRequest(analyticsPatient(), ['request_status' => 'active']);
    analyticsSession($request, ['consultation_status' => 'completed']);

    expect(Consultation::completed()->whereKey($request->request_id)->exists())->toBeTrue();
});

it('treats a request as completed exactly once when both sides agree', function () {
    $request = analyticsRequest(analyticsPatient(), ['request_status' => 'completed']);
    analyticsSession($request, ['consultation_status' => 'completed']);

    expect(Consultation::completed()->where('request_id', $request->request_id)->count())->toBe(1);
});

it('does not treat an in-flight request with no completed session as completed', function () {
    $request = analyticsRequest(analyticsPatient(), ['request_status' => 'active']);
    analyticsSession($request, ['consultation_status' => 'active']);

    expect(Consultation::completed()->whereKey($request->request_id)->exists())->toBeFalse();
});

// --- concluded / in-flight partition ---------------------------------------

it('includes completed, rejected, and cancelled requests in concluded', function () {
    $patient = analyticsPatient();
    analyticsRequest($patient, ['request_status' => 'completed']);
    analyticsRequest($patient, ['request_status' => 'rejected']);
    analyticsRequest($patient, ['request_status' => 'cancelled']);

    expect(Consultation::concluded()->count())->toBe(3);
});

it('excludes pending, reviewed, scheduled, and active requests from concluded', function () {
    $patient = analyticsPatient();
    foreach (['pending', 'reviewed', 'scheduled', 'active'] as $status) {
        analyticsRequest($patient, ['request_status' => $status]);
    }

    expect(Consultation::concluded()->count())->toBe(0);
});

it('counts a session-completed request as concluded even if request_status lags', function () {
    $request = analyticsRequest(analyticsPatient(), ['request_status' => 'active']);
    analyticsSession($request, ['consultation_status' => 'completed']);

    expect(Consultation::concluded()->whereKey($request->request_id)->exists())->toBeTrue();
});

it('includes pending, reviewed, scheduled, and active requests in in-flight', function () {
    $patient = analyticsPatient();
    foreach (['pending', 'reviewed', 'scheduled', 'active'] as $status) {
        analyticsRequest($patient, ['request_status' => $status]);
    }

    expect(Consultation::inFlight()->count())->toBe(4);
});

it('excludes concluded requests from in-flight', function () {
    $patient = analyticsPatient();
    analyticsRequest($patient, ['request_status' => 'completed']);
    analyticsRequest($patient, ['request_status' => 'rejected']);
    analyticsRequest($patient, ['request_status' => 'cancelled']);

    expect(Consultation::inFlight()->count())->toBe(0);
});

// --- initial vs follow-up, including legacy NULL ---------------------------

it('treats an explicit initial type as initial', function () {
    $request = analyticsRequest(analyticsPatient(), ['type' => 'initial']);

    expect(Consultation::initial()->whereKey($request->request_id)->exists())->toBeTrue();
});

it('excludes follow_up type from initial', function () {
    $request = analyticsRequest(analyticsPatient(), ['type' => 'follow_up']);

    expect(Consultation::initial()->whereKey($request->request_id)->exists())->toBeFalse();
});

/**
 * The `type` column is defined `enum(...) NOT NULL DEFAULT 'initial'`
 * (migration 2026_08_06_180500_add_follow_up_fields_to_consultation_
 * requests_table.php) with no ->nullable() — and unlike the two
 * alter_*_enum migrations, this one is not skipped on SQLite, so the
 * constraint is genuinely enforced identically in tests and in MySQL/
 * MariaDB. A type = NULL row cannot be inserted through Eloquent or a raw
 * DB::table() insert in either environment; the assertion below proves it
 * rather than assuming it, so this stays true if the schema ever changes.
 *
 * CLAUDE.md and the Phase 1 blueprint both describe "legacy NULL type"
 * rows as a real possibility that the app defensively handles
 * (ConsultationController::history, PhysicianController::
 * consultationHistory both use whereNull('type')->orWhere('type', '!=',
 * 'follow_up')). That defensive code is not wrong, but under the current
 * schema it is unreachable — there is no data it can ever match. This
 * scope keeps the same defensive OR-NULL clause for consistency and as a
 * safeguard if the column is ever relaxed, but "NULL is normalized to
 * initial" cannot be proven against a live row because no such row can
 * exist.
 */
it('the type column rejects NULL at the database level, in this environment as in production', function () {
    expect(fn () => DB::table('consultation_requests')->insert([
        'patient_id' => analyticsPatient()->user_id,
        'concern_category' => 'general',
        'symptoms_desc' => json_encode([]),
        'online_reason' => 'test',
        'request_status' => 'pending',
        'priority_level' => 'Normal',
        'type' => null,
        'submitted_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

// --- role scoping ------------------------------------------------------------

it('forNurse scopes to only the given nurse, excluding other nurses', function () {
    $patient = analyticsPatient();
    $nurseA = analyticsNurse();
    $nurseB = analyticsNurse();

    $mine = analyticsRequest($patient, ['assigned_nurse_id' => $nurseA->user_id]);
    analyticsRequest($patient, ['assigned_nurse_id' => $nurseB->user_id]);

    $matched = Consultation::forNurse((int) $nurseA->user_id)->pluck('request_id');

    expect($matched)->toHaveCount(1);
    expect((int) $matched->first())->toBe((int) $mine->request_id);
});

it('forNurse includes a follow-up that inherited assigned_nurse_id from its parent', function () {
    $patient = analyticsPatient();
    $nurse = analyticsNurse();

    analyticsRequest($patient, [
        'assigned_nurse_id' => $nurse->user_id,
        'type' => 'follow_up',
    ]);

    expect(Consultation::forNurse((int) $nurse->user_id)->count())->toBe(1);
});

it('forPhysician scopes to only the given physician, excluding another physician who rejected a request', function () {
    $patient = analyticsPatient();
    $physicianA = analyticsPhysician();
    $physicianB = analyticsPhysician();

    $mine = analyticsRequest($patient, [
        'assigned_physician_id' => $physicianA->user_id,
        'request_status' => 'active',
    ]);
    // Physician B rejected a different request; it has no session at all.
    analyticsRequest($patient, [
        'assigned_physician_id' => $physicianB->user_id,
        'request_status' => 'rejected',
    ]);

    $matched = Consultation::forPhysician((int) $physicianA->user_id)->pluck('request_id');

    expect($matched)->toHaveCount(1);
    expect((int) $matched->first())->toBe((int) $mine->request_id);
});

// --- date scoping ------------------------------------------------------------

it('submittedBetween includes requests inside the range, at both boundaries', function () {
    $patient = analyticsPatient();
    $start = now()->subDays(5)->startOfDay();
    $end = now()->subDays(1)->endOfDay();

    $atStart = analyticsRequest($patient, ['submitted_at' => $start]);
    $atEnd = analyticsRequest($patient, ['submitted_at' => $end]);
    $middle = analyticsRequest($patient, ['submitted_at' => $start->copy()->addDays(2)]);

    $matched = Consultation::submittedBetween($start, $end)->pluck('request_id')->map(fn ($id) => (int) $id);

    expect($matched)->toHaveCount(3)
        ->and($matched)->toContain((int) $atStart->request_id, (int) $atEnd->request_id, (int) $middle->request_id);
});

it('submittedBetween excludes requests outside the range', function () {
    $patient = analyticsPatient();
    $start = now()->subDays(5)->startOfDay();
    $end = now()->subDays(1)->endOfDay();

    analyticsRequest($patient, ['submitted_at' => $start->copy()->subDay()]);
    analyticsRequest($patient, ['submitted_at' => $end->copy()->addDay()]);

    expect(Consultation::submittedBetween($start, $end)->count())->toBe(0);
});

// --- pending / unclaimed / active / high priority ---------------------------

it('unclaimed pending combines pending status with no assigned nurse', function () {
    $patient = analyticsPatient();
    $nurse = analyticsNurse();

    $unclaimed = analyticsRequest($patient, ['request_status' => 'pending', 'assigned_nurse_id' => null]);
    analyticsRequest($patient, ['request_status' => 'pending', 'assigned_nurse_id' => $nurse->user_id]);
    analyticsRequest($patient, ['request_status' => 'reviewed', 'assigned_nurse_id' => null]);

    $matched = Consultation::pending()->unclaimed()->pluck('request_id');

    expect($matched)->toHaveCount(1);
    expect((int) $matched->first())->toBe((int) $unclaimed->request_id);
});

it('scopeActive matches only request_status active', function () {
    $patient = analyticsPatient();
    $active = analyticsRequest($patient, ['request_status' => 'active']);
    analyticsRequest($patient, ['request_status' => 'scheduled']);

    $matched = Consultation::active()->pluck('request_id');

    expect($matched)->toHaveCount(1);
    expect((int) $matched->first())->toBe((int) $active->request_id);
});

it('scopeHighPriority matches only High, not Normal', function () {
    $patient = analyticsPatient();
    $high = analyticsRequest($patient, ['priority_level' => 'High']);
    analyticsRequest($patient, ['priority_level' => 'Normal']);

    $matched = Consultation::highPriority()->pluck('request_id');

    expect($matched)->toHaveCount(1);
    expect((int) $matched->first())->toBe((int) $high->request_id);
});

// --- the dead 'assigned' status ----------------------------------------------

it('does not classify the dead assigned status as in-flight or concluded', function () {
    // Never written by any real workflow path, but must not silently fall into
    // either partition if it somehow exists in the data.
    $request = analyticsRequest(analyticsPatient(), ['request_status' => 'assigned']);

    expect(Consultation::inFlight()->whereKey($request->request_id)->exists())->toBeFalse();
    expect(Consultation::concluded()->whereKey($request->request_id)->exists())->toBeFalse();
});

it('excludes the dead assigned status from the meaningful status list', function () {
    expect(Consultation::MEANINGFUL_STATUSES)->not->toContain('assigned');
});
