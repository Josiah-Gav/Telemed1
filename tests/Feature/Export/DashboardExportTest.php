<?php

use App\Models\Consultation;
use App\Models\User;
use App\Services\DashboardAnalyticsService;
use App\Support\DateRange;
use Illuminate\Testing\TestResponse;

/**
 * Phase 3 of the export feature: CSV export of the same analytics each
 * dashboard already renders (DashboardAnalyticsService, unchanged). These
 * tests hit the real HTTP endpoints and parse the actual streamed CSV bytes
 * — they compare the exported values against what the service itself
 * returns, rather than re-deriving expected numbers from raw consultation
 * rows, so the analytics calculation is never duplicated here.
 */
function exportPatient(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'patient', 'user_type' => 'student'], $overrides));
}

function exportNurse(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'nurse', 'user_type' => 'staff'], $overrides));
}

function exportPhysician(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'physician', 'user_type' => 'staff'], $overrides));
}

function exportAdmin(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'admin'], $overrides));
}

function exportRequest(User $patient, array $overrides = []): Consultation
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

/**
 * Parses a streamed CSV TestResponse body into rows, stripping the BOM
 * first — mirrors CsvDownloadTest's own parsing helper.
 *
 * @return list<list<string|null>>
 */
function parseExportCsv(TestResponse $response): array
{
    $body = $response->streamedContent();

    if (str_starts_with($body, "\xEF\xBB\xBF")) {
        $body = substr($body, 3);
    }

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $body);
    rewind($stream);

    $rows = [];
    while (($row = fgetcsv($stream)) !== false) {
        $rows[] = $row;
    }
    fclose($stream);

    return $rows;
}

/** Finds the first row whose first cell exactly matches $label, or null. */
function findCsvRow(array $rows, string $label): ?array
{
    foreach ($rows as $row) {
        if (($row[0] ?? null) === $label) {
            return $row;
        }
    }

    return null;
}

// --- Nurse ---------------------------------------------------------------

it('lets a nurse export their own dashboard as CSV', function () {
    $nurse = exportNurse();
    $patient = exportPatient();
    exportRequest($patient, ['request_status' => 'pending', 'assigned_nurse_id' => null]);

    $response = $this->actingAs($nurse)
        ->get(route('nurse.dashboard.export', ['nurse' => $nurse->user_id]));

    $response->assertOk();
});

it('refuses a physician hitting the nurse export route', function () {
    $physician = exportPhysician();

    $this->actingAs($physician)
        ->get(route('nurse.dashboard.export', ['nurse' => $physician->user_id]))
        ->assertForbidden();
});

it('refuses a nurse exporting another nurse\'s dashboard', function () {
    $nurseA = exportNurse();
    $nurseB = exportNurse();

    $this->actingAs($nurseA)
        ->get(route('nurse.dashboard.export', ['nurse' => $nurseB->user_id]))
        ->assertForbidden();
});

it('matches the nurse export operational metrics to what DashboardAnalyticsService returns', function () {
    $nurse = exportNurse();
    $patient = exportPatient();
    exportRequest($patient, ['request_status' => 'pending', 'assigned_nurse_id' => null]);
    exportRequest($patient, ['request_status' => 'reviewed', 'assigned_nurse_id' => $nurse->user_id]);

    $expected = app(DashboardAnalyticsService::class)
        ->forNurse($nurse, DateRange::fromInput(null, null, null, 'last_30_days'));

    $response = $this->actingAs($nurse)
        ->get(route('nurse.dashboard.export', ['nurse' => $nurse->user_id]));

    $rows = parseExportCsv($response);

    expect(findCsvRow($rows, 'Unclaimed Pending')[1])->toBe((string) $expected['operational']['unclaimed_pending'])
        ->and(findCsvRow($rows, 'My Open Cases — Total')[1])->toBe((string) $expected['operational']['my_open_cases']['total']);
});

// --- Physician -------------------------------------------------------------

it('lets a physician export their own dashboard as CSV', function () {
    $physician = exportPhysician();

    $response = $this->actingAs($physician)
        ->get(route('physician.dashboard.export', ['physician' => $physician->user_id]));

    $response->assertOk();
});

it('refuses another physician\'s route parameter', function () {
    $physicianA = exportPhysician();
    $physicianB = exportPhysician();

    $this->actingAs($physicianA)
        ->get(route('physician.dashboard.export', ['physician' => $physicianB->user_id]))
        ->assertForbidden();
});

