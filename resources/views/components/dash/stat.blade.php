@props([
    'label',
    'value',
    'supporting' => null,
    'tone' => 'neutral', // neutral | critical | active
    'href' => null,
])

@php
    $toneClasses = match ($tone) {
        'critical' => 'border-red-200 bg-red-50',
        'active' => 'border-brand-green bg-brand-green',
        default => 'border-brand-border bg-white',
    };
    $valueClasses = $tone === 'active' ? 'text-white' : 'text-slate-900';
    $labelClasses = $tone === 'active' ? 'text-white/80' : ($tone === 'critical' ? 'text-red-700' : 'text-slate-500');
    $supportingClasses = $tone === 'active' ? 'text-white/70' : 'text-slate-500';
    $tag = $href ? 'a' : 'div';
@endphp

{{-- Interactivity is opt-in via $href only — a non-linked stat card must
     never look clickable (no cursor-pointer, no hover lift), per Phase 2's
     "no fake interactivity" rule. --}}
<{{ $tag }}
    @if ($href) href="{{ $href }}" @endif
    {{ $attributes->merge([
        'class' => trim("rounded-xl border p-4 transition $toneClasses " . ($href ? 'cursor-pointer hover:border-brand-green hover:shadow-sm' : '')),
    ]) }}
>
    <p class="text-xs font-semibold uppercase tracking-wide {{ $labelClasses }}">{{ $label }}</p>
    <p class="mt-2 text-3xl font-bold tabular-nums {{ $valueClasses }}">{{ $value }}</p>
    @if ($supporting)
        <p class="mt-1 text-xs {{ $supportingClasses }}">{{ $supporting }}</p>
    @endif
    {{ $slot }}
</{{ $tag }}>
