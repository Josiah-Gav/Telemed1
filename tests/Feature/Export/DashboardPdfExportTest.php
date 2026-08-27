<?php

use App\Models\Consultation;
use App\Models\User;
use App\Services\DashboardAnalyticsService;
use App\Services\Export\DashboardExportRows;
use App\Support\DateRange;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * Phase 4: PDF export of the dashboard analytics, sharing
 * DashboardExportRows::forRole() with the CSV path.
 *
 * Test strategy, deliberately chosen: dompdf Flate-compresses its content
 * streams, so exported text is NOT greppable in the response body, and no
 * PDF parser is installed (adding one purely for tests was ruled out). So
 * this file asserts at three stable levels instead of poking at internal
 * PDF object layout:
 *
 *  1. Real end-to-end generation — status, Content-Type, %PDF magic bytes,
 *     cache headers, authorization. No mocking; the genuine dompdf render
 *     runs, which is what proves the view is dompdf-compatible at all.
 *  2. A Pdf facade spy — proves the controller hands the view exactly the
 *     DashboardExportRows::forRole() sections rather than reconstructing
 *     analytics on its own, and that range/start/end reach the same
 *     DateRange path the CSV export uses.
 *  3. Direct renders of the Blade view — proves the content semantics
 *     (em dash for null, "Not Date-Filtered" wording, suppressed-term
 *     privacy) that the compressed PDF cannot be inspected for. This
 *     mirrors existing precedent in tests/Feature/Analytics
 *     (ChartPayloadEscapingTest renders a component directly).
 */
function pdfPatient(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'patient', 'user_type' => 'student'], $overrides));
}

function pdfNurse(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'nurse', 'user_type' => 'staff'], $overrides));
}

function pdfPhysician(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'physician', 'user_type' => 'staff'], $overrides));
}

function pdfAdmin(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'admin'], $overrides));
}

function pdfRequest(User $patient, array $overrides = []): Consultation
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

/** Renders the export view directly, the way the controller does. */
function renderDashboardPdfView(array $sections): string
{
    return view('exports.dashboard', ['sections' => $sections])->render();
}

/**
 * Replaces the Pdf facade with a chain-compatible double, capturing the
 * data the controller passes to loadView(). Returns a reference holder.
 */
function spyOnPdfFacade(): object
{
    $captured = new stdClass;
    $captured->view = null;
    $captured->data = null;
    $captured->paper = null;
    $captured->filename = null;

    // Must be a real PDF-typed double: Barryvdh\DomPDF\PDF::loadView() and
    // setPaper() both declare a `self` return type, so a generic mock is
    // rejected at the boundary.
    $pdf = Mockery::mock(Barryvdh\DomPDF\PDF::class);
    $pdf->shouldReceive('setPaper')
        ->andReturnUsing(function ($size, $orientation) use ($pdf, $captured) {
            $captured->paper = [$size, $orientation];

            return $pdf;
        });
    $pdf->shouldReceive('download')
        ->andReturnUsing(function ($filename) use ($captured) {
            $captured->filename = $filename;

            return new Response('%PDF-1.7 stub', 200, [
                'Content-Type' => 'application/pdf',
            ]);
        });

    Pdf::shouldReceive('loadView')
        ->once()
        ->andReturnUsing(function ($view, $data) use ($pdf, $captured) {
            $captured->view = $view;
            $captured->data = $data;

            return $pdf;
        });

    return $captured;
}

// --- Real end-to-end generation: status, type, magic bytes -----------------

it('returns 200 for a nurse PDF export', function () {
    $nurse = pdfNurse();

    $this->actingAs($nurse)
        ->get(route('nurse.dashboard.export', ['nurse' => $nurse->user_id]).'?format=pdf')
        ->assertOk();
});

it('returns 200 for a physician PDF export', function () {
    $physician = pdfPhysician();

    $this->actingAs($physician)
        ->get(route('physician.dashboard.export', ['physician' => $physician->user_id]).'?format=pdf')
        ->assertOk();
});

it('returns 200 for an admin PDF export', function () {
    $admin = pdfAdmin();

    $this->actingAs($admin)
        ->get(route('admin.dashboard.export').'?format=pdf')
        ->assertOk();
});

it('responds with an application/pdf content type', function () {
    $nurse = pdfNurse();

    $response = $this->actingAs($nurse)
        ->get(route('nurse.dashboard.export', ['nurse' => $nurse->user_id]).'?format=pdf');

    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});

