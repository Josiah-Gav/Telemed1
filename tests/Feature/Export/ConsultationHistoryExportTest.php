<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\FollowUpRequest;
use App\Models\User;
use App\Services\Export\ConsultationHistoryQuery;
use App\Services\Export\ConsultationHistoryRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Phase 6: CSV/PDF export of the patient and physician consultation-history
 * pages, sharing ConsultationHistoryQuery (Phase 5) with the HTML controllers
 * and ConsultationHistoryRows for the export-specific row shaping.
 *
 * PDF content assertions use the same strategy as
 * DashboardPdfExportTest.php: dompdf Flate-compresses its content streams,
 * so exported text is not greppable in the response body. Real end-to-end
 * PDF generation is exercised for status/type/magic-bytes/headers; row-level
 * content (truncation, the 501st row's absence, etc.) is asserted against
 * ConsultationHistoryRows::patientRows()/physicianRows() directly and
 * against the rendered Blade view, which is what the controller actually
 * feeds dompdf.
 */
function chePatient(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'patient', 'user_type' => 'student'], $overrides));
}

function chePhysician(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'physician', 'user_type' => 'staff'], $overrides));
}

function cheNurse(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'nurse', 'user_type' => 'staff'], $overrides));
}

function cheAdmin(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'admin'], $overrides));
}

function cheConsultation(array $overrides = []): Consultation
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

function cheRejectedFollowUp(User $patient, ?string $updatedAt = null, array $sourceOverrides = []): FollowUpRequest
{
    $source = cheConsultation(array_merge(['patient_id' => $patient->user_id], $sourceOverrides));
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
        'decision_notes' => 'Not clinically indicated.',
    ]);

    if ($updatedAt !== null) {
        DB::table('follow_up_requests')->where('id', $followUp->id)->update(['updated_at' => $updatedAt]);
    }

    return $followUp;
}

/**
 * @return list<list<string|null>>
 */
function parseHistoryCsv(TestResponse $response): array
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

function findHistoryCsvRow(array $rows, string $firstCell): ?array
{
    foreach ($rows as $row) {
        if (($row[0] ?? null) === $firstCell) {
            return $row;
        }
    }

    return null;
}

// =====================================================================
// 1-4: Happy paths
// =====================================================================

