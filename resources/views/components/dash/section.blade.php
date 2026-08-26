@props(['title', 'description' => null, 'level' => 'h2', 'id' => null])

<section
    @if ($id) id="{{ $id }}" @endif
    {{ $attributes->merge(['class' => 'space-y-4']) }}
    @if ($id) aria-labelledby="{{ $id }}-heading" @endif
>
    <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <{{ $level }} @if ($id) id="{{ $id }}-heading" @endif class="text-lg font-bold text-slate-900">{{ $title }}</{{ $level }}>
            @if ($description)
                <p class="mt-1 max-w-2xl text-sm text-slate-600">{{ $description }}</p>
            @endif
        </div>
        @isset($action)
            <div>{{ $action }}</div>
        @endisset
    </div>

    {{ $slot }}
</section>