it('refuses a nurse hitting the physician export route', function () {
    $nurse = exportNurse();
    $physician = exportPhysician();

    $this->actingAs($nurse)
        ->get(route('physician.dashboard.export', ['physician' => $physician->user_id]))
        ->assertForbidden();
});

it('exports the physician completion_rate null as an em dash, never as 0', function () {
    $physician = exportPhysician();
    $patient = exportPatient();
    exportRequest($patient, ['request_status' => 'pending', 'assigned_physician_id' => $physician->user_id]);

    $response = $this->actingAs($physician)
        ->get(route('physician.dashboard.export', ['physician' => $physician->user_id]));

    $rows = parseExportCsv($response);
    $rateRow = findCsvRow($rows, 'Completion Rate — Rate');

    expect($rateRow[1])->toBe('—')
        ->and($rateRow[1])->not->toBe('0')
        ->and($rateRow[1])->not->toBe('0%');
});

it('exports a numeric physician completion_rate with a percent sign, matching the service value', function () {
    $physician = exportPhysician();
    $patient = exportPatient();
    exportRequest($patient, ['request_status' => 'completed', 'assigned_physician_id' => $physician->user_id]);
    exportRequest($patient, ['request_status' => 'rejected', 'assigned_physician_id' => $physician->user_id]);

    $expected = app(DashboardAnalyticsService::class)
        ->forPhysician($physician, DateRange::fromInput(null, null, null, 'this_month'));

    $response = $this->actingAs($physician)
        ->get(route('physician.dashboard.export', ['physician' => $physician->user_id]));

    $rows = parseExportCsv($response);
    $rateRow = findCsvRow($rows, 'Completion Rate — Rate');

    expect($rateRow[1])->toBe($expected['period']['completion_rate']['rate'].'%');
});

it('uses the same DateRange default the physician dashboard uses (this_month), not the nurse default', function () {
    $physician = exportPhysician();

    $response = $this->actingAs($physician)
        ->get(route('physician.dashboard.export', ['physician' => $physician->user_id]));

    $rows = parseExportCsv($response);
    $expectedPreset = DateRange::fromInput(null, null, null, 'this_month')->preset;

    expect(findCsvRow($rows, 'Range')[1])->toBe($expectedPreset);
});

it('honors range/start/end query parameters exactly as the physician dashboard does', function () {
    $physician = exportPhysician();

    $response = $this->actingAs($physician)->get(
        route('physician.dashboard.export', ['physician' => $physician->user_id])
        .'?range=custom&start=2026-08-01&end=2026-08-10'
    );

    $rows = parseExportCsv($response);

    expect(findCsvRow($rows, 'Range')[1])->toBe('custom')
        ->and(findCsvRow($rows, 'Range Start')[1])->toBe('2026-08-01')
        ->and(findCsvRow($rows, 'Range End')[1])->toBe('2026-08-10');
});

// --- Admin -------------------------------------------------------------

it('lets an admin export the system-wide dashboard as CSV', function () {
    $admin = exportAdmin();

    $response = $this->actingAs($admin)->get(route('admin.dashboard.export'));

    $response->assertOk();
});

it('refuses a non-admin hitting the admin export route', function () {
    $nurse = exportNurse();

    $this->actingAs($nurse)
        ->get(route('admin.dashboard.export'))
        ->assertForbidden();
});

it('includes admin symptom analytics with suppressed_terms_count in the export', function () {
    $admin = exportAdmin();
    $patient = exportPatient();
    // Two distinct patients reporting the same unrecognized term, both
    // below the k=3 threshold — must be counted as suppressed, not listed.
    exportRequest($patient, ['symptoms_desc' => [['name' => 'Weird Ache']]]);
    exportRequest(exportPatient(), ['symptoms_desc' => [['name' => 'Weird Ache']]]);

    $expected = app(DashboardAnalyticsService::class)
        ->forAdmin(DateRange::fromInput(null, null, null, 'last_30_days'));

    $response = $this->actingAs($admin)->get(route('admin.dashboard.export'));
    $rows = parseExportCsv($response);

    $suppressedRow = findCsvRow($rows, 'Suppressed Terms Count');

    expect($suppressedRow)->not->toBeNull()
        ->and($suppressedRow[1])->toBe((string) $expected['symptoms']['custom']['suppressed_terms_count'])
        ->and($suppressedRow[1])->toBe('1');

    // The suppressed term itself must not leak into the exported terms table.
    expect(findCsvRow($rows, 'Weird Ache'))->toBeNull();
});

