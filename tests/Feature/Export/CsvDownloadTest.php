<?php

use App\Support\CsvDownload;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CsvDownload is the sole path this application uses to produce a CSV
 * response (Phase 2 of the export feature). These tests exercise the actual
 * streamed bytes — not the callback in isolation — so a regression in how
 * the BOM, delimiter, or guard interacts with fputcsv() would be caught
 * here rather than only in an end-to-end export test written later.
 */
function captureStreamedBody(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return ob_get_clean();
}

/**
 * Strips the UTF-8 BOM (if present) and parses the remaining bytes back into
 * rows with fgetcsv() against an in-memory stream — this correctly handles
 * quoted fields containing embedded commas/quotes/newlines, which naive
 * line-splitting would not.
 *
 * @return list<list<string|null>>
 */
function parseCsvBody(string $body): array
{
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

it('generates a normal CSV with a header row and data rows', function () {
    $response = CsvDownload::stream('test.csv', [
        ['Alice', '30'],
        ['Bob', '40'],
    ], ['Name', 'Age']);

    $rows = parseCsvBody(captureStreamedBody($response));

    expect($rows)->toBe([
        ['Name', 'Age'],
        ['Alice', '30'],
        ['Bob', '40'],
    ]);
});

it('prefixes the output with a UTF-8 BOM', function () {
    $response = CsvDownload::stream('test.csv', [['a']]);

    $body = captureStreamedBody($response);

    expect(substr($body, 0, 3))->toBe("\xEF\xBB\xBF");
});

it('round-trips a cell containing a comma', function () {
    $response = CsvDownload::stream('test.csv', [['Smith, John', 'value']]);

    $rows = parseCsvBody(captureStreamedBody($response));

    expect($rows[0])->toBe(['Smith, John', 'value']);
});

it('round-trips a cell containing double quotes', function () {
    $response = CsvDownload::stream('test.csv', [['She said "hi" today']]);

    $rows = parseCsvBody(captureStreamedBody($response));

    expect($rows[0])->toBe(['She said "hi" today']);
});

it('round-trips a cell containing an embedded newline', function () {
    $response = CsvDownload::stream('test.csv', [["Line one\nLine two", 'next column']]);

    $rows = parseCsvBody(captureStreamedBody($response));

    expect($rows[0])->toBe(["Line one\nLine two", 'next column']);
});

it('round-trips arbitrary UTF-8 characters unchanged', function () {
    $value = 'Café — 日本語 — señor';
    $response = CsvDownload::stream('test.csv', [[$value]]);

    $rows = parseCsvBody(captureStreamedBody($response));

    expect($rows[0])->toBe([$value]);
});

it('prefixes a cell beginning with each dangerous formula-injection character with a single quote', function (string $prefix) {
    $malicious = $prefix.'cmd|\' /C calc\'!A0';
    $response = CsvDownload::stream('test.csv', [[$malicious]]);

    $rows = parseCsvBody(captureStreamedBody($response));

    expect($rows[0][0])->toBe("'".$malicious);
})->with([
    'equals' => ['='],
    'plus' => ['+'],
    'minus' => ['-'],
    'at' => ['@'],
    'tab' => ["\t"],
    'carriage return' => ["\r"],
]);

it('leaves a cell beginning with ordinary text unchanged', function () {
    $response = CsvDownload::stream('test.csv', [['Headache'], ['Normal symptom, mild']]);

    $rows = parseCsvBody(captureStreamedBody($response));

    expect($rows[0][0])->toBe('Headache')
        ->and($rows[1][0])->toBe('Normal symptom, mild');
});

it('does not guard a numeric-looking negative value beyond the leading quote', function () {
    // A literal negative number is legitimate data, but a leading "-" is
    // still an Excel formula trigger — the guard must apply uniformly
    // regardless of whether the rest of the value "looks like a formula".
    $response = CsvDownload::stream('test.csv', [['-42']]);

    $rows = parseCsvBody(captureStreamedBody($response));

    expect($rows[0][0])->toBe("'-42");
});

it('writes multiple rows in the given order', function () {
    $response = CsvDownload::stream('test.csv', [
        ['1', 'first'],
        ['2', 'second'],
        ['3', 'third'],
    ], ['ID', 'Label']);

    $rows = parseCsvBody(captureStreamedBody($response));

    expect($rows)->toBe([
        ['ID', 'Label'],
        ['1', 'first'],
        ['2', 'second'],
        ['3', 'third'],
    ]);
});

it('handles an empty row set by producing only the header', function () {
    $response = CsvDownload::stream('test.csv', [], ['ID', 'Label']);

    $rows = parseCsvBody(captureStreamedBody($response));

    expect($rows)->toBe([
        ['ID', 'Label'],
    ]);
});

it('handles no header and no rows without error', function () {
    $response = CsvDownload::stream('test.csv', []);

    $body = captureStreamedBody($response);

    expect(parseCsvBody($body))->toBe([])
        ->and(substr($body, 0, 3))->toBe("\xEF\xBB\xBF");
});

it('does not break surrounding rows when one row in the middle is empty', function () {
    $response = CsvDownload::stream('test.csv', [
        ['before'],
        [],
        ['after'],
    ]);

    $body = captureStreamedBody($response);
    $lines = explode("\n", trim(substr($body, 3)));

    // An empty fputcsv() row is a bare line terminator — assert it does not
    // corrupt the rows on either side of it.
    expect(trim($lines[0]))->toBe('before')
        ->and(trim($lines[2]))->toBe('after');
});

it('sends no-store cache-prevention headers', function () {
    $response = CsvDownload::stream('test.csv', [['a']]);

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Pragma'))->toBe('no-cache')
        ->and($response->headers->get('Expires'))->toBe('0');
});

it('sends a CSV content type', function () {
    $response = CsvDownload::stream('test.csv', [['a']]);

    expect($response->headers->get('Content-Type'))->toContain('text/csv');
});

it('uses the caller-supplied filename in the Content-Disposition header without touching request input', function () {
    $response = CsvDownload::stream('my-export-name.csv', [['a']]);

    expect($response->headers->get('Content-Disposition'))->toContain('my-export-name.csv');
});
