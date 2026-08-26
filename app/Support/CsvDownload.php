<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only path this application uses to produce a CSV download (Phase 2 of
 * the export feature — see docs/EXPORTS.md once written). Centralizes three
 * things that must never be reimplemented per call site:
 *
 *  - Streaming via response()->streamDownload() + native fputcsv(), so an
 *    export never buffers the whole file in memory regardless of row count.
 *  - A UTF-8 BOM + comma delimiter, so Excel renders non-ASCII text (e.g.
 *    patient-entered symptom names) correctly by default.
 *  - A formula-injection guard applied to every cell of every row before it
 *    reaches fputcsv(). This is the security boundary: symptoms_desc and
 *    other free-text fields flowing into an export are patient-controlled
 *    (ConsultationController::store validates symptoms_payload only as
 *    `required|string`, with no per-entry validation — see SymptomAnalytics'
 *    class docblock), so a cell can legitimately start with =, +, -, or @,
 *    which spreadsheet software treats as "this cell is a formula". The
 *    guard runs here, unconditionally — call sites never opt out of it and
 *    never re-implement it themselves.
 */
final class CsvDownload
{
    /**
     * First-character triggers that a spreadsheet application (Excel,
     * Sheets, Numbers) treats as "evaluate this cell as a formula" rather
     * than as literal text. Prefixing the cell with a single quote forces
     * every one of them to render it as text instead.
     */
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param  string  $filename  Caller-supplied, literal filename (e.g.
     *                            "telemed-dashboard-2026-08-26.csv"). This
     *                            method never reads the current request, so
     *                            it can never derive a filename from
     *                            user-controlled input itself — that
     *                            responsibility stays with the caller.
     * @param  iterable<int, iterable<int|string, mixed>>  $rows  Each element is one CSV row: an
     *                                                            iterable of cell values in column order.
     * @param  list<string>|null  $header  Optional header row, written before $rows if given.
     */
    public static function stream(string $filename, iterable $rows, ?array $header = null): StreamedResponse
    {
        $callback = function () use ($rows, $header): void {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM first, before any row — some parsers require it to
            // be the very first bytes of the file to be recognized at all.
            fwrite($out, "\xEF\xBB\xBF");

            if ($header !== null) {
                fputcsv($out, self::guardRow($header), ',');
            }

            foreach ($rows as $row) {
                fputcsv($out, self::guardRow($row), ',');
            }

            fclose($out);
        };

        return response()->streamDownload($callback, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            // Exports contain PII (patient names, symptoms, outcomes) — never
            // cache the response anywhere along the way.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * @param  iterable<int|string, mixed>  $row
     * @return list<string>
     */
    private static function guardRow(iterable $row): array
    {
        $guarded = [];

        foreach ($row as $cell) {
            $guarded[] = self::guardCell($cell);
        }

        return $guarded;
    }

    private static function guardCell(mixed $cell): string
    {
        $value = (string) ($cell ?? '');

        if ($value !== '' && in_array($value[0], self::DANGEROUS_PREFIXES, true)) {
            return "'".$value;
        }

        return $value;
    }
}
