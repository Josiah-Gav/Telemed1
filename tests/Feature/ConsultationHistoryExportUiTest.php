<?php

use App\Models\Consultation;
use App\Models\User;

/**
 * UI-integration coverage for the export control added to the patient and
 * physician consultation-history pages (resources/views/patient/
 * consultation-history.blade.php, resources/views/physician/
 * consultation_history.blade.php), reusing
 * resources/views/components/dash/export-menu.blade.php's :query-params path
 * — the same component the three dashboards already use via :date-range.
 * The export backend itself is covered under tests/Feature/Export/; this
 * file only proves the control is present, links to the right route with
 * the currently-selected filters, and that existing page content survives.
 *
 * assertSee() is used WITHOUT the `false` (unescaped) flag throughout, for
 * the same reason as DashboardExportUiTest.php: the export links are
 * multi-param query strings rendered through Blade's component attribute
 * bag, which HTML-escapes '&' to '&amp;'.
 */
function historyUiPatient(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'patient', 'user_type' => 'student'], $overrides));
}

function historyUiPhysician(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'physician', 'user_type' => 'staff'], $overrides));
}

function historyUiConsultation(array $overrides = []): Consultation
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

// --- Patient history --------------------------------------------------------

it('renders the export control on the patient consultation history page', function () {
    $patient = historyUiPatient();

    $response = $this->actingAs($patient)->get(route('consultations.history'));

    $response->assertOk();
    $response->assertSee('Export History');
});

it('links the patient history export control to the CSV and PDF routes', function () {
    $patient = historyUiPatient();

    $response = $this->actingAs($patient)->get(route('consultations.history'));

    $response->assertSee(route('consultations.history.export', [
        'date_filter' => 'all', 'status' => 'all', 'consultation_type' => 'all', 'format' => 'csv',
    ]));
    $response->assertSee(route('consultations.history.export', [
        'date_filter' => 'all', 'status' => 'all', 'consultation_type' => 'all', 'format' => 'pdf',
    ]));
});

it('preserves the patient\'s currently selected filters in the export links', function () {
    $patient = historyUiPatient();

    $response = $this->actingAs($patient)
        ->get(route('consultations.history', ['date_filter' => 'last_7_days', 'status' => 'completed', 'consultation_type' => 'follow_up']));

    $response->assertSee(route('consultations.history.export', [
        'date_filter' => 'last_7_days', 'status' => 'completed', 'consultation_type' => 'follow_up', 'format' => 'csv',
    ]));
});

it('still renders existing patient history content alongside the export control', function () {
    $patient = historyUiPatient();
    historyUiConsultation(['patient_id' => $patient->user_id, 'request_status' => 'completed']);

    $response = $this->actingAs($patient)->get(route('consultations.history'));

    $response->assertOk();
    $response->assertSee('Your Consultation History');
    $response->assertSee('New Consultation');
});

// --- Physician history -------------------------------------------------------

it('renders the export control on the physician consultation history page', function () {
    $physician = historyUiPhysician();

    $response = $this->actingAs($physician)
        ->get(route('physician.consultation_history', ['physician' => $physician->user_id]));

    $response->assertOk();
    $response->assertSee('Export History');
});

it('links the physician history export control to the CSV and PDF routes, scoped to that physician', function () {
    $physician = historyUiPhysician();

    $response = $this->actingAs($physician)
        ->get(route('physician.consultation_history', ['physician' => $physician->user_id]));

    $response->assertSee(route('physician.consultation_history.export', [
        'physician' => $physician->user_id, 'date_filter' => 'all', 'status' => 'all', 'consultation_type' => 'all', 'format' => 'csv',
    ]));
    $response->assertSee(route('physician.consultation_history.export', [
        'physician' => $physician->user_id, 'date_filter' => 'all', 'status' => 'all', 'consultation_type' => 'all', 'format' => 'pdf',
    ]));
});

it('preserves the physician\'s currently selected filters, including search, in the export links', function () {
    $physician = historyUiPhysician();

    $response = $this->actingAs($physician)->get(
        route('physician.consultation_history', ['physician' => $physician->user_id])
            .'?date_filter=today&status=rejected&consultation_type=general&search=Mario'
    );

    $response->assertSee(route('physician.consultation_history.export', [
        'physician' => $physician->user_id,
        'date_filter' => 'today', 'status' => 'rejected', 'consultation_type' => 'general', 'search' => 'Mario',
        'format' => 'csv',
    ]));
});

it('omits the search parameter from the physician export links when no search is active', function () {
    $physician = historyUiPhysician();

    $response = $this->actingAs($physician)
        ->get(route('physician.consultation_history', ['physician' => $physician->user_id]));

    $html = $response->getContent();
    $csvLink = route('physician.consultation_history.export', [
        'physician' => $physician->user_id, 'date_filter' => 'all', 'status' => 'all', 'consultation_type' => 'all', 'format' => 'csv',
    ]);

    // The link with no 'search' key must appear; a link carrying an empty
    // search=&... segment must not (array_filter() drops it entirely).
    expect($html)->toContain(e($csvLink))
        ->and($html)->not->toContain('search=&');
});

it('does not point one physician\'s export link at another physician\'s id', function () {
    $physicianA = historyUiPhysician();
    $physicianB = historyUiPhysician();

    $response = $this->actingAs($physicianA)
        ->get(route('physician.consultation_history', ['physician' => $physicianA->user_id]));

    $response->assertDontSee("physicians/{$physicianB->user_id}/consultation-history/export");
});

it('still renders existing physician history content alongside the export control', function () {
    $physician = historyUiPhysician();
    historyUiConsultation([
        'patient_id' => historyUiPatient()->user_id,
        'assigned_physician_id' => $physician->user_id,
        'request_status' => 'completed',
    ]);

    $response = $this->actingAs($physician)
        ->get(route('physician.consultation_history', ['physician' => $physician->user_id]));

    $response->assertOk();
    $response->assertSee('Search Patient or Nurse');
});

// --- No export control where no history page/route exists -------------------

it('does not render a history export control on the nurse dashboard, which has no history export route', function () {
    $nurse = User::factory()->create(['role' => 'nurse', 'user_type' => 'staff']);

    $response = $this->actingAs($nurse)->get(route('nurse.dashboard', ['nurse' => $nurse->user_id]));

    $response->assertOk();
    $response->assertDontSee('Export History');
});

it('confirms no nurse or admin consultation-history export route exists to accidentally link to', function () {
    $routeNames = collect(app('router')->getRoutes())->map(fn ($route) => $route->getName())->filter();

    expect($routeNames)->not->toContain('nurse.consultation_history.export')
        ->and($routeNames)->not->toContain('admin.consultation_history.export');
});