it('includes admin standardized symptom rows matching the service output', function () {
    $admin = exportAdmin();
    $patient = exportPatient();
    exportRequest($patient, ['symptoms_desc' => [['name' => 'Headache', 'severity' => 3]]]);

    $response = $this->actingAs($admin)->get(route('admin.dashboard.export'));
    $rows = parseExportCsv($response);

    $headacheRow = findCsvRow($rows, 'Headache');

    expect($headacheRow)->not->toBeNull()
        ->and($headacheRow[1])->toBe('1');
});

// --- Cross-cutting: sections, headers, empty data, no leakage ---------------

it('explicitly labels the operational section as current-state, not date-filtered', function () {
    $nurse = exportNurse();

    $response = $this->actingAs($nurse)
        ->get(route('nurse.dashboard.export', ['nurse' => $nurse->user_id]));

    $body = $response->streamedContent();

    expect($body)->toContain('Operational — Current State (Not Date-Filtered)');
});

it('labels the period section with the resolved date range', function () {
    $physician = exportPhysician();

    $response = $this->actingAs($physician)->get(
        route('physician.dashboard.export', ['physician' => $physician->user_id])
        .'?range=custom&start=2026-08-01&end=2026-08-10'
    );

    $body = $response->streamedContent();

    expect($body)->toContain('Period — 2026-08-01 to 2026-08-10');
});

it('flattens the volume-over-time chart into Date/Requests rows matching the service series', function () {
    $admin = exportAdmin();
    $patient = exportPatient();
    exportRequest($patient);

    $expected = app(DashboardAnalyticsService::class)
        ->forAdmin(DateRange::fromInput('today', null, null))['charts']['volume_over_time'];

    $response = $this->actingAs($admin)
        ->get(route('admin.dashboard.export').'?range=today');

    $rows = parseExportCsv($response);
    $headerIndex = null;
    foreach ($rows as $i => $row) {
        if ($row === ['Date', 'Requests']) {
            $headerIndex = $i;
            break;
        }
    }

    expect($headerIndex)->not->toBeNull();
    expect($rows[$headerIndex + 1])->toBe([$expected['labels'][0], (string) $expected['datasets'][0]['data'][0]]);
});

it('produces a well-formed CSV with no server error when a dashboard has no data at all', function () {
    $admin = exportAdmin();

    $response = $this->actingAs($admin)->get(route('admin.dashboard.export'));

    $response->assertOk();
    $rows = parseExportCsv($response);

    expect($rows)->not->toBeEmpty()
        ->and(findCsvRow($rows, 'Valid Requests')[1])->toBe('0');
});

it('rejects an unsupported export format explicitly rather than silently falling back', function () {
    $nurse = exportNurse();

    // Phase 3 asserted format=pdf here, when PDF was not yet implemented.
    // Phase 4 added PDF, so this now uses a format that is genuinely
    // unsupported — the assertion's intent (unknown format must 422 rather
    // than quietly degrading to CSV) is unchanged.
    $this->actingAs($nurse)
        ->get(route('nurse.dashboard.export', ['nurse' => $nurse->user_id]).'?format=xlsx')
        ->assertStatus(422);
});

it('accepts an explicit format=csv the same as the default', function () {
    $nurse = exportNurse();

    $this->actingAs($nurse)
        ->get(route('nurse.dashboard.export', ['nurse' => $nurse->user_id]).'?format=csv')
        ->assertOk();
});

it('responds with a CSV content type', function () {
    $nurse = exportNurse();

    $response = $this->actingAs($nurse)
        ->get(route('nurse.dashboard.export', ['nurse' => $nurse->user_id]));

    expect($response->headers->get('Content-Type'))->toContain('text/csv');
});

it('prefixes the export body with a UTF-8 BOM, inherited from CsvDownload', function () {
    $nurse = exportNurse();

    $response = $this->actingAs($nurse)
        ->get(route('nurse.dashboard.export', ['nurse' => $nurse->user_id]));

    expect(substr($response->streamedContent(), 0, 3))->toBe("\xEF\xBB\xBF");
});

it('neutralizes a formula-injection attempt in a custom symptom name, inherited from CsvDownload', function () {
    $admin = exportAdmin();
    $malicious = '=cmd|\'/C calc\'!A0';
    // Three distinct patients so the term clears the k=3 suppression floor
    // and actually appears in the exported terms table.
    exportRequest(exportPatient(), ['symptoms_desc' => [['name' => $malicious]]]);
    exportRequest(exportPatient(), ['symptoms_desc' => [['name' => $malicious]]]);
    exportRequest(exportPatient(), ['symptoms_desc' => [['name' => $malicious]]]);

    $response = $this->actingAs($admin)->get(route('admin.dashboard.export'));
    $rows = parseExportCsv($response);

    $row = findCsvRow($rows, "'".$malicious);

    expect($row)->not->toBeNull('The malicious symptom name should appear quote-prefixed as a single cell.')
        ->and($row[1])->toBe('3');
});