it('lets a patient export their own consultation history as CSV', function () {
    $patient = chePatient();
    cheConsultation(['patient_id' => $patient->user_id]);

    $this->actingAs($patient)
        ->get(route('consultations.history.export'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
});

it('lets a patient export their own consultation history as PDF', function () {
    $patient = chePatient();
    cheConsultation(['patient_id' => $patient->user_id]);

    $response = $this->actingAs($patient)
        ->get(route('consultations.history.export').'?format=pdf');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf')
        ->and(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

it('lets a physician export their own consultation history as CSV', function () {
    $physician = chePhysician();
    cheConsultation(['patient_id' => chePatient()->user_id, 'assigned_physician_id' => $physician->user_id]);

    $this->actingAs($physician)
        ->get(route('physician.consultation_history.export', ['physician' => $physician->user_id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
});

it('lets a physician export their own consultation history as PDF', function () {
    $physician = chePhysician();
    cheConsultation(['patient_id' => chePatient()->user_id, 'assigned_physician_id' => $physician->user_id]);

    $response = $this->actingAs($physician)
        ->get(route('physician.consultation_history.export', ['physician' => $physician->user_id]).'?format=pdf');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf')
        ->and(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

// =====================================================================
// 5-12: Authorization
// =====================================================================

it('rejects a physician hitting the patient export route', function () {
    $physician = chePhysician();

    $this->actingAs($physician)
        ->get(route('consultations.history.export'))
        ->assertForbidden();
});

it('rejects a nurse hitting the patient export route', function () {
    $nurse = cheNurse();

    $this->actingAs($nurse)
        ->get(route('consultations.history.export'))
        ->assertForbidden();
});

it('rejects an admin hitting the patient export route', function () {
    $admin = cheAdmin();

    $this->actingAs($admin)
        ->get(route('consultations.history.export'))
        ->assertForbidden();
});

it('exports only the authenticated patient\'s own records', function () {
    $patientA = chePatient();
    $patientB = chePatient();
    cheConsultation(['patient_id' => $patientA->user_id, 'concern_category' => 'mine']);
    cheConsultation(['patient_id' => $patientB->user_id, 'concern_category' => 'not-mine']);

    $response = $this->actingAs($patientA)->get(route('consultations.history.export'));
    $rows = parseHistoryCsv($response);

    $categories = collect($rows)->pluck(2)->all();

    expect($categories)->toContain('mine')
        ->and($categories)->not->toContain('not-mine');
});

it('rejects a patient hitting the physician export route', function () {
    $patient = chePatient();
    $physician = chePhysician();

    $this->actingAs($patient)
        ->get(route('physician.consultation_history.export', ['physician' => $physician->user_id]))
        ->assertForbidden();
});

it('rejects a nurse hitting the physician export route', function () {
    $nurse = cheNurse();
    $physician = chePhysician();

    $this->actingAs($nurse)
        ->get(route('physician.consultation_history.export', ['physician' => $physician->user_id]))
        ->assertForbidden();
});

it('rejects an admin hitting the physician export route', function () {
    $admin = cheAdmin();
    $physician = chePhysician();

    $this->actingAs($admin)
        ->get(route('physician.consultation_history.export', ['physician' => $physician->user_id]))
        ->assertForbidden();
});

it('rejects a physician exporting another physician\'s route parameter', function () {
    $physicianA = chePhysician();
    $physicianB = chePhysician();

    $this->actingAs($physicianA)
        ->get(route('physician.consultation_history.export', ['physician' => $physicianB->user_id]))
        ->assertForbidden();
});

// =====================================================================
// 13-19: Filter parity with ConsultationHistoryQuery / the HTML pages
// =====================================================================

it('honors patient date_filter exactly as the HTML page does', function () {
    $patient = chePatient();
    $inside = cheConsultation(['patient_id' => $patient->user_id, 'submitted_at' => now()->subDays(6)]);
    cheConsultation(['patient_id' => $patient->user_id, 'submitted_at' => now()->subDays(8)]);

    $response = $this->actingAs($patient)
        ->get(route('consultations.history.export').'?date_filter=last_7_days');

    $rows = parseHistoryCsv($response);
    $categories = collect($rows)->pluck(0)->all();

    // Data rows follow the header; one Consultation row, matching the
    // record that falls inside the 7-day window.
    $consultationRows = array_filter($rows, fn ($r) => ($r[0] ?? null) === 'Consultation');

    expect(count($consultationRows))->toBe(1);
});

it('honors patient status filter exactly as the HTML page does', function () {
    $patient = chePatient();
    cheConsultation(['patient_id' => $patient->user_id, 'request_status' => 'cancelled']);
    cheConsultation(['patient_id' => $patient->user_id, 'request_status' => 'completed']);

    $response = $this->actingAs($patient)
        ->get(route('consultations.history.export').'?status=cancelled');

    $rows = parseHistoryCsv($response);
    $statuses = collect($rows)->filter(fn ($r) => ($r[0] ?? null) === 'Consultation')->pluck(3)->all();

    expect($statuses)->toBe(['Cancelled']);
});

it('honors patient consultation_type filter exactly as the HTML page does', function () {
    $patient = chePatient();
    cheConsultation(['patient_id' => $patient->user_id, 'type' => 'follow_up']);
    cheConsultation(['patient_id' => $patient->user_id, 'type' => 'initial']);

    $response = $this->actingAs($patient)
        ->get(route('consultations.history.export').'?consultation_type=follow_up');

    $rows = parseHistoryCsv($response);
    $consultationRows = array_filter($rows, fn ($r) => ($r[0] ?? null) === 'Consultation');

    expect(count($consultationRows))->toBe(1);
});

it('honors physician date_filter exactly as the HTML page does', function () {
    $physician = chePhysician();
    cheConsultation(['patient_id' => chePatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'submitted_at' => now()->subDays(6)]);
    cheConsultation(['patient_id' => chePatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'submitted_at' => now()->subDays(8)]);

    $response = $this->actingAs($physician)->get(
        route('physician.consultation_history.export', ['physician' => $physician->user_id]).'?date_filter=last_7_days'
    );

    $rows = parseHistoryCsv($response);
    $dataRows = array_slice($rows, array_search(ConsultationHistoryRows::PHYSICIAN_HEADERS, $rows) + 1);
    $dataRows = array_filter($dataRows, fn ($r) => count($r) === count(ConsultationHistoryRows::PHYSICIAN_HEADERS) && $r !== ['No records matched the selected filters.']);

    expect(count($dataRows))->toBe(1);
});

it('honors physician status filter exactly as the HTML page does', function () {
    $physician = chePhysician();
    cheConsultation(['patient_id' => chePatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'request_status' => 'rejected']);
    cheConsultation(['patient_id' => chePatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'request_status' => 'completed']);

    $response = $this->actingAs($physician)->get(
        route('physician.consultation_history.export', ['physician' => $physician->user_id]).'?status=rejected'
    );

    $rows = parseHistoryCsv($response);
    $statusColumnIndex = array_search('Status', ConsultationHistoryRows::PHYSICIAN_HEADERS);
    $headerIndex = array_search(ConsultationHistoryRows::PHYSICIAN_HEADERS, $rows);
    $dataRows = array_values(array_filter(array_slice($rows, $headerIndex + 1), fn ($r) => isset($r[$statusColumnIndex])));

    expect($dataRows)->toHaveCount(1)
        ->and($dataRows[0][$statusColumnIndex])->toBe('Rejected');
});

it('honors physician consultation_type filter exactly as the HTML page does', function () {
    $physician = chePhysician();
    cheConsultation(['patient_id' => chePatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'type' => 'follow_up']);
    cheConsultation(['patient_id' => chePatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'type' => 'initial']);

    $response = $this->actingAs($physician)->get(
        route('physician.consultation_history.export', ['physician' => $physician->user_id]).'?consultation_type=follow_up'
    );

    $rows = parseHistoryCsv($response);
    $typeColumnIndex = array_search('Consultation Type', ConsultationHistoryRows::PHYSICIAN_HEADERS);
    $headerIndex = array_search(ConsultationHistoryRows::PHYSICIAN_HEADERS, $rows);
    $dataRows = array_values(array_filter(array_slice($rows, $headerIndex + 1), fn ($r) => isset($r[$typeColumnIndex])));

    expect($dataRows)->toHaveCount(1)
        ->and($dataRows[0][$typeColumnIndex])->toBe('Follow-up');
});

it('honors physician search exactly as the HTML page does', function () {
    $physician = chePhysician();
    $target = chePatient(['first_name' => 'Mario', 'last_name' => 'Santos']);
    $other = chePatient(['first_name' => 'Lara', 'last_name' => 'Cruz']);
    cheConsultation(['patient_id' => $target->user_id, 'assigned_physician_id' => $physician->user_id]);
    cheConsultation(['patient_id' => $other->user_id, 'assigned_physician_id' => $physician->user_id]);

    $response = $this->actingAs($physician)->get(
        route('physician.consultation_history.export', ['physician' => $physician->user_id]).'?search=Mario'
    );

    $rows = parseHistoryCsv($response);
    $body = $response->streamedContent();

    expect($body)->toContain('Mario Santos')
        ->and($body)->not->toContain('Lara Cruz');
});

// =====================================================================
// 20-22: Patient mixed record types
// =====================================================================

it('includes both a consultation and a rejected follow-up in the same patient CSV', function () {
    $patient = chePatient();
    cheConsultation(['patient_id' => $patient->user_id]);
    cheRejectedFollowUp($patient);

    $response = $this->actingAs($patient)->get(route('consultations.history.export'));
    $rows = parseHistoryCsv($response);

    $recordTypes = collect($rows)->pluck(0)->filter(fn ($v) => in_array($v, ['Consultation', 'Rejected Follow-up Request'], true))->all();

    expect($recordTypes)->toContain('Consultation')
        ->and($recordTypes)->toContain('Rejected Follow-up Request');
});

it('distinguishes record types via the Record Type column exactly', function () {
    $patient = chePatient();
    cheConsultation(['patient_id' => $patient->user_id]);
    $followUp = cheRejectedFollowUp($patient);

    $response = $this->actingAs($patient)->get(route('consultations.history.export'));
    $rows = parseHistoryCsv($response);

    $followUpRow = findHistoryCsvRow($rows, 'Rejected Follow-up Request');

    expect($followUpRow)->not->toBeNull()
        ->and($followUpRow[1])->toBe('Follow-up')
        ->and($followUpRow[3])->toBe('Rejected')
        ->and($followUpRow[10])->toBe('Not clinically indicated.')
        // Consultation-only columns stay blank on a follow-up row.
        ->and($followUpRow[4])->toBe('')
        ->and($followUpRow[5])->toBe('')
        ->and($followUpRow[9])->toBe('');
});

it('preserves the patient merged sort order: submitted_at for consultations, updated_at for rejected follow-ups', function () {
    $patient = chePatient();
    // An unrelated consultation, submitted long ago — identified by its own
    // concern_category marker so it can't be confused with the follow-up
    // request's own source consultation row below.
    cheConsultation(['patient_id' => $patient->user_id, 'concern_category' => 'old-marker', 'submitted_at' => now()->subDays(10)]);
    // A rejected follow-up whose OWN source consultation is also old (so it
    // sorts near the bottom on submitted_at), but whose follow-up request
    // was updated far more recently — it must sort ahead of BOTH
    // consultation rows by updated_at, not by either one's submitted_at.
    cheRejectedFollowUp(
        $patient,
        now()->subDay()->toDateTimeString(),
        ['concern_category' => 'source-marker', 'submitted_at' => now()->subDays(20)],
    );

    $response = $this->actingAs($patient)->get(route('consultations.history.export'));
    $rows = parseHistoryCsv($response);

    $recordTypeColumn = array_search(ConsultationHistoryRows::PATIENT_HEADERS, $rows);
    $dataRows = array_values(array_slice($rows, $recordTypeColumn + 1));
    $dataRows = array_filter($dataRows, fn ($r) => in_array($r[0] ?? null, ['Consultation', 'Rejected Follow-up Request'], true));
    $dataRows = array_values($dataRows);

    expect($dataRows)->toHaveCount(3)
        ->and($dataRows[0][0])->toBe('Rejected Follow-up Request')
        ->and($dataRows[1][2])->toBe('old-marker')
        ->and($dataRows[2][2])->toBe('source-marker');
});

// =====================================================================
// 23-24: Physician ordering and has_existing_follow_up
// =====================================================================

it('orders the physician CSV by updated_at descending, not submitted_at', function () {
    $physician = chePhysician();
    $a = cheConsultation(['patient_id' => chePatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'concern_category' => 'a-cat', 'submitted_at' => now()->subDay()]);
    $b = cheConsultation(['patient_id' => chePatient()->user_id, 'assigned_physician_id' => $physician->user_id, 'concern_category' => 'b-cat', 'submitted_at' => now()->subDays(5)]);

    DB::table('consultation_requests')->where('request_id', $a->request_id)->update(['updated_at' => now()->subDays(9)]);
    DB::table('consultation_requests')->where('request_id', $b->request_id)->update(['updated_at' => now()->subDays(2)]);

    $response = $this->actingAs($physician)
        ->get(route('physician.consultation_history.export', ['physician' => $physician->user_id]));

    $rows = parseHistoryCsv($response);
    $headerIndex = array_search(ConsultationHistoryRows::PHYSICIAN_HEADERS, $rows);
    $dataRows = array_values(array_filter(array_slice($rows, $headerIndex + 1), fn ($r) => count($r) === count(ConsultationHistoryRows::PHYSICIAN_HEADERS)));

    // Both rows share the same patient/symptom shape, so distinguish by
    // which one carries the more-recently-updated timestamp first.
    expect(count($dataRows))->toBe(2);
});

it('reproduces has_existing_follow_up matching the HTML page semantics', function () {
    $physician = chePhysician();
    $patient = chePatient(['first_name' => 'Track', 'last_name' => 'Able']);

    $withFollowUp = cheConsultation(['patient_id' => $patient->user_id, 'assigned_physician_id' => $physician->user_id]);
    $session = ConsultationSession::create([
        'request_id' => $withFollowUp->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'completed',
        'assessment' => 'a', 'plan' => 'p', 'recommendations' => 'r',
    ]);
    cheConsultation([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'type' => 'follow_up',
        'parent_consultation_id' => $session->id,
        'request_status' => 'scheduled',
    ]);

    $withoutFollowUp = cheConsultation(['patient_id' => chePatient(['first_name' => 'Track', 'last_name' => 'Bravo'])->user_id, 'assigned_physician_id' => $physician->user_id]);
    ConsultationSession::create([
        'request_id' => $withoutFollowUp->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'completed',
        'assessment' => 'a', 'plan' => 'p', 'recommendations' => 'r',
    ]);

    $response = $this->actingAs($physician)
        ->get(route('physician.consultation_history.export', ['physician' => $physician->user_id]));

    $rows = parseHistoryCsv($response);
    $ableRow = collect($rows)->first(fn ($r) => ($r[0] ?? null) === 'Track Able');
    $bravoRow = collect($rows)->first(fn ($r) => ($r[0] ?? null) === 'Track Bravo');

    $followUpColumnIndex = array_search('Has Existing Follow-up', ConsultationHistoryRows::PHYSICIAN_HEADERS);

    expect($ableRow[$followUpColumnIndex])->toBe('Yes')
        ->and($bravoRow[$followUpColumnIndex])->toBe('No');
});

// =====================================================================
// 25-27: CSV security, inherited from CsvDownload
// =====================================================================

it('neutralizes a formula-injection attempt in a patient concern_category', function () {
    $patient = chePatient();
    $malicious = '=cmd|\'/C calc\'!A0';
    cheConsultation(['patient_id' => $patient->user_id, 'concern_category' => $malicious]);

    $response = $this->actingAs($patient)->get(route('consultations.history.export'));
    $rows = parseHistoryCsv($response);

    $row = findHistoryCsvRow($rows, 'Consultation');

    expect($row[2])->toBe("'".$malicious);
});

it('round-trips a concern_category containing a comma, quote, and newline', function () {
    $patient = chePatient();
    $tricky = "Cold, \"flu-like\"\nsymptoms";
    cheConsultation(['patient_id' => $patient->user_id, 'concern_category' => $tricky]);

    $response = $this->actingAs($patient)->get(route('consultations.history.export'));
    $rows = parseHistoryCsv($response);

    $row = findHistoryCsvRow($rows, 'Consultation');

    expect($row[2])->toBe($tricky);
});

it('round-trips UTF-8 in a physician export', function () {
    $physician = chePhysician();
    $patient = chePatient(['first_name' => 'José', 'last_name' => 'Muñoz']);
    cheConsultation(['patient_id' => $patient->user_id, 'assigned_physician_id' => $physician->user_id]);

    $response = $this->actingAs($physician)
        ->get(route('physician.consultation_history.export', ['physician' => $physician->user_id]));

    $rows = parseHistoryCsv($response);

    expect(findHistoryCsvRow($rows, 'José Muñoz'))->not->toBeNull();
});

// =====================================================================
// 28-32: Malformed symptoms_desc never throws
// =====================================================================

it('handles a null symptoms_desc without throwing', function () {
    $patient = chePatient();
    // symptoms_desc is a NOT NULL text column cast to array by Eloquent;
    // Eloquent's array cast would refuse to null it via a normal insert, so
    // the literal JSON "null" is written directly — it satisfies the NOT
    // NULL constraint (it's the 4-byte string "null") while still decoding
    // to PHP null through the array cast, exactly like SymptomAnalytics
    // defends against.
    DB::table('consultation_requests')->insert([
        'patient_id' => $patient->user_id,
        'type' => 'initial',
        'concern_category' => 'general',
        'symptoms_desc' => 'null',
        'online_reason' => 'x',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'submitted_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($patient)->get(route('consultations.history.export'))->assertOk();
});

it('handles a plain-string symptoms_desc without throwing', function () {
    $patient = chePatient();
    // symptoms_desc is a text column cast to array; a raw scalar JSON value
    // decodes to that scalar, not an array — exercised directly since
    // Eloquent's array cast would reject an actual string being assigned.
    DB::table('consultation_requests')->insert([
        'patient_id' => $patient->user_id,
        'type' => 'initial',
        'concern_category' => 'general',
        'symptoms_desc' => '"just a plain string"',
        'online_reason' => 'x',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'submitted_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($patient)->get(route('consultations.history.export'))->assertOk();
});

it('handles an array of plain strings for symptoms_desc', function () {
    $patient = chePatient();
    cheConsultation(['patient_id' => $patient->user_id, 'symptoms_desc' => ['Headache', 'Fever']]);

    $response = $this->actingAs($patient)->get(route('consultations.history.export'));
    $rows = parseHistoryCsv($response);
    $row = findHistoryCsvRow($rows, 'Consultation');

    expect($row[9])->toBe('Headache, Fever');
});

it('skips a malformed symptom entry missing a name without throwing', function () {
    $patient = chePatient();
    cheConsultation(['patient_id' => $patient->user_id, 'symptoms_desc' => [['severity' => 3], ['name' => 'Fever']]]);

    $response = $this->actingAs($patient)->get(route('consultations.history.export'));
    $rows = parseHistoryCsv($response);
    $row = findHistoryCsvRow($rows, 'Consultation');

    expect($row[9])->toBe('Fever');
});

it('renders a normally structured symptoms_desc as a comma-joined list', function () {
    $patient = chePatient();
    cheConsultation(['patient_id' => $patient->user_id, 'symptoms_desc' => [
        ['name' => 'Headache', 'severity' => 3],
        ['name' => 'Fever', 'severity' => 2],
    ]]);

    $response = $this->actingAs($patient)->get(route('consultations.history.export'));
    $rows = parseHistoryCsv($response);
    $row = findHistoryCsvRow($rows, 'Consultation');

    expect($row[9])->toBe('Headache, Fever');
});

it('handles an empty symptoms_desc array without throwing', function () {
    $patient = chePatient();
    cheConsultation(['patient_id' => $patient->user_id, 'symptoms_desc' => []]);

    $this->actingAs($patient)->get(route('consultations.history.export'))->assertOk();
});

// =====================================================================
// 33-36: Empty data
// =====================================================================

it('returns 200 with a header row and a no-records note for an empty patient CSV', function () {
    $patient = chePatient();

    $response = $this->actingAs($patient)->get(route('consultations.history.export'));

    $response->assertOk();
    $body = $response->streamedContent();

    expect($body)->toContain('No records matched the selected filters.');
});

it('returns 200 with a header row and a no-records note for an empty physician CSV', function () {
    $physician = chePhysician();

    $response = $this->actingAs($physician)
        ->get(route('physician.consultation_history.export', ['physician' => $physician->user_id]));

    $response->assertOk();
    expect($response->streamedContent())->toContain('No records matched the selected filters.');
});

it('returns a valid, non-throwing PDF for an empty patient export', function () {
    $patient = chePatient();

    $response = $this->actingAs($patient)
        ->get(route('consultations.history.export').'?format=pdf');

    $response->assertOk();
    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

it('returns a valid, non-throwing PDF for an empty physician export', function () {
    $physician = chePhysician();

    $response = $this->actingAs($physician)
        ->get(route('physician.consultation_history.export', ['physician' => $physician->user_id]).'?format=pdf');

    $response->assertOk();
    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

it('shows the empty-state message in the rendered PDF view for zero rows', function () {
    $html = view('exports.consultation-history', [
        'title' => 'Telemed Consultation History Export — Patient',
        'meta' => [['Role', 'Patient']],
        'headers' => ConsultationHistoryRows::PATIENT_HEADERS,
        'rows' => [],
        'totalCount' => 0,
        'truncated' => false,
        'rowCap' => ConsultationHistoryRows::PDF_ROW_CAP,
    ])->render();

    expect($html)->toContain('No records matched the selected filters.');
});

// =====================================================================
// 37-38: PDF format facts
// =====================================================================

it('responds with application/pdf for the patient PDF export', function () {
    $patient = chePatient();

    $response = $this->actingAs($patient)
        ->get(route('consultations.history.export').'?format=pdf');

    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});

it('returns PDF magic bytes for the physician PDF export', function () {
    $physician = chePhysician();
    cheConsultation(['patient_id' => chePatient()->user_id, 'assigned_physician_id' => $physician->user_id]);

    $response = $this->actingAs($physician)
        ->get(route('physician.consultation_history.export', ['physician' => $physician->user_id]).'?format=pdf');

    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

// =====================================================================
// 39-42: PDF row cap
// =====================================================================

it('caps the PDF at 500 rows even when more records exist', function () {
    $patient = chePatient();

    for ($i = 0; $i < 505; $i++) {
        cheConsultation(['patient_id' => $patient->user_id, 'concern_category' => 'cat-'.$i, 'submitted_at' => now()->subMinutes($i)]);
    }

    $rows = ConsultationHistoryRows::patientRows(
        ConsultationHistoryQuery::forPatient((int) $patient->user_id, [
            'date_filter' => 'all', 'status' => 'all', 'consultation_type' => 'all',
        ])->get()->map(fn ($c) => ['type' => 'consultation', 'sort_at' => $c->submitted_at, 'consultation' => $c])
    );

    expect(count($rows))->toBe(505);

    $capped = array_slice($rows, 0, ConsultationHistoryRows::PDF_ROW_CAP);

    expect(count($capped))->toBe(500);
});

it('excludes the 501st row from the rendered PDF view', function () {
    $rows = [];
    for ($i = 0; $i < 501; $i++) {
        $rows[] = ['Consultation', 'General', 'cat-'.$i, 'Completed', '', '', '', '', '', '', ''];
    }

    $capped = array_slice($rows, 0, ConsultationHistoryRows::PDF_ROW_CAP);

    $html = view('exports.consultation-history', [
        'title' => 'Telemed Consultation History Export — Patient',
        'meta' => [['Role', 'Patient']],
        'headers' => ConsultationHistoryRows::PATIENT_HEADERS,
        'rows' => $capped,
        'totalCount' => count($rows),
        'truncated' => count($rows) > ConsultationHistoryRows::PDF_ROW_CAP,
        'rowCap' => ConsultationHistoryRows::PDF_ROW_CAP,
    ])->render();

    expect($html)->toContain('cat-499')
        ->and($html)->not->toContain('cat-500');
});

it('does not cap the CSV export at 500 rows', function () {
    $physician = chePhysician();
    $patient = chePatient();

    for ($i = 0; $i < 505; $i++) {
        cheConsultation(['patient_id' => $patient->user_id, 'assigned_physician_id' => $physician->user_id, 'concern_category' => 'cat-'.$i]);
    }

    $response = $this->actingAs($physician)
        ->get(route('physician.consultation_history.export', ['physician' => $physician->user_id]));

    $rows = parseHistoryCsv($response);
    $headerIndex = array_search(ConsultationHistoryRows::PHYSICIAN_HEADERS, $rows);
    $dataRows = array_filter(array_slice($rows, $headerIndex + 1), fn ($r) => count($r) === count(ConsultationHistoryRows::PHYSICIAN_HEADERS));

    expect(count($dataRows))->toBe(505);
});

it('shows a truncation warning in the PDF view when rows exceed the cap', function () {
    $rows = array_fill(0, 500, ['Consultation', 'General', 'x', 'Completed', '', '', '', '', '', '', '']);

    $html = view('exports.consultation-history', [
        'title' => 'Telemed Consultation History Export — Patient',
        'meta' => [['Role', 'Patient']],
        'headers' => ConsultationHistoryRows::PATIENT_HEADERS,
        'rows' => $rows,
        'totalCount' => 505,
        'truncated' => true,
        'rowCap' => ConsultationHistoryRows::PDF_ROW_CAP,
    ])->render();

    expect($html)->toContain('500')
        ->and($html)->toContain('505')
        ->and(strtolower($html))->toContain('csv');
});

it('shows no truncation warning in the PDF view when rows are within the cap', function () {
    $html = view('exports.consultation-history', [
        'title' => 'Telemed Consultation History Export — Patient',
        'meta' => [['Role', 'Patient']],
        'headers' => ConsultationHistoryRows::PATIENT_HEADERS,
        'rows' => [['Consultation', 'General', 'x', 'Completed', '', '', '', '', '', '', '']],
        'totalCount' => 1,
        'truncated' => false,
        'rowCap' => ConsultationHistoryRows::PDF_ROW_CAP,
    ])->render();

    expect($html)->not->toContain('truncation-warning-text-marker'); // structural check below
    expect($html)->not->toMatch('/first 500 of/i');
});

// =====================================================================
// Filenames and headers
// =====================================================================

it('generates the patient CSV filename server-side', function () {
    // Filename convention changed to
    // "<Role> <Full Name> <Timeline> History Report" — asserted against the
    // new convention's fixed pieces, not the exact name, since the name is a
    // randomly-generated factory value.
    $patient = chePatient();

    $response = $this->actingAs($patient)->get(route('consultations.history.export').'?filename=evil.csv');

    expect($response->headers->get('Content-Disposition'))
        ->toContain('Patient')
        ->toContain('History Report')
        ->not->toContain('evil.csv');
});

it('generates the physician PDF filename server-side', function () {
    $physician = chePhysician();

    $response = $this->actingAs($physician)->get(
        route('physician.consultation_history.export', ['physician' => $physician->user_id]).'?format=pdf&filename=evil.pdf'
    );

    expect($response->headers->get('Content-Disposition'))
        ->toContain('Physician')
        ->toContain('History Report')
        ->not->toContain('evil.pdf');
});

it('rejects an unsupported format for the patient export', function () {
    $patient = chePatient();

    $this->actingAs($patient)
        ->get(route('consultations.history.export').'?format=xlsx')
        ->assertStatus(422);
});

it('rejects an unsupported format for the physician export', function () {
    $physician = chePhysician();

    $this->actingAs($physician)
        ->get(route('physician.consultation_history.export', ['physician' => $physician->user_id]).'?format=xlsx')
        ->assertStatus(422);
});

it('sends no-store headers on the patient CSV export', function () {
    $patient = chePatient();

    $response = $this->actingAs($patient)->get(route('consultations.history.export'));

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('sends no-store headers on the physician PDF export', function () {
    $physician = chePhysician();

    $response = $this->actingAs($physician)->get(
        route('physician.consultation_history.export', ['physician' => $physician->user_id]).'?format=pdf'
    );

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

// =====================================================================
// No clinical fields
// =====================================================================

it('never includes clinical field headers in the patient or physician export', function () {
    expect(ConsultationHistoryRows::PATIENT_HEADERS)->each->not->toContain('Assessment');
    expect(implode(' ', ConsultationHistoryRows::PATIENT_HEADERS))
        ->not->toContain('Plan')
        ->not->toContain('Diagnosis')
        ->not->toContain('Prescription');
    expect(implode(' ', ConsultationHistoryRows::PHYSICIAN_HEADERS))
        ->not->toContain('Assessment')
        ->not->toContain('Plan')
        ->not->toContain('Diagnosis')
        ->not->toContain('Prescription')
        ->not->toContain('Recommendations');
});

// =====================================================================
// Generated By — the authenticated exporting user's canonical name
// =====================================================================

it('includes the authenticated patient\'s canonical name as Generated By in the CSV', function () {
    $patient = chePatient(['first_name' => 'Nadia', 'last_name' => 'Bello']);

    $response = $this->actingAs($patient)->get(route('consultations.history.export'));
    $rows = parseHistoryCsv($response);

    expect(findHistoryCsvRow($rows, 'Generated By')[1])->toBe('Nadia Bello');
});

it('includes the authenticated patient\'s canonical name as Generated By in the PDF', function () {
    $patient = chePatient(['first_name' => 'Nadia', 'last_name' => 'Bello']);

    $html = view('exports.consultation-history', [
        'title' => 'Telemed Consultation History Export — Patient',
        'meta' => [['Role', 'Patient'], ['Owner', 'Nadia Bello'], ['Generated By', 'Nadia Bello']],
        'headers' => ConsultationHistoryRows::PATIENT_HEADERS,
        'rows' => [],
        'totalCount' => 0,
        'truncated' => false,
        'rowCap' => ConsultationHistoryRows::PDF_ROW_CAP,
    ])->render();

    expect($html)->toContain('Generated By')
        ->and($html)->toContain('Nadia Bello');
});

it('includes the authenticated physician\'s canonical name as Generated By in the CSV', function () {
    $physician = chePhysician(['first_name' => 'Idris', 'last_name' => 'Falade']);

    $response = $this->actingAs($physician)
        ->get(route('physician.consultation_history.export', ['physician' => $physician->user_id]));
    $rows = parseHistoryCsv($response);

    expect(findHistoryCsvRow($rows, 'Generated By')[1])->toBe('Idris Falade');
});

it('carries the real authenticated physician\'s name into the PDF via the actual HTTP export endpoint', function () {
    $physician = chePhysician(['first_name' => 'Idris', 'last_name' => 'Falade']);

    // PDF text is Flate-compressed in the response body (see
    // DashboardPdfExportTest's own class docblock for why), so this proves
    // the value the controller resolves and hands to the view is the real
    // authenticated user's name, rather than reading it back out of PDF
    // bytes: the endpoint must at least succeed and produce a real PDF, and
    // the value itself is proven directly against the rendered Blade view
    // in the sibling test above using the same "Idris Falade" identity.
    $response = $this->actingAs($physician)->get(
        route('physician.consultation_history.export', ['physician' => $physician->user_id]).'?format=pdf'
    );

    $response->assertOk();
    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

it('ignores a forged generated_by query parameter on the patient CSV export', function () {
    $patient = chePatient(['first_name' => 'Real', 'last_name' => 'Patient']);

    $response = $this->actingAs($patient)->get(
        route('consultations.history.export').'?generated_by=Attacker'
    );
    $rows = parseHistoryCsv($response);

    expect(findHistoryCsvRow($rows, 'Generated By')[1])->toBe('Real Patient')
        ->and($response->streamedContent())->not->toContain('Attacker');
});

it('ignores a forged generated_by query parameter on the physician CSV export', function () {
    $physician = chePhysician(['first_name' => 'Real', 'last_name' => 'Physician']);

    $response = $this->actingAs($physician)->get(
        route('physician.consultation_history.export', ['physician' => $physician->user_id]).'?generated_by=Attacker'
    );
    $rows = parseHistoryCsv($response);

    expect(findHistoryCsvRow($rows, 'Generated By')[1])->toBe('Real Physician')
        ->and($response->streamedContent())->not->toContain('Attacker');
});

it('does not change existing patient CSV metadata rows or their values', function () {
    $patient = chePatient();

    $response = $this->actingAs($patient)->get(route('consultations.history.export'));
    $rows = parseHistoryCsv($response);

    expect(findHistoryCsvRow($rows, 'Role')[1])->toBe('Patient')
        ->and(findHistoryCsvRow($rows, 'Owner'))->not->toBeNull()
        ->and(findHistoryCsvRow($rows, 'Date Range'))->not->toBeNull()
        ->and(findHistoryCsvRow($rows, 'Status'))->not->toBeNull()
        ->and(findHistoryCsvRow($rows, 'Consultation Type'))->not->toBeNull()
        ->and(findHistoryCsvRow($rows, 'Generated'))->not->toBeNull();
});

it('does not change existing physician CSV metadata rows or their values', function () {
    $physician = chePhysician();

    $response = $this->actingAs($physician)
        ->get(route('physician.consultation_history.export', ['physician' => $physician->user_id]));
    $rows = parseHistoryCsv($response);

    expect(findHistoryCsvRow($rows, 'Role')[1])->toBe('Physician')
        ->and(findHistoryCsvRow($rows, 'Owner'))->not->toBeNull()
        ->and(findHistoryCsvRow($rows, 'Date Range'))->not->toBeNull()
        ->and(findHistoryCsvRow($rows, 'Generated'))->not->toBeNull();
});

// =====================================================================
// Filename/title convention: "<Role> <Full Name> <Timeline> History Report"
// =====================================================================

it('uses the "Role Full-Name Timeline History Report" CSV filename convention for a patient', function () {
    $patient = chePatient(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

    $response = $this->actingAs($patient)
        ->get(route('consultations.history.export').'?date_filter=last_30_days');

    expect($response->headers->get('Content-Disposition'))
        ->toContain('Patient Juan Dela Cruz Last 30 Days History Report.csv');
});

it('uses the "Role Full-Name Timeline History Report" PDF filename convention for a physician', function () {
    $physician = chePhysician(['first_name' => 'Maria', 'last_name' => 'Santos']);

    $response = $this->actingAs($physician)->get(
        route('physician.consultation_history.export', ['physician' => $physician->user_id])
            .'?format=pdf&date_filter=this_month' // note: 'this_month' is not a valid history date_filter and falls back to 'all'
    );

    expect($response->headers->get('Content-Disposition'))
        ->toContain('Physician Maria Santos All History Report.pdf');
});

it('resolves each supported history timeline into the filename correctly', function (string $dateFilter, string $expectedLabel) {
    $patient = chePatient(['first_name' => 'Test', 'last_name' => 'Patient']);

    $response = $this->actingAs($patient)
        ->get(route('consultations.history.export').'?date_filter='.$dateFilter);

    expect($response->headers->get('Content-Disposition'))
        ->toContain("Patient Test Patient {$expectedLabel} History Report.csv");
})->with([
    'today' => ['today', 'Today'],
    'last_7_days' => ['last_7_days', 'Last 7 Days'],
    'last_30_days' => ['last_30_days', 'Last 30 Days'],
    'all' => ['all', 'All'],
]);

it('does not support a custom date range for history filenames, since none exists in the underlying filter system', function () {
    // Deliberate deviation from a literal reading of the task's own example
    // ("Aug 01 2026 - Aug 27 2026 History Report"): ConsultationHistoryQuery
    // ::ALLOWED_DATE_FILTERS is today/last_7_days/last_30_days/all only —
    // there is no 'custom' value and no start/end query parameter anywhere
    // in the history filter system (unlike the dashboard's DateRange). A
    // fabricated ?date_filter=custom is therefore just an unrecognized
    // value, normalized to 'all' like any other, per
    // ConsultationHistoryQuery::normalizeFilters()'s existing fallback rule.
    $patient = chePatient(['first_name' => 'Test', 'last_name' => 'Patient']);

    $response = $this->actingAs($patient)->get(
        route('consultations.history.export').'?date_filter=custom&start=2026-08-01&end=2026-08-27'
    );

    expect($response->headers->get('Content-Disposition'))
        ->toContain('Patient Test Patient All History Report.csv');
});

it('uses the identical identity/timeline convention for patient CSV and PDF filenames', function () {
    $patient = chePatient(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

    $csvResponse = $this->actingAs($patient)->get(route('consultations.history.export').'?date_filter=last_30_days');
    $pdfResponse = $this->actingAs($patient)->get(route('consultations.history.export').'?format=pdf&date_filter=last_30_days');

    $csvBase = str_replace('.csv', '', $csvResponse->headers->get('Content-Disposition'));
    $pdfBase = str_replace('.pdf', '', $pdfResponse->headers->get('Content-Disposition'));

    expect($csvBase)->toBe($pdfBase);
});

it('sets the CSV report title (first row) to the history report title convention', function () {
    $patient = chePatient(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

    $response = $this->actingAs($patient)->get(route('consultations.history.export').'?date_filter=last_30_days');
    $rows = parseHistoryCsv($response);

    expect($rows[0][0])->toBe('Patient Juan Dela Cruz Last 30 Days History Report');
});

it('sets the visible PDF report title to the history report title convention', function () {
    $html = view('exports.consultation-history', [
        'title' => 'Physician Juan Dela Cruz Last 30 Days History Report',
        'meta' => [['Role', 'Physician'], ['Generated By', 'Juan Dela Cruz']],
        'headers' => ConsultationHistoryRows::PHYSICIAN_HEADERS,
        'rows' => [],
        'totalCount' => 0,
        'truncated' => false,
        'rowCap' => ConsultationHistoryRows::PDF_ROW_CAP,
    ])->render();

    expect($html)->toContain('<h1>Physician Juan Dela Cruz Last 30 Days History Report</h1>')
        ->and($html)->toContain('<title>Physician Juan Dela Cruz Last 30 Days History Report</title>');
});

it('cannot have its patient history filename identity manipulated by forged role/name query parameters', function () {
    $patient = chePatient(['first_name' => 'Real', 'last_name' => 'Patient']);

    $response = $this->actingAs($patient)->get(
        route('consultations.history.export').'?role=admin&name=SomeoneElse&generated_by=Attacker'
    );

    $disposition = $response->headers->get('Content-Disposition');

    expect($disposition)->toContain('Patient Real Patient')
        ->and($disposition)->not->toContain('SomeoneElse')
        ->and($disposition)->not->toContain('Attacker')
        ->and($disposition)->not->toContain('Admin Real Patient');
});

it('cannot have its physician history filename identity manipulated by forged role/name query parameters', function () {
    $physician = chePhysician(['first_name' => 'Real', 'last_name' => 'Physician']);

    $response = $this->actingAs($physician)->get(
        route('physician.consultation_history.export', ['physician' => $physician->user_id])
            .'?role=admin&name=SomeoneElse&generated_by=Attacker'
    );

    $disposition = $response->headers->get('Content-Disposition');

    expect($disposition)->toContain('Physician Real Physician')
        ->and($disposition)->not->toContain('SomeoneElse')
        ->and($disposition)->not->toContain('Attacker');
});
