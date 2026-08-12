@props(['active'])

@php
$classes = ($active ?? false)
            ? 'flex w-full items-center rounded-xl border border-brand-green bg-brand-green px-3 py-2.5 text-sm font-semibold leading-5 text-white shadow-sm transition duration-150 ease-in-out hover:bg-brand-green-deep focus:outline-none focus:ring-2 focus:ring-brand-green/30'
            : 'flex w-full items-center rounded-xl border border-transparent px-3 py-2.5 text-sm font-medium leading-5 text-slate-700 transition duration-150 ease-in-out hover:bg-brand-green-soft hover:text-brand-green-deep focus:outline-none focus:text-brand-green-deep focus:ring-2 focus:ring-brand-gold/30';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