it('generates the filename server-side rather than from request input', function () {
    // Filename convention changed to "<Role> <Full Name> <Timeline> Report" —
    // asserted against the new convention's fixed pieces (role + "Report"),
    // not the exact name, since the name is a randomly-generated factory value.
    $nurse = exportNurse();

    $response = $this->actingAs($nurse)->get(
        route('nurse.dashboard.export', ['nurse' => $nurse->user_id]).'?filename=evil.csv'
    );

    $disposition = $response->headers->get('Content-Disposition');

    expect($disposition)->toContain('Nurse')
        ->and($disposition)->toContain('Report')
        ->and($disposition)->not->toContain('evil.csv');
});

it('does not leak another nurse\'s unclaimed-queue-unrelated personal counts into this nurse\'s export', function () {
    $nurseA = exportNurse();
    $nurseB = exportNurse();
    $patient = exportPatient();
    exportRequest($patient, ['request_status' => 'reviewed', 'assigned_nurse_id' => $nurseB->user_id]);

    $response = $this->actingAs($nurseA)
        ->get(route('nurse.dashboard.export', ['nurse' => $nurseA->user_id]));

    $rows = parseExportCsv($response);

    expect(findCsvRow($rows, 'My Open Cases — Total')[1])->toBe('0');
});

it('does not leak another physician\'s consultations into this physician\'s export', function () {
    $physicianA = exportPhysician();
    $physicianB = exportPhysician();
    $patient = exportPatient();
    exportRequest($patient, ['request_status' => 'completed', 'assigned_physician_id' => $physicianB->user_id]);

    $response = $this->actingAs($physicianA)
        ->get(route('physician.dashboard.export', ['physician' => $physicianA->user_id]));

    $rows = parseExportCsv($response);

    expect(findCsvRow($rows, 'Completed')[1])->toBe('0');
});

// =====================================================================
// Generated By — the authenticated exporting user's canonical name
// =====================================================================

it('includes the authenticated nurse\'s canonical name as Generated By in the CSV', function () {
    $nurse = exportNurse(['first_name' => 'Wren', 'last_name' => 'Okafor']);

    $response = $this->actingAs($nurse)
        ->get(route('nurse.dashboard.export', ['nurse' => $nurse->user_id]));

    $rows = parseExportCsv($response);

    expect(findCsvRow($rows, 'Generated By')[1])->toBe('Wren Okafor');
});

it('includes the authenticated physician\'s canonical name as Generated By in the CSV', function () {
    $physician = exportPhysician(['first_name' => 'Idris', 'last_name' => 'Falade']);

    $response = $this->actingAs($physician)
        ->get(route('physician.dashboard.export', ['physician' => $physician->user_id]));

    $rows = parseExportCsv($response);

    expect(findCsvRow($rows, 'Generated By')[1])->toBe('Idris Falade');
});

it('includes the authenticated admin\'s canonical name as Generated By in the CSV', function () {
    $admin = User::factory()->create(['role' => 'admin', 'first_name' => 'Priya', 'last_name' => 'Vance']);

    $response = $this->actingAs($admin)->get(route('admin.dashboard.export'));

    $rows = parseExportCsv($response);

    expect(findCsvRow($rows, 'Generated By')[1])->toBe('Priya Vance');
});

it('ignores a forged generated_by query parameter entirely', function () {
    $nurse = exportNurse(['first_name' => 'Real', 'last_name' => 'Nurse']);

    $response = $this->actingAs($nurse)->get(
        route('nurse.dashboard.export', ['nurse' => $nurse->user_id]).'?generated_by=Attacker'
    );

    $rows = parseExportCsv($response);

    expect(findCsvRow($rows, 'Generated By')[1])->toBe('Real Nurse')
        ->and($response->streamedContent())->not->toContain('Attacker');
});

