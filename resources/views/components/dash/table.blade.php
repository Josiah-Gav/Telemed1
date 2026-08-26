@props(['headers' => [], 'rows' => [], 'caption' => null, 'emptyMessage' => 'No data available.'])

@if (count($rows) === 0)
    <x-dash.empty :message="$emptyMessage" />
@else
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            @if ($caption)
                <caption class="sr-only">{{ $caption }}</caption>
            @endif
            <thead>
                <tr class="border-b border-brand-border text-xs uppercase tracking-wide text-slate-500">
                    @foreach ($headers as $header)
                        <th scope="col" class="py-2 pr-4 font-semibold">{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-b border-brand-border/60 last:border-0">
                        @foreach ($row as $cell)
                            <td class="py-2 pr-4 text-slate-700">{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
