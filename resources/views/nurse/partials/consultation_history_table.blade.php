@if($historyConsultations->isEmpty())
    <x-dash.empty message="No consultation history found for the selected filters." />
@else
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Patient Name') }}</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Priority') }}</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Assigned Physician') }}</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Consultation Type') }}</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Status') }}</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Completed At') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
                @foreach($historyConsultations as $consultation)
                    <tr>
                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                            {{ optional($consultation->patient)->first_name ? optional($consultation->patient)->first_name . ' ' . optional($consultation->patient)->last_name : __('Unknown Patient') }}
                        </td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm">
                            <x-dash.badge :priority="$consultation->priority_level" />
                        </td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                            {{ trim(optional($consultation->physician)->first_name . ' ' . optional($consultation->physician)->last_name) ?: __('Unassigned') }}
                        </td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                            {{ $consultation->type === 'follow_up' ? __('Follow-up') : __('General') }}
                        </td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm">
                            <x-dash.badge :status="$consultation->request_status" />
                        </td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                            {{ optional(optional($consultation->consultationSession)->completed_at)->format('M. j, Y g:i A') ?? optional($consultation->updated_at)->format('M. j, Y g:i A') ?? __('Unknown') }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
