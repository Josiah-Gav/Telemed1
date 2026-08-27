{{--
    Standalone, print-oriented PDF view for the dashboard export (Phase 4).
    Rendered by dompdf via Pdf::loadView(), NOT by the browser — so it
    deliberately uses none of the application's normal frontend stack: no
    x-app-layout, no Tailwind, no Alpine, no Chart.js, no flexbox, no grid.
    Dompdf supports only a fragment of CSS 2.1; tables are the layout
    primitive here, not a stylistic choice.

    Every value rendered below comes from DashboardExportRows::forRole(),
    the same structured sections the CSV export consumes — this view never
    recomputes a metric, re-queries, or reformats a null. That is what keeps
    the two formats in agreement by construction rather than by discipline.

    Charts are rendered as ordinary tables. The dashboard's own chart
    component (resources/views/components/dash/chart.blade.php) already
    ships an accessible <table> fallback of the identical labels/datasets,
    so a tabular PDF reuses an existing representation rather than inventing
    a lesser one.

    Font: DejaVu Sans is dompdf's bundled Unicode face and is required here,
    not cosmetic. The em dash is load-bearing in this export — it separates
    nested metric labels ("Completion Rate — Rate"), appears in the
    "Operational — Current State (Not Date-Filtered)" heading, and is the
    rendering of a null rate that must never read as 0. Dompdf's default
    core fonts (Helvetica/Times) do not carry it reliably.
--}}
@php
    // forRole() always returns the meta block first (see its construction
    // order); it is rendered as the report letterhead rather than as
    // another body table, while every remaining section renders uniformly.
    $metaSection = $sections[0] ?? null;
    $bodySections = array_slice($sections, 1);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $metaSection['title'] ?? 'Telemed Dashboard Export' }}</title>
    <style>
        @page {
            margin: 20mm 15mm 18mm 15mm;
        }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9pt;
            line-height: 1.4;
            color: #1f2937;
            margin: 0;
        }

        /* Dompdf repeats position:fixed blocks on every page. */
        .page-footer {
            position: fixed;
            bottom: -12mm;
            left: 0;
            right: 0;
            font-size: 7.5pt;
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
            font-size: 16pt;
            font-weight: bold;
            margin: 0 0 2pt 0;
            color: #111827;
        }

        .subtitle {
            font-size: 8pt;
            color: #6b7280;
            margin: 0 0 10pt 0;
            padding-bottom: 8pt;
            border-bottom: 1.5pt solid #111827;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        table.meta {
            margin-bottom: 14pt;
        }

        table.meta td {
            border: none;
            padding: 1.5pt 0;
            font-size: 8.5pt;
        }

        table.meta td.meta-label {
            width: 28%;
            color: #6b7280;
            text-transform: uppercase;
            font-size: 7.5pt;
            letter-spacing: 0.4pt;
        }

        table.meta td.meta-value {
            font-weight: bold;
            color: #111827;
        }

        .section {
            margin-bottom: 14pt;
        }

        h2 {
            font-size: 10.5pt;
            font-weight: bold;
            margin: 0 0 1pt 0;
            padding: 4pt 6pt;
            background-color: #f3f4f6;
            border-left: 3pt solid #166534;
            color: #111827;
        }

        .section-description {
            font-size: 7.5pt;
            color: #6b7280;
            font-style: italic;
            margin: 3pt 0 4pt 0;
        }

        table.data th {
            background-color: #e5e7eb;
            border: 0.5pt solid #d1d5db;
            padding: 3.5pt 6pt;
            text-align: left;
            font-size: 8pt;
            font-weight: bold;
            color: #374151;
        }

        table.data td {
            border: 0.5pt solid #e5e7eb;
            padding: 3pt 6pt;
            font-size: 8.5pt;
            vertical-align: top;
        }

        /* Label column reads left; every value column reads right, so
           figures line up down the page without per-cell type sniffing. */
        table.data th.value-col,
        table.data td.value-col {
            text-align: right;
        }

        .empty-note {
            font-size: 8pt;
            color: #6b7280;
            font-style: italic;
            padding: 4pt 0;
            margin: 0;
        }
    </style>
</head>
<body>

<div class="page-footer">
    <table>
        <tr>
            <td style="border: none; padding: 0; font-size: 7.5pt; color: #6b7280;">
                {{ $metaSection['title'] ?? 'Telemed Dashboard Export' }} &middot; Confidential
            </td>
            <td class="footer-right" style="border: none; padding: 0; font-size: 7.5pt; color: #6b7280;">
                Page <span class="page-number"></span>
            </td>
        </tr>
    </table>
</div>

@if($metaSection)
    <h1>{{ $metaSection['title'] }}</h1>
    <p class="subtitle">CLSU Infirmary Telemedicine &mdash; Dashboard Analytics Report</p>

    <table class="meta">
        @foreach($metaSection['rows'] as $row)
            <tr>
                <td class="meta-label">{{ $row[0] ?? '' }}</td>
                <td class="meta-value">{{ $row[1] ?? '' }}</td>
            </tr>
        @endforeach
    </table>
@endif

@foreach($bodySections as $section)
    <div class="section">
        <h2>{{ $section['title'] }}</h2>

        @if(!empty($section['description']))
            <p class="section-description">{{ $section['description'] }}</p>
        @endif

        @if(empty($section['rows']))
            <p class="empty-note">{{ __('No data for this section.') }}</p>
        @else
            <table class="data">
                @if(!empty($section['headers']))
                    {{-- <thead> lets dompdf repeat the header row when a
                         long table breaks across pages. --}}
                    <thead>
                        <tr>
                            @foreach($section['headers'] as $i => $header)
                                <th class="{{ $i > 0 ? 'value-col' : '' }}">{{ $header }}</th>
                            @endforeach
                        </tr>
                    </thead>
                @endif
                <tbody>
                    @foreach($section['rows'] as $row)
                        <tr>
                            @foreach($row as $i => $cell)
                                <td class="{{ $i > 0 ? 'value-col' : '' }}">{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endforeach

</body>
</html>
