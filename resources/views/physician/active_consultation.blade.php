<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Active Consultations') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    @if($activeConsultations->isEmpty())
                        <p class="text-sm text-gray-500">{{ __('You do not have any active consultations yet.') }}</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Patient Name') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Symptoms') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Assigned Nurse') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Submitted At') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Priority') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($activeConsultations as $consultation)
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ optional($consultation->patient)->first_name ? optional($consultation->patient)->first_name . ' ' . optional($consultation->patient)->last_name : __('Unknown Patient') }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
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
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ trim(optional($consultation->nurse)->first_name . ' ' . optional($consultation->nurse)->last_name) ?: __('Unassigned') }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $consultation->submitted_at ? $consultation->submitted_at->format('Y-m-d H:i') : __('Unknown') }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                @if ($consultation->priority_level == 'High')
                                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold text-red-700 bg-red-100">
                                                        {{ $consultation->priority_level ?? __('N/A') }}
                                                    </span>
                                                @elseif ($consultation->priority_level == 'Normal')
                                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold text-yellow-700 bg-yellow-100">
                                                        {{ $consultation->priority_level ?? __('N/A') }}
                                                    </span>
                                                @else
                                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold text-green-700 bg-green-100">
                                                        {{ $consultation->priority_level ?? __('N/A') }}
                                                    </span>
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