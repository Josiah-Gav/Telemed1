{{--
    Standalone, print-oriented PDF view for the consultation-history export
    (Phase 6) — the same dompdf constraints as exports/dashboard.blade.php:
    no x-app-layout, no Tailwind, no Alpine, no JavaScript, no flexbox/grid.
    Tables are the layout primitive because dompdf only supports a fragment
    of CSS 2.1.

    Every value here comes pre-formatted from ConsultationHistoryRows — this
    view never queries, flattens symptoms, or reformats a date. Landscape
    orientation (set by the controller's setPaper() call) is used instead of
    the dashboard export's portrait, because a history table is wide
    (8–11 columns) rather than the dashboard's narrow metric/value pairs.

    Font: DejaVu Sans, for the same reason as the dashboard export — the em
    dash appears in the title ("Telemed Consultation History Export —
    Patient") and dompdf's default core fonts do not carry it reliably.
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page {
            margin: 14mm 12mm 16mm 12mm;
        }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 8pt;
            line-height: 1.35;
            color: #1f2937;
            margin: 0;
        }

        .page-footer {
            position: fixed;
            bottom: -10mm;
            left: 0;
            right: 0;
            font-size: 7pt;
            color: #6b7280;
            border-top: 0.5pt solid #d1d5db;
            padding-top: 3pt;
        }

        .page-footer .page-number:after {
            content: counter(page);
        }

        .footer-right {
            text-align: right;
        }

        h1 {
            font-size: 14pt;
            font-weight: bold;
            margin: 0 0 2pt 0;
            color: #111827;
        }

        .subtitle {
            font-size: 7.5pt;
            color: #6b7280;
            margin: 0 0 8pt 0;
            padding-bottom: 6pt;
            border-bottom: 1.5pt solid #111827;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        table.meta {
            margin-bottom: 10pt;
        }

        table.meta td {
            border: none;
            padding: 1pt 10pt 1pt 0;
            font-size: 7.5pt;
        }

        table.meta td.meta-label {
            color: #6b7280;
            text-transform: uppercase;
            font-size: 6.5pt;
            letter-spacing: 0.4pt;
            white-space: nowrap;
        }

        table.meta td.meta-value {
            font-weight: bold;
            color: #111827;
        }

        .truncation-warning {
            margin: 0 0 8pt 0;
            padding: 5pt 7pt;
            background-color: #fef3c7;
            border: 0.5pt solid #d97706;
            color: #78350f;
            font-size: 7.5pt;
        }

        table.data th {
            background-color: #e5e7eb;
            border: 0.5pt solid #d1d5db;
            padding: 3pt 5pt;
            text-align: left;
            font-size: 7pt;
            font-weight: bold;
            color: #374151;
        }

        table.data td {
            border: 0.5pt solid #e5e7eb;
            padding: 2.5pt 5pt;
            font-size: 7.5pt;
            vertical-align: top;
            word-wrap: break-word;
        }

        .empty-note {
            font-size: 8pt;
            color: #6b7280;
            font-style: italic;
            padding: 10pt 0;
            margin: 0;
        }
    </style>
</head>
<body>

<div class="page-footer">
    <table>
        <tr>
            <td style="border: none; padding: 0; font-size: 7pt; color: #6b7280;">
                {{ $title }} &middot; Confidential
            </td>
            <td class="footer-right" style="border: none; padding: 0; font-size: 7pt; color: #6b7280;">
                Page <span class="page-number"></span>
            </td>
        </tr>
    </table>
</div>

<h1>{{ $title }}</h1>
<p class="subtitle">CLSU Infirmary Telemedicine &mdash; Consultation History Report</p>

<table class="meta">
    @foreach($meta as $row)
        <tr>
            <td class="meta-label">{{ $row[0] ?? '' }}</td>
            <td class="meta-value">{{ $row[1] ?? '' }}</td>
        </tr>
    @endforeach
</table>

@if($truncated)
    <p class="truncation-warning">
        {{ __('This PDF shows only the first :cap of :total matching records, in the same order as the history page. Download the CSV export for the complete, unlimited list.', ['cap' => number_format($rowCap), 'total' => number_format($totalCount)]) }}
    </p>
@endif

@if(empty($rows))
    <p class="empty-note">{{ __('No records matched the selected filters.') }}</p>
@else
    <table class="data">
        {{-- <thead> lets dompdf repeat the header row when the table breaks across pages. --}}
        <thead>
            <tr>
                @foreach($headers as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    @foreach($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

</body>
</html>
