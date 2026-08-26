@props(['status' => null, 'priority' => null, 'size' => 'md'])

{{--
    One component, two variants (status / priority) — used identically in
    tables and beside chart legends so the same word always carries the
    same color (dashboards-shared.md §2). Icon + text are always both
    present; nothing here is conveyed by color alone
    (UX guideline "Accessibility / Color Only", severity High).
--}}
@php
    $icons = [
        'clock' => 'M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z',
        'clipboard-check' => 'M9 12.75l1.5 1.5 3-3.75M9 5.25H7.5A2.25 2.25 0 005.25 7.5v11.25A2.25 2.25 0 007.5 21h9a2.25 2.25 0 002.25-2.25V7.5A2.25 2.25 0 0016.5 5.25H15M9 5.25v1.5A1.5 1.5 0 0010.5 8.25h3A1.5 1.5 0 0015 6.75v-1.5m-6 0h6',
        'calendar' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0V11.25A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5',
        'signal' => 'M9.348 14.652a3.75 3.75 0 010-5.304m5.304 0a3.75 3.75 0 010 5.304m-7.425 2.121a6.75 6.75 0 010-9.546m9.546 0a6.75 6.75 0 010 9.546M12 12h.008v.008H12V12z',
        'check-circle' => 'M9 12.75l1.5 1.5 3-3.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'x-circle' => 'M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'minus-circle' => 'M15 12H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z',
        'arrow-up-circle' => 'M8.25 9.75L12 6l3.75 3.75M12 6v12',
    ];

    $statusMap = [
        'pending' => ['label' => 'Pending', 'classes' => 'bg-amber-100 text-amber-800', 'icon' => 'clock'],
        'reviewed' => ['label' => 'Reviewed', 'classes' => 'bg-cyan-100 text-cyan-800', 'icon' => 'clipboard-check'],
        'scheduled' => ['label' => 'Scheduled', 'classes' => 'bg-indigo-100 text-indigo-800', 'icon' => 'calendar'],
        'active' => ['label' => 'Active', 'classes' => 'bg-brand-green text-white', 'icon' => 'signal'],
        'completed' => ['label' => 'Completed', 'classes' => 'bg-slate-100 text-slate-700', 'icon' => 'check-circle'],
        'rejected' => ['label' => 'Rejected', 'classes' => 'bg-red-100 text-red-800', 'icon' => 'x-circle'],
        'cancelled' => ['label' => 'Cancelled', 'classes' => 'border border-dashed border-slate-300 bg-slate-50 text-slate-600', 'icon' => 'minus-circle'],
    ];

    $priorityMap = [
        'High' => ['label' => 'High Priority', 'classes' => 'bg-red-100 text-red-800', 'icon' => 'arrow-up-circle'],
        'Normal' => ['label' => 'Normal Priority', 'classes' => 'bg-slate-100 text-slate-600', 'icon' => null],
    ];

    $config = $status !== null
        ? ($statusMap[$status] ?? null)
        : ($priority !== null ? ($priorityMap[$priority] ?? null) : null);

    $sizeClasses = $size === 'sm' ? 'px-2 py-0.5 text-[11px]' : 'px-2.5 py-1 text-xs';
@endphp

@if ($config)
    <span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 rounded-full font-semibold $sizeClasses {$config['classes']}"]) }}>
        @if ($config['icon'])
            <svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons[$config['icon']] }}" />
            </svg>
        @endif
        {{ $config['label'] }}
    </span>
@endif
