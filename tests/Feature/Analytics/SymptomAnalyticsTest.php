<?php

use App\Services\SymptomAnalytics;

/**
 * Pure PHP over arrays — no database involved. The service is handed
 * already-filtered `symptoms_desc` values (initial requests only; the
 * caller is responsible for excluding follow-ups, since they copy the
 * parent's symptoms verbatim and would otherwise be double-counted).
 */
beforeEach(function () {
    $this->analytics = new SymptomAnalytics;
});

it('counts a standardized symptom once per request', function () {
    $result = $this->analytics->summarize([
        [['name' => 'Headache', 'severity' => 3]],
    ]);

    expect($result['standardized'])->toContain(['name' => 'Headache', 'count' => 1]);
});

it('counts the same standardized symptom across multiple requests', function () {
    $result = $this->analytics->summarize([
        [['name' => 'Headache', 'severity' => 3]],
        [['name' => 'Headache', 'severity' => 2]],
    ]);

    expect($result['standardized'])->toContain(['name' => 'Headache', 'count' => 2]);
});

it('counts the same symptom listed twice in one request only once', function () {
    $result = $this->analytics->summarize([
        [
            ['name' => 'Headache', 'severity' => 3],
            ['name' => 'headache', 'severity' => 4], // same symptom, different case
        ],
    ]);

    expect($result['standardized'])->toContain(['name' => 'Headache', 'count' => 1]);
});

it('classifies an entry without a custom key as standardized', function () {
    $result = $this->analytics->summarize([
        [['name' => 'Cough', 'severity' => 2]],
    ]);

    expect($result['standardized'])->toContain(['name' => 'Cough', 'count' => 1])
        ->and($result['custom']['requests_with_custom'])->toBe(0);
});

it('classifies an entry with custom true as custom, not standardized', function () {
    $result = $this->analytics->summarize([
        [['name' => 'Pressure behind my eyes', 'severity' => 3, 'custom' => true]],
    ]);

    expect($result['custom']['requests_with_custom'])->toBe(1)
        ->and(collect($result['standardized'])->pluck('name'))->not->toContain('Pressure behind my eyes');
});

it('computes the custom percentage against valid requests, not raw entries', function () {
    $result = $this->analytics->summarize([
        [['name' => 'Headache', 'severity' => 3]],
        [['name' => 'Headache', 'severity' => 3]],
        [['name' => 'Custom symptom', 'severity' => 3, 'custom' => true]],
        [['name' => 'Custom symptom', 'severity' => 3, 'custom' => true]],
    ]);

    expect($result['valid_requests'])->toBe(4)
        ->and($result['custom']['requests_with_custom'])->toBe(2)
        ->and($result['custom']['percentage'])->toBe(50.0);
});

it('gates individual custom terms below three reports into a suppressed count, per the k=3 privacy rule', function () {
    $result = $this->analytics->summarize([
        [['name' => 'Ringing in ears', 'severity' => 2, 'custom' => true]],
        [['name' => 'Ringing in ears', 'severity' => 2, 'custom' => true]],
        // Only two reports of the same term — below the n>=3 threshold.
    ]);

    expect($result['custom']['terms'])->toBe([])
        ->and($result['custom']['suppressed_terms_count'])->toBe(1);
});

it('surfaces a custom term once it reaches three or more distinct-request reports', function () {
    $result = $this->analytics->summarize([
        [['name' => 'Ringing in ears', 'severity' => 2, 'custom' => true]],
        [['name' => 'Ringing in ears', 'severity' => 2, 'custom' => true]],
        [['name' => 'ringing in ears', 'severity' => 3, 'custom' => true]],
    ]);

    expect($result['custom']['terms'])->toContain(['name' => 'Ringing in ears', 'count' => 3])
        ->and($result['custom']['suppressed_terms_count'])->toBe(0);
});

it('builds the severity distribution across 1 through 4', function () {
    $result = $this->analytics->summarize([
        [['name' => 'A', 'severity' => 1]],
        [['name' => 'B', 'severity' => 2]],
        [['name' => 'C', 'severity' => 3]],
        [['name' => 'D', 'severity' => 4]],
        [['name' => 'E', 'severity' => 4]],
    ]);

    expect($result['severity']['counts'])->toBe([1 => 1, 2 => 1, 3 => 1, 4 => 2])
        ->and($result['severity']['valid_entries'])->toBe(5)
        ->and($result['severity']['severe_count'])->toBe(2);
});

it('marks bucket 3 as the pre-selected default rather than a deliberate severe rating', function () {
    $result = $this->analytics->summarize([
        [['name' => 'A', 'severity' => 3]],
    ]);

    expect($result['severity']['default_bucket'])->toBe(3);
});

it('never computes an average or mean severity', function () {
    $result = $this->analytics->summarize([
        [['name' => 'A', 'severity' => 1]],
        [['name' => 'B', 'severity' => 4]],
    ]);

    expect($result['severity'])->not->toHaveKey('average')
        ->and($result['severity'])->not->toHaveKey('mean');
});

// --- defensive handling of malformed data -----------------------------------

it('skips a request with a null symptoms_desc without crashing', function () {
    $result = $this->analytics->summarize([null]);

    expect($result['valid_requests'])->toBe(0)
        ->and($result['malformed_requests'])->toBe(1);
});

it('skips a request whose symptoms_desc is not an array without crashing', function () {
    $result = $this->analytics->summarize(['not an array']);

    expect($result['valid_requests'])->toBe(0)
        ->and($result['malformed_requests'])->toBe(1);
});

it('skips an empty symptoms_desc array without counting it as valid', function () {
    $result = $this->analytics->summarize([[]]);

    expect($result['valid_requests'])->toBe(0);
});

it('skips a non-array entry inside an otherwise valid request', function () {
    $result = $this->analytics->summarize([
        ['not an entry', ['name' => 'Headache', 'severity' => 3]],
    ]);

    expect($result['valid_requests'])->toBe(1)
        ->and($result['standardized'])->toContain(['name' => 'Headache', 'count' => 1]);
});

it('skips an entry with a missing name but keeps the rest of the request valid', function () {
    $result = $this->analytics->summarize([
        [['severity' => 3], ['name' => 'Cough', 'severity' => 2]],
    ]);

    expect($result['valid_requests'])->toBe(1)
        ->and($result['standardized'])->toContain(['name' => 'Cough', 'count' => 1]);
});

it('skips an entry with a non-string name', function () {
    $result = $this->analytics->summarize([
        [['name' => 42, 'severity' => 3]],
    ]);

    expect($result['valid_requests'])->toBe(0);
});

it('counts a symptom missing severity toward frequency but not toward the severity distribution', function () {
    $result = $this->analytics->summarize([
        [['name' => 'Headache']],
    ]);

    expect($result['standardized'])->toContain(['name' => 'Headache', 'count' => 1])
        ->and($result['severity']['valid_entries'])->toBe(0);
});

it('excludes an out-of-range severity from the distribution but keeps the symptom in frequency', function () {
    $result = $this->analytics->summarize([
        [['name' => 'Headache', 'severity' => 9]],
    ]);

    expect($result['standardized'])->toContain(['name' => 'Headache', 'count' => 1])
        ->and($result['severity']['valid_entries'])->toBe(0);
});

it('never throws when every row is malformed', function () {
    $result = $this->analytics->summarize([null, 'garbage', [], [['name' => '']]]);

    expect($result['valid_requests'])->toBe(0)
        ->and($result['standardized'])->toBe([]);
});
