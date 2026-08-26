<?php

use App\Services\SymptomAnalytics;

/**
 * Phase 5 finding H-4: SymptomAnalytics trusted the client-supplied
 * `custom` boolean as the sole classification authority. That flag is set
 * by Alpine in resources/views/patient/newconsultation.blade.php and is
 * never validated server-side (ConsultationController::store validates
 * symptoms_payload only as `required|string`), so a patient can submit
 * {"name": "<anything>", "custom": false} and force arbitrary free text
 * into the *ungated* standardized bucket, bypassing the k=3 privacy
 * suppression that otherwise only applied to entries flagged custom.
 *
 * New policy: classification is driven by name membership in the
 * application's actual standardized symptom vocabulary — the same 12
 * names patients choose from in the intake form's symptom picker
 * (resources/views/patient/newconsultation.blade.php, the `x-for="symptom
 * in [...]"` list, excluding the literal 'Others' trigger, which is not
 * itself a symptom). The `custom` flag is no longer read at all; a name
 * not in that vocabulary is always custom, regardless of what the client
 * claims.
 */
beforeEach(function () {
    $this->analytics = new SymptomAnalytics();
});

it('keeps a known standardized symptom name in the standardized bucket', function () {
    $result = $this->analytics->summarize([
        [['name' => 'Headache', 'severity' => 3]],
    ]);

    expect($result['standardized'])->toContain(['name' => 'Headache', 'count' => 1])
        ->and($result['custom']['requests_with_custom'])->toBe(0);
});

it('recognizes every name in the actual intake form vocabulary as standardized', function () {
    // The exact 12 symptom names from patient/newconsultation.blade.php's
    // picker (excludes 'Others', which is the UI trigger for custom entry,
    // not a symptom name).
    $vocabulary = [
        'Headache', 'Fever', 'Cough', 'Sore Throat', 'Body Pain', 'Fatigue',
        'Nausea / Vomiting', 'Diarrhea', 'Runny Nose', 'Shortness of Breath',
        'Loss of Appetite', 'Abdominal Pain',
    ];

    foreach ($vocabulary as $name) {
        $result = $this->analytics->summarize([[['name' => $name, 'severity' => 2]]]);

        expect($result['custom']['requests_with_custom'])
            ->toBe(0, "Expected \"$name\" to be classified as standardized, not custom.");
    }
});

it('treats an unrecognized symptom name as custom even when the client claims custom is false', function () {
    $result = $this->analytics->summarize([
        [['name' => 'Pressure behind my eyes', 'severity' => 3, 'custom' => false]],
    ]);

    expect($result['custom']['requests_with_custom'])->toBe(1)
        ->and(collect($result['standardized'])->pluck('name'))->not->toContain('Pressure behind my eyes');
});

it('treats an unrecognized symptom name as custom even when the custom key is entirely absent', function () {
    $result = $this->analytics->summarize([
        [['name' => 'Pressure behind my eyes', 'severity' => 3]],
    ]);

    expect($result['custom']['requests_with_custom'])->toBe(1);
});

it('still treats a known standardized name as standardized even if the client falsely flags it custom', function () {
    // The flag is no longer trusted at all — classification is name-driven
    // in both directions, not just for the attack case.
    $result = $this->analytics->summarize([
        [['name' => 'Headache', 'severity' => 3, 'custom' => true]],
    ]);

    expect($result['standardized'])->toContain(['name' => 'Headache', 'count' => 1])
        ->and($result['custom']['requests_with_custom'])->toBe(0);
});

it('suppresses an unrecognized symptom name reported only once', function () {
    $result = $this->analytics->summarize([
        [['name' => 'Weird tingling', 'severity' => 2]],
    ]);

    expect($result['custom']['terms'])->toBe([])
        ->and($result['custom']['suppressed_terms_count'])->toBe(1);
});

it('suppresses an unrecognized symptom name reported exactly twice', function () {
    $result = $this->analytics->summarize([
        [['name' => 'Weird tingling', 'severity' => 2]],
        [['name' => 'Weird tingling', 'severity' => 3]],
    ]);

    expect($result['custom']['terms'])->toBe([])
        ->and($result['custom']['suppressed_terms_count'])->toBe(1);
});

it('surfaces an unrecognized symptom name once it reaches three reports', function () {
    $result = $this->analytics->summarize([
        [['name' => 'Weird tingling', 'severity' => 2]],
        [['name' => 'Weird tingling', 'severity' => 3]],
        [['name' => 'weird tingling', 'severity' => 1]],
    ]);

    expect($result['custom']['terms'])->toContain(['name' => 'Weird tingling', 'count' => 3])
        ->and($result['custom']['suppressed_terms_count'])->toBe(0);
});

it('never leaks a suppressed term into the standardized bucket as a workaround', function () {
    $result = $this->analytics->summarize([
        [['name' => 'Only reported once', 'severity' => 2]],
    ]);

    expect(collect($result['standardized'])->pluck('name'))->not->toContain('Only reported once')
        ->and(collect($result['custom']['terms'])->pluck('name'))->not->toContain('Only reported once');
});

it('never exposes a suppressed term anywhere in the returned structure', function () {
    $result = $this->analytics->summarize([
        [['name' => 'Rare unique complaint', 'severity' => 2]],
    ]);

    $serialized = json_encode($result);

    expect($serialized)->not->toContain('Rare unique complaint');
});

it('still deduplicates a known standardized symptom repeated within one request', function () {
    $result = $this->analytics->summarize([
        [
            ['name' => 'Fever', 'severity' => 3],
            ['name' => 'fever', 'severity' => 4],
        ],
    ]);

    expect($result['standardized'])->toContain(['name' => 'Fever', 'count' => 1]);
});

it('does not break malformed-data protections under the new classification rule', function () {
    $result = $this->analytics->summarize([null, 'garbage', [], [['name' => '']], [['severity' => 3]]]);

    expect($result['valid_requests'])->toBe(0)
        ->and($result['standardized'])->toBe([])
        ->and($result['custom']['requests_with_custom'])->toBe(0);
});
