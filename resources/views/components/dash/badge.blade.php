@props(['status' => null, 'priority' => null, 'size' => 'md'])

{{--
    One component, two variants (status / priority) — used identically in
    tables and beside chart legends so the same word always carries the
    same color (dashboards-shared.md §2). Icon + text are always both
    present; nothing here is conveyed by color alone
    (UX guideline "Accessibility / Color Only", severity High).

    The label/color/icon maps live in App\Support\StatusBadge because the
    physician consultation inbox renders its rows with Alpine x-for (for the
    AJAX table refresh) and cannot use this component per row — it binds the
    same tokens from JSON instead. One map, two renderers.
--}}
@php
    $config = $status !== null
        ? \App\Support\StatusBadge::status($status)
        : ($priority !== null ? \App\Support\StatusBadge::priority($priority) : null);

    $sizeClasses = $size === 'sm' ? 'px-2 py-0.5 text-[11px]' : 'px-2.5 py-1 text-xs';
@endphp

@if ($config)
    <span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 rounded-full font-semibold $sizeClasses {$config['classes']}"]) }}>
        @if ($config['icon_path'])
            <svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $config['icon_path'] }}" />
            </svg>
        @endif
        {{ $config['label'] }}
    </span>
@endif
