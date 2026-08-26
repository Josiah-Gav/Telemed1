@props(['message', 'tone' => 'neutral', 'action' => null])

@php
    $isPositive = $tone === 'positive';
@endphp

<div class="rounded-xl border p-6 text-center {{ $isPositive ? 'border-brand-green/40 bg-brand-green-soft' : 'border-dashed border-slate-300 bg-slate-50' }}">
    @if ($isPositive)
        <svg class="mx-auto h-6 w-6 text-brand-green" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
        </svg>
    @endif
    <p class="{{ $isPositive ? 'text-brand-green-deep' : 'text-slate-500' }} {{ $isPositive ? 'mt-2' : '' }} text-sm font-medium">{{ $message }}</p>
    @if ($action)
        <div class="mt-3">{{ $action }}</div>
    @endif
</div>
