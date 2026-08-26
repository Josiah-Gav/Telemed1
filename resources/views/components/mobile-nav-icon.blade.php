@props(['href', 'active' => false, 'path', 'label'])

{{-- Icon-only mobile bottom-nav item. No visible label, so the <a>'s
     aria-label carries the accessible name and the icon is aria-hidden. --}}
<a
    href="{{ $href }}"
    aria-label="{{ __($label) }}"
    title="{{ __($label) }}"
    @if ($active) aria-current="page" @endif
    class="flex min-h-11 flex-1 items-center justify-center rounded-md py-2 {{ $active ? 'bg-clsu-green text-white' : 'text-gray-600' }}"
>
    <svg class="h-6 w-6 stroke-current" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $path }}" />
    </svg>
</a>
