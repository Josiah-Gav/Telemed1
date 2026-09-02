@props(['disabled' => false])

@php
    $isPassword = strtolower((string) $attributes->get('type')) === 'password';
@endphp

@if ($isPassword)
    {{-- Password fields get a show/hide toggle via x-password-reveal. Every
         other input type keeps the plain, unwrapped markup below unchanged. --}}
    <x-password-reveal>
        <input
            @disabled($disabled)
            :type="show ? 'text' : 'password'"
            {{ $attributes->except('type')->merge(['class' => 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm']) }}
        >
    </x-password-reveal>
@else
    <input @disabled($disabled) {{ $attributes->merge(['class' => 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm']) }}>
@endif