it('does not change existing dashboard CSV metadata rows or their values', function () {
    $nurse = exportNurse();

    $response = $this->actingAs($nurse)
        ->get(route('nurse.dashboard.export', ['nurse' => $nurse->user_id]));

    $rows = parseExportCsv($response);

    // The four pre-existing meta rows must still be present and correct —
    // Generated By is an addition, not a replacement of any of these.
    expect(findCsvRow($rows, 'Role')[1])->toBe('Nurse')
        ->and(findCsvRow($rows, 'Range'))->not->toBeNull()
        ->and(findCsvRow($rows, 'Range Start'))->not->toBeNull()
        ->and(findCsvRow($rows, 'Range End'))->not->toBeNull()
        ->and(findCsvRow($rows, 'Generated'))->not->toBeNull();
});

// =====================================================================
// Filename/title convention: "<Role> <Full Name> <Timeline> Report"
// =====================================================================

it('uses the "Role Full-Name Timeline Report" CSV filename convention for a nurse', function () {
    $nurse = exportNurse(['first_name' => 'Maria', 'last_name' => 'Santos']);

    $response = $this->actingAs($nurse)
        ->get(route('nurse.dashboard.export', ['nurse' => $nurse->user_id]).'?range=this_month');

    expect($response->headers->get('Content-Disposition'))
        ->toContain('Nurse Maria Santos This Month Report.csv');
});

it('uses the "Role Full-Name Timeline Report" CSV filename convention for a physician', function () {
    $physician = exportPhysician(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

    $response = $this->actingAs($physician)
        ->get(route('physician.dashboard.export', ['physician' => $physician->user_id]).'?range=last_30_days');

    expect($response->headers->get('Content-Disposition'))
        ->toContain('Physician Juan Dela Cruz Last 30 Days Report.csv');
});

it('uses the "Role Full-Name Timeline Report" CSV filename convention for an admin', function () {
    $admin = User::factory()->create(['role' => 'admin', 'first_name' => 'Pedro', 'last_name' => 'Reyes']);

    $response = $this->actingAs($admin)
        ->get(route('admin.dashboard.export').'?range=last_30_days');

    expect($response->headers->get('Content-Disposition'))
        ->toContain('Admin Pedro Reyes Last 30 Days Report.csv');
});

it('produces a readable, filesystem-safe filename for a custom date range', function () {
    $physician = exportPhysician(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

    $response = $this->actingAs($physician)->get(
        route('physician.dashboard.export', ['physician' => $physician->user_id])
            .'?range=custom&start=2026-08-01&end=2026-08-27'
    );

    $disposition = $response->headers->get('Content-Disposition');

    expect($disposition)->toContain('Physician Juan Dela Cruz Aug 01 2026 - Aug 27 2026 Report.csv');

    // Extract the filename itself out of the header's quoting syntax
    // (attachment; filename="...") before checking for forbidden
    // characters — the header's own quotes/semicolons are header syntax,
    // not part of the constructed filename under test.
    preg_match('/filename="?([^"]+\.csv)"?/', $disposition, $m);
    $filename = $m[1];

    foreach (['/', ':', '\\', '*', '?', '<', '>', '|'] as $forbidden) {
        expect($filename)->not->toContain($forbidden);
    }
});

it('uses a human-readable role, not a raw internal value, in the filename', function () {
    $nurse = exportNurse();

    $response = $this->actingAs($nurse)
        ->get(route('nurse.dashboard.export', ['nurse' => $nurse->user_id]));

    $disposition = $response->headers->get('Content-Disposition');

    // "Nurse" (human-readable), never the raw lowercase 'nurse' role value
    // standing alone as a word.
    expect($disposition)->toContain('Nurse ')
        ->and($disposition)->not->toMatch('/\bnurse\b/'); // no lowercase "nurse" word anywhere
});

it('sets the CSV report title (first row) to match the filename convention', function () {
    $physician = exportPhysician(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

    $response = $this->actingAs($physician)
        ->get(route('physician.dashboard.export', ['physician' => $physician->user_id]).'?range=last_30_days');

    $rows = parseExportCsv($response);

    expect($rows[0][0])->toBe('Physician Juan Dela Cruz Last 30 Days Report');
});

it('cannot have its filename identity manipulated by forged role/name query parameters', function () {
    $nurse = exportNurse(['first_name' => 'Real', 'last_name' => 'Nurse']);

    $response = $this->actingAs($nurse)->get(
        route('nurse.dashboard.export', ['nurse' => $nurse->user_id])
            .'?role=admin&name=SomeoneElse&generated_by=Attacker'
    );

    $disposition = $response->headers->get('Content-Disposition');

    expect($disposition)->toContain('Nurse Real Nurse')
        ->and($disposition)->not->toContain('SomeoneElse')
        ->and($disposition)->not->toContain('Attacker')
        ->and($disposition)->not->toContain('Admin Real Nurse');
});