it('returns a body beginning with the PDF magic bytes', function () {
    $admin = pdfAdmin();
    pdfRequest(pdfPatient(), ['request_status' => 'completed']);

    $response = $this->actingAs($admin)->get(route('admin.dashboard.export').'?format=pdf');

    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

it('sends no-store cache-prevention headers on the PDF response', function () {
    $nurse = pdfNurse();

    $response = $this->actingAs($nurse)
        ->get(route('nurse.dashboard.export', ['nurse' => $nurse->user_id]).'?format=pdf');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Pragma'))->toBe('no-cache')
        ->and($response->headers->get('Expires'))->toBe('0');
});

it('names the PDF file server-side and never from request input', function () {
    // Filename convention changed to "<Role> <Full Name> <Timeline> Report" —
    // asserted against the new convention's fixed pieces (role + "Report"),
    // not the exact name, since the name is a randomly-generated factory value.
    $nurse = pdfNurse();

    $response = $this->actingAs($nurse)->get(
        route('nurse.dashboard.export', ['nurse' => $nurse->user_id]).'?format=pdf&filename=evil.pdf'
    );

    $disposition = $response->headers->get('Content-Disposition');

    expect($disposition)->toContain('Nurse')
        ->and($disposition)->toContain('Report')
        ->and($disposition)->toContain('.pdf')
        ->and($disposition)->not->toContain('evil.pdf');
});

it('produces a valid PDF when there is no analytics data at all', function () {
    $admin = pdfAdmin();

    $response = $this->actingAs($admin)->get(route('admin.dashboard.export').'?format=pdf');

    $response->assertOk();
    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-')
        ->and(strlen($response->getContent()))->toBeGreaterThan(1000);
});

it('produces a valid PDF for an admin with symptom data present', function () {
    $admin = pdfAdmin();
    pdfRequest(pdfPatient(), ['symptoms_desc' => [['name' => 'Headache', 'severity' => 4]]]);
    pdfRequest(pdfPatient(), ['symptoms_desc' => [['name' => 'Fever', 'severity' => 2]]]);

    $response = $this->actingAs($admin)->get(route('admin.dashboard.export').'?format=pdf');

    $response->assertOk();
    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

// --- Authorization: identical to the CSV path ------------------------------

it('refuses a nurse requesting another nurse\'s PDF export', function () {
    $nurseA = pdfNurse();
    $nurseB = pdfNurse();

    $this->actingAs($nurseA)
        ->get(route('nurse.dashboard.export', ['nurse' => $nurseB->user_id]).'?format=pdf')
        ->assertForbidden();
});

it('refuses a physician requesting another physician\'s PDF export', function () {
    $physicianA = pdfPhysician();
    $physicianB = pdfPhysician();

    $this->actingAs($physicianA)
        ->get(route('physician.dashboard.export', ['physician' => $physicianB->user_id]).'?format=pdf')
        ->assertForbidden();
});

it('refuses a physician hitting the nurse PDF export route', function () {
    $physician = pdfPhysician();

    $this->actingAs($physician)
        ->get(route('nurse.dashboard.export', ['nurse' => $physician->user_id]).'?format=pdf')
        ->assertForbidden();
});

it('refuses a nurse hitting the physician PDF export route', function () {
    $nurse = pdfNurse();
    $physician = pdfPhysician();

    $this->actingAs($nurse)
        ->get(route('physician.dashboard.export', ['physician' => $physician->user_id]).'?format=pdf')
        ->assertForbidden();
});

it('refuses a non-admin hitting the admin PDF export route', function () {
    $physician = pdfPhysician();

    $this->actingAs($physician)
        ->get(route('admin.dashboard.export').'?format=pdf')
        ->assertForbidden();
});

it('refuses a patient hitting any dashboard PDF export route', function () {
    $patient = pdfPatient();

    $this->actingAs($patient)
        ->get(route('admin.dashboard.export').'?format=pdf')
        ->assertForbidden();
});

// --- Format contract preserved from Phase 3 --------------------------------

it('still defaults to CSV when format is omitted', function () {
    $nurse = pdfNurse();

    $response = $this->actingAs($nurse)
        ->get(route('nurse.dashboard.export', ['nurse' => $nurse->user_id]));

    expect($response->headers->get('Content-Type'))->toContain('text/csv');
});

it('still returns CSV for an explicit format=csv', function () {
    $nurse = pdfNurse();

    $response = $this->actingAs($nurse)
        ->get(route('nurse.dashboard.export', ['nurse' => $nurse->user_id]).'?format=csv');

    expect($response->headers->get('Content-Type'))->toContain('text/csv');
});

it('still rejects an unsupported format with 422', function () {
    $nurse = pdfNurse();

    $this->actingAs($nurse)
        ->get(route('nurse.dashboard.export', ['nurse' => $nurse->user_id]).'?format=xlsx')
        ->assertStatus(422);
});

// --- Wiring: the PDF path consumes DashboardExportRows, not its own math ---

it('passes the exact DashboardExportRows sections to the PDF view rather than rebuilding analytics', function () {
    $physician = pdfPhysician();
    pdfRequest(pdfPatient(), ['request_status' => 'completed', 'assigned_physician_id' => $physician->user_id]);

    // Phase "Generated By": forRole() now takes the exporting user's
    // canonical name as its 4th argument. Matched here to the same value
    // the controller derives from Auth::user(), since $physician IS that
    // authenticated user — the exact-equality assertion below requires it.
    $physicianDateRange = DateRange::fromInput(null, null, null, 'this_month');
    $expectedSections = DashboardExportRows::forRole(
        'physician',
        app(DashboardAnalyticsService::class)->forPhysician($physician, $physicianDateRange),
        now(),
        trim($physician->first_name.' '.$physician->last_name),
        DashboardExportRows::timelineLabel($physicianDateRange->preset, $physicianDateRange->start->toDateString(), $physicianDateRange->end->toDateString())
    );

    $captured = spyOnPdfFacade();

    $this->actingAs($physician)
        ->get(route('physician.dashboard.export', ['physician' => $physician->user_id]).'?format=pdf')
        ->assertOk();

    expect($captured->view)->toBe('exports.dashboard')
        ->and(array_keys($captured->data))->toBe(['sections'])
        ->and($captured->data['sections'])->toBe($expectedSections);
});

it('renders the PDF as A4 portrait', function () {
    $nurse = pdfNurse();
    $captured = spyOnPdfFacade();

    $this->actingAs($nurse)
        ->get(route('nurse.dashboard.export', ['nurse' => $nurse->user_id]).'?format=pdf')
        ->assertOk();

    expect($captured->paper)->toBe(['a4', 'portrait']);
});

it('honors range/start/end on the PDF path exactly as the CSV path does', function () {
    $admin = pdfAdmin();

    // See the equivalent comment in the physician test above.
    $adminDateRange = DateRange::fromInput('custom', '2026-08-01', '2026-08-10');
    $expectedSections = DashboardExportRows::forRole(
        'admin',
        app(DashboardAnalyticsService::class)->forAdmin($adminDateRange),
        now(),
        trim($admin->first_name.' '.$admin->last_name),
        DashboardExportRows::timelineLabel($adminDateRange->preset, $adminDateRange->start->toDateString(), $adminDateRange->end->toDateString())
    );

    $captured = spyOnPdfFacade();

    $this->actingAs($admin)
        ->get(route('admin.dashboard.export').'?format=pdf&range=custom&start=2026-08-01&end=2026-08-10')
        ->assertOk();

    // The meta section carries the resolved range; comparing whole sections
    // proves the same DateRange reached the same analytics call.
    expect($captured->data['sections'])->toBe($expectedSections);
});

it('uses the physician this_month default on the PDF path, not the nurse default', function () {
    $physician = pdfPhysician();
    $captured = spyOnPdfFacade();

    $this->actingAs($physician)
        ->get(route('physician.dashboard.export', ['physician' => $physician->user_id]).'?format=pdf')
        ->assertOk();

    $metaRows = $captured->data['sections'][0]['rows'];
    $rangeRow = collect($metaRows)->firstWhere(0, 'Range');

    expect($rangeRow[1])->toBe(DateRange::fromInput(null, null, null, 'this_month')->preset);
});

// --- Rendered content semantics (view-level, since PDF text is compressed) --

it('renders every section returned by DashboardExportRows without silently omitting any', function () {
    $sections = DashboardExportRows::forRole(
        'admin',
        app(DashboardAnalyticsService::class)->forAdmin(DateRange::fromInput('this_year', null, null)),
        now()
    );

    $html = renderDashboardPdfView($sections);

    foreach ($sections as $section) {
        expect($html)->toContain(e($section['title']));
    }
});

it('keeps the Not Date-Filtered wording visible in the operational section', function () {
    $sections = DashboardExportRows::forRole(
        'nurse',
        app(DashboardAnalyticsService::class)->forNurse(pdfNurse(), DateRange::fromInput('this_year', null, null)),
        now()
    );

    $html = renderDashboardPdfView($sections);

    expect($html)->toContain('Not Date-Filtered');
});

it('renders a null completion rate as an em dash and never as zero', function () {
    $physician = pdfPhysician();
    // Only an in-flight request: nothing concluded, so rate is null.
    pdfRequest(pdfPatient(), ['request_status' => 'pending', 'assigned_physician_id' => $physician->user_id]);

    $analytics = app(DashboardAnalyticsService::class)
        ->forPhysician($physician, DateRange::fromInput('this_year', null, null));

    expect($analytics['period']['completion_rate']['rate'])->toBeNull();

    $sections = DashboardExportRows::forRole('physician', $analytics, now());
    $html = renderDashboardPdfView($sections);

    // Locate the Completion Rate — Rate row and confirm its value cell.
    expect($html)->toMatch('/Completion Rate — Rate<\/td>\s*<td[^>]*>—<\/td>/u')
        ->and($html)->not->toMatch('/Completion Rate — Rate<\/td>\s*<td[^>]*>0<\/td>/u');
});

it('renders a null custom symptom percentage as an em dash and never as zero', function () {
    // No requests at all: percentage is null, not 0.
    $analytics = app(DashboardAnalyticsService::class)
        ->forAdmin(DateRange::fromInput('this_year', null, null));

    expect($analytics['symptoms']['custom']['percentage'])->toBeNull();

    $sections = DashboardExportRows::forRole('admin', $analytics, now());
    $html = renderDashboardPdfView($sections);

    expect($html)->toMatch('/Percentage<\/td>\s*<td[^>]*>—<\/td>/u')
        ->and($html)->not->toMatch('/Percentage<\/td>\s*<td[^>]*>0<\/td>/u');
});

it('includes suppressed_terms_count in the admin PDF content', function () {
    // Two reports of an unrecognized term — below the k=3 floor.
    pdfRequest(pdfPatient(), ['symptoms_desc' => [['name' => 'Mystery Ache']]]);
    pdfRequest(pdfPatient(), ['symptoms_desc' => [['name' => 'Mystery Ache']]]);

    $analytics = app(DashboardAnalyticsService::class)
        ->forAdmin(DateRange::fromInput('this_year', null, null));

    expect($analytics['symptoms']['custom']['suppressed_terms_count'])->toBe(1);

    $sections = DashboardExportRows::forRole('admin', $analytics, now());
    $html = renderDashboardPdfView($sections);

    expect($html)->toContain('Suppressed Terms Count')
        ->and($html)->toMatch('/Suppressed Terms Count<\/td>\s*<td[^>]*>1<\/td>/u');
});

it('never exposes a suppressed symptom term in the admin PDF content', function () {
    pdfRequest(pdfPatient(), ['symptoms_desc' => [['name' => 'Mystery Ache']]]);
    pdfRequest(pdfPatient(), ['symptoms_desc' => [['name' => 'Mystery Ache']]]);

    $analytics = app(DashboardAnalyticsService::class)
        ->forAdmin(DateRange::fromInput('this_year', null, null));

    $sections = DashboardExportRows::forRole('admin', $analytics, now());
    $html = renderDashboardPdfView($sections);

    expect($html)->not->toContain('Mystery Ache');
});

it('surfaces a custom term only once it clears the k=3 threshold', function () {
    pdfRequest(pdfPatient(), ['symptoms_desc' => [['name' => 'Mystery Ache']]]);
    pdfRequest(pdfPatient(), ['symptoms_desc' => [['name' => 'Mystery Ache']]]);
    pdfRequest(pdfPatient(), ['symptoms_desc' => [['name' => 'Mystery Ache']]]);

    $analytics = app(DashboardAnalyticsService::class)
        ->forAdmin(DateRange::fromInput('this_year', null, null));

    $sections = DashboardExportRows::forRole('admin', $analytics, now());
    $html = renderDashboardPdfView($sections);

    expect($html)->toContain('Mystery Ache')
        ->and($analytics['symptoms']['custom']['suppressed_terms_count'])->toBe(0);
});

it('escapes a symptom name containing HTML rather than injecting markup into the PDF view', function () {
    $malicious = '<script>alert(1)</script>';
    pdfRequest(pdfPatient(), ['symptoms_desc' => [['name' => $malicious]]]);
    pdfRequest(pdfPatient(), ['symptoms_desc' => [['name' => $malicious]]]);
    pdfRequest(pdfPatient(), ['symptoms_desc' => [['name' => $malicious]]]);

    $analytics = app(DashboardAnalyticsService::class)
        ->forAdmin(DateRange::fromInput('this_year', null, null));

    $sections = DashboardExportRows::forRole('admin', $analytics, now());
    $html = renderDashboardPdfView($sections);

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;');
});

it('renders a chart section as a table of the same labels and values the service produced', function () {
    pdfRequest(pdfPatient(), ['request_status' => 'completed']);

    $analytics = app(DashboardAnalyticsService::class)
        ->forAdmin(DateRange::fromInput('today', null, null));

    $sections = DashboardExportRows::forRole('admin', $analytics, now());
    $html = renderDashboardPdfView($sections);

    $volume = $analytics['charts']['volume_over_time'];

    expect($html)->toContain('Requests Over Time')
        ->and($html)->toMatch('/'.preg_quote($volume['labels'][0], '/').'<\/td>\s*<td[^>]*>'.$volume['datasets'][0]['data'][0].'<\/td>/u');
});

it('contains no Tailwind, Alpine, JavaScript, flexbox or grid in the rendered PDF view', function () {
    // Asserts on the RENDERED output, not the Blade source: the source
    // carries an explanatory comment naming the very constructs being
    // excluded (x-app-layout, Chart.js), which a raw source scan would
    // false-positive on. Blade comments are stripped from the render, so
    // this checks what dompdf actually receives.
    $sections = DashboardExportRows::forRole(
        'nurse',
        app(DashboardAnalyticsService::class)->forNurse(pdfNurse(), DateRange::fromInput('this_year', null, null)),
        now()
    );

    $html = renderDashboardPdfView($sections);

    expect($html)->not->toContain('<x-app-layout')
        ->and($html)->not->toContain('<script')
        ->and($html)->not->toContain('x-data')
        ->and($html)->not->toContain('display: flex')
        ->and($html)->not->toContain('display:flex')
        ->and($html)->not->toContain('display: grid')
        ->and($html)->not->toContain('display:grid')
        ->and($html)->not->toContain('@vite')
        ->and($html)->not->toContain('cdn.')
        ->and($html)->not->toContain('http://')
        ->and($html)->not->toContain('https://');
});

// =====================================================================
// Generated By — the authenticated exporting user's canonical name
// =====================================================================

it('renders Generated By with the authenticated nurse\'s canonical name in the PDF', function () {
    $nurse = pdfNurse(['first_name' => 'Wren', 'last_name' => 'Okafor']);

    $sections = DashboardExportRows::forRole(
        'nurse',
        app(DashboardAnalyticsService::class)->forNurse($nurse, DateRange::fromInput('this_year', null, null)),
        now(),
        'Wren Okafor'
    );

    $html = renderDashboardPdfView($sections);

    expect($html)->toContain('Generated By')
        ->and($html)->toContain('Wren Okafor');
});

it('carries the real authenticated user\'s name into the PDF via the actual HTTP export endpoint', function () {
    $physician = pdfPhysician(['first_name' => 'Idris', 'last_name' => 'Falade']);
    $captured = spyOnPdfFacade();

    $this->actingAs($physician)
        ->get(route('physician.dashboard.export', ['physician' => $physician->user_id]).'?format=pdf')
        ->assertOk();

    $metaRows = $captured->data['sections'][0]['rows'];
    $generatedByRow = collect($metaRows)->firstWhere(0, 'Generated By');

    expect($generatedByRow[1])->toBe('Idris Falade');
});

it('ignores a forged generated_by query parameter on the PDF export endpoint', function () {
    $nurse = pdfNurse(['first_name' => 'Real', 'last_name' => 'Nurse']);
    $captured = spyOnPdfFacade();

    $this->actingAs($nurse)
        ->get(route('nurse.dashboard.export', ['nurse' => $nurse->user_id]).'?format=pdf&generated_by=Attacker')
        ->assertOk();

    $metaRows = $captured->data['sections'][0]['rows'];
    $generatedByRow = collect($metaRows)->firstWhere(0, 'Generated By');

    expect($generatedByRow[1])->toBe('Real Nurse')
        ->and(collect($metaRows)->flatten()->implode(' '))->not->toContain('Attacker');
});

it('does not change existing dashboard PDF metadata rows or their values', function () {
    $sections = DashboardExportRows::forRole(
        'admin',
        app(DashboardAnalyticsService::class)->forAdmin(DateRange::fromInput('this_year', null, null)),
        now(),
        'Priya Vance'
    );

    // The pre-existing meta rows must still all be present — Generated By
    // is an addition, not a replacement of any of these.
    $metaRows = $sections[0]['rows'];
    $labels = collect($metaRows)->pluck(0)->all();

    expect($labels)->toContain('Role')
        ->toContain('Generated By')
        ->toContain('Range')
        ->toContain('Range Start')
        ->toContain('Range End')
        ->toContain('Generated');
});

// =====================================================================
// Filename/title convention: "<Role> <Full Name> <Timeline> Report"
// =====================================================================

it('uses the "Role Full-Name Timeline Report" PDF filename convention for a physician', function () {
    $physician = pdfPhysician(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

    $response = $this->actingAs($physician)->get(
        route('physician.dashboard.export', ['physician' => $physician->user_id]).'?format=pdf&range=last_30_days'
    );

    expect($response->headers->get('Content-Disposition'))
        ->toContain('Physician Juan Dela Cruz Last 30 Days Report.pdf');
});

it('produces a readable, filesystem-safe PDF filename for a custom date range', function () {
    $physician = pdfPhysician(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

    $response = $this->actingAs($physician)->get(
        route('physician.dashboard.export', ['physician' => $physician->user_id])
            .'?format=pdf&range=custom&start=2026-08-01&end=2026-08-27'
    );

    $disposition = $response->headers->get('Content-Disposition');

    expect($disposition)->toContain('Physician Juan Dela Cruz Aug 01 2026 - Aug 27 2026 Report.pdf');

    preg_match('/filename="?([^"]+\.pdf)"?/', $disposition, $m);
    $filename = $m[1];

    foreach (['/', ':', '\\', '*', '?', '<', '>', '|'] as $forbidden) {
        expect($filename)->not->toContain($forbidden);
    }
});

it('uses the identical identity/timeline convention for CSV and PDF filenames', function () {
    $physician = pdfPhysician(['first_name' => 'Maria', 'last_name' => 'Santos']);

    $csvResponse = $this->actingAs($physician)->get(
        route('physician.dashboard.export', ['physician' => $physician->user_id]).'?format=csv&range=this_month'
    );
    $pdfResponse = $this->actingAs($physician)->get(
        route('physician.dashboard.export', ['physician' => $physician->user_id]).'?format=pdf&range=this_month'
    );

    $csvBase = str_replace('.csv', '', $csvResponse->headers->get('Content-Disposition'));
    $pdfBase = str_replace('.pdf', '', $pdfResponse->headers->get('Content-Disposition'));

    expect($csvBase)->toBe($pdfBase);
});

it('sets the visible PDF report title to match the filename convention', function () {
    $physician = pdfPhysician(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

    $sections = DashboardExportRows::forRole(
        'physician',
        app(DashboardAnalyticsService::class)->forPhysician($physician, DateRange::fromInput(null, null, null, 'last_30_days')),
        now(),
        'Juan Dela Cruz',
        DashboardExportRows::timelineLabel('last_30_days', '', '')
    );

    $html = renderDashboardPdfView($sections);

    expect($html)->toContain('<h1>Physician Juan Dela Cruz Last 30 Days Report</h1>')
        ->and($html)->toContain('<title>Physician Juan Dela Cruz Last 30 Days Report</title>');
});

it('cannot have its PDF filename identity manipulated by forged role/name query parameters', function () {
    $nurse = pdfNurse(['first_name' => 'Real', 'last_name' => 'Nurse']);

    $response = $this->actingAs($nurse)->get(
        route('nurse.dashboard.export', ['nurse' => $nurse->user_id])
            .'?format=pdf&role=admin&name=SomeoneElse&generated_by=Attacker'
    );

    $disposition = $response->headers->get('Content-Disposition');

    expect($disposition)->toContain('Nurse Real Nurse')
        ->and($disposition)->not->toContain('SomeoneElse')
        ->and($disposition)->not->toContain('Attacker');
});
