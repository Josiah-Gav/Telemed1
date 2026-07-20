<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Consultation History') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    @if($completedConsultations->isEmpty())
                        <p class="text-sm text-gray-500">{{ __('No completed consultations yet.') }}</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Patient Name') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Symptoms') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Assigned Nurse') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Completed At') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Status') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white">
                                    @foreach($completedConsultations as $consultation)
                                        <tr>
                                            <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                                {{ optional($consultation->patient)->first_name ? optional($consultation->patient)->first_name . ' ' . optional($consultation->patient)->last_name : __('Unknown Patient') }}
                                            </td>
                                            <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                                @php
                                                    $symptomsDisplay = __('N/A');
                                                    $symptomsData = $consultation->symptoms_desc;

                                                    if (!empty($symptomsData)) {
                                                        if (is_array($symptomsData)) {
                                                            $symptomsDisplay = collect($symptomsData)
                                                                ->map(function ($item) {
                                                                    return is_array($item) ? ($item['name'] ?? null) : $item;
                                                                })
                                                                ->filter()
                                                                ->implode(', ');
                                                        } else {
                                                            $symptomsDisplay = $symptomsData;
                                                        }
                                                    }
                                                @endphp
                                                {{ $symptomsDisplay }}
                                            </td>
                                            <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                                {{ trim(optional($consultation->nurse)->first_name . ' ' . optional($consultation->nurse)->last_name) ?: __('Unassigned') }}
                                            </td>
                                            <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                                {{ optional(optional($consultation->consultationSession)->completed_at)->format('Y-m-d H:i') ?? optional($consultation->updated_at)->format('Y-m-d H:i') ?? __('Unknown') }}
                                            </td>
                                            <td class="whitespace-nowrap px-6 py-4 text-sm">
                                                <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                                                    {{ __('Completed') }}
                                                </span>
                                            </td>
                                            <td class="whitespace-nowrap px-6 py-4 text-sm">
                                                @if($consultation->consultationSession)
                                                    <a
                                                        href="{{ route('consultations.messaging.show', $consultation->consultationSession) }}"
                                                        class="inline-flex items-center gap-1 rounded-md bg-slate-700 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800"
                                                        aria-label="View consultation record"
                                                    >
                                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4" aria-hidden="true">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75h6.75m-6.75 3h4.5m6.375 7.5-3.375-2.025a3.75 3.75 0 0 0-1.928-.525H6.75A3.75 3.75 0 0 1 3 13.95V7.5A3.75 3.75 0 0 1 6.75 3.75h10.5A3.75 3.75 0 0 1 21 7.5v8.25a3.75 3.75 0 0 1-1.5 3z" />
                                                        </svg>
                                                        <span>{{ __('View record') }}</span>
                                                    </a>
                                                @else
                                                    <span class="text-xs text-gray-500">{{ __('Session unavailable') }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>