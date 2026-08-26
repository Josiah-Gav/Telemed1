<?php

/**
 * Regression coverage for Phase 5 finding C-1: Blade's @json directive does
 * `explode(',', $expression)` on its raw argument text (CompilesJson.php).
 * Passed a multi-key array literal directly — @json(['labels' => $labels,
 * 'datasets' => $datasets]) — the top-level comma between the two array
 * entries splits the expression, and the JSON_HEX_* escaping flags that
 * were supposed to apply get silently replaced by the $depth default
 * (512), which happens to be interpreted as $flags instead. Apostrophes,
 * quotes, and angle brackets in symptom names (patient-controlled, via
 * ConsultationController::store, which validates symptoms_payload only as
 * `required|string` with no per-entry validation) then reach the
 * single-quoted HTML attribute unescaped.
 *
 * These tests parse the ACTUAL rendered HTML with a real DOM parser and
 * assert the attribute value round-trips to the original strings — not
 * merely that a dangerous substring is absent, which would pass even if
 * the attribute were simply truncated (data loss) rather than escaped
 * (correctness).
 */
function renderChartComponent(array $labels, array $datasets = null): string
{
    $datasets ??= [['label' => 'Requests', 'data' => array_fill(0, count($labels), 1)]];

    return view('components.dash.chart', [
        'title' => 'Test chart',
        'chartId' => 'test-chart',
        'type' => 'hbar',
        'labels' => $labels,
        'datasets' => $datasets,
        'attributes' => new \Illuminate\View\ComponentAttributeBag([]),
    ])->render();
}

function extractChartPayload(string $html): array
{
    $dom = new \DOMDocument();
    // Symptom names may contain characters DOMDocument would otherwise warn
    // about outside a full HTML document; suppress warnings, not errors.
    libxml_use_internal_errors(true);
    $dom->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR);
    libxml_use_internal_errors(false);

    $canvas = $dom->getElementsByTagName('canvas')->item(0);

    expect($canvas)->not->toBeNull('The chart canvas element did not parse — the HTML is structurally broken.');

    $rawAttribute = $canvas->getAttribute('data-chart-payload');
    $decoded = json_decode($rawAttribute, true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE, 'The data-chart-payload attribute (after HTML entity decoding) is not valid JSON: ' . $rawAttribute);

    return $decoded;
}

it('round-trips a symptom name containing an apostrophe through the rendered attribute unchanged', function () {
    $label = "Can't sleep";
    $html = renderChartComponent([$label]);

    $payload = extractChartPayload($html);

    expect($payload['labels'][0])->toBe($label);
});

it('round-trips a symptom name containing a double quote through the rendered attribute unchanged', function () {
    $label = 'Pain "sharp" in chest';
    $html = renderChartComponent([$label]);

    $payload = extractChartPayload($html);

    expect($payload['labels'][0])->toBe($label);
});

it('neutralizes an attribute-injection attempt in a symptom name without executing or truncating it', function () {
    $malicious = "x' onmouseover='alert(1)";
    $html = renderChartComponent([$malicious]);

    // Structural proof: the canvas element parses as exactly one element
    // with no injected onmouseover attribute anywhere in the document —
    // if the injection worked, a real onmouseover attribute would exist.
    $dom = new \DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR);
    libxml_use_internal_errors(false);

    $canvas = $dom->getElementsByTagName('canvas')->item(0);
    expect($canvas->hasAttribute('onmouseover'))->toBeFalse();

    // And the payload survives intact as data, not as markup.
    $payload = extractChartPayload($html);
    expect($payload['labels'][0])->toBe($malicious);
});

it('round-trips angle brackets in a symptom name without breaking canvas markup', function () {
    $label = '<script>alert(1)</script>';
    $html = renderChartComponent([$label]);

    $dom = new \DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR);
    libxml_use_internal_errors(false);

    // No script element was injected into the document.
    expect($dom->getElementsByTagName('script')->length)->toBe(0);

    $payload = extractChartPayload($html);
    expect($payload['labels'][0])->toBe($label);
});

it('round-trips an ampersand in a symptom name unchanged', function () {
    $label = 'Nausea & Vomiting';
    $html = renderChartComponent([$label]);

    $payload = extractChartPayload($html);

    expect($payload['labels'][0])->toBe($label);
});

it('round-trips multiple dangerous labels together with correct positional order', function () {
    $labels = ["Can't sleep", 'Pain "here"', '<b>bold</b>', 'A & B'];
    $html = renderChartComponent($labels);

    $payload = extractChartPayload($html);

    expect($payload['labels'])->toBe($labels);
});

it('still round-trips the datasets alongside the labels — the fix must not drop the second array key', function () {
    $labels = ["Can't sleep", 'Headache'];
    $datasets = [['label' => "Nurse's Requests", 'data' => [3, 5]]];
    $html = renderChartComponent($labels, $datasets);

    $payload = extractChartPayload($html);

    expect($payload['labels'])->toBe($labels)
        ->and($payload['datasets'])->toBe($datasets);
});
