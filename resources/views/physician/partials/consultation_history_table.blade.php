@if($historyConsultations->isEmpty())
    <p class="text-sm text-gray-500">{{ __('No consultation history found for the selected filters.') }}</p>
@else
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Patient Name') }}</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Symptoms') }}</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Assigned Nurse') }}</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Consultation Type') }}</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Completed At') }}</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Status') }}</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
                @foreach($historyConsultations as $consultation)
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
                            @php
                                $consultationTypeLabel = $consultation->type === 'follow_up' ? 'Follow-up' : 'General';
                            @endphp
                            {{ $consultationTypeLabel }}
                        </td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                            {{ optional(optional($consultation->consultationSession)->completed_at)->format('Y-m-d H:i') ?? optional($consultation->updated_at)->format('Y-m-d H:i') ?? __('Unknown') }}
                        </td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm">
                            @php
                                $status = $consultation->request_status;
                                $badgeClasses = 'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ';
                                if (in_array($status, ['rejected', 'cancelled'], true)) {
                                    $badgeClasses .= 'bg-red-100 text-red-700';
                                } elseif ($status === 'completed') {
                                    $badgeClasses .= 'bg-emerald-100 text-emerald-700';
                                } elseif (in_array($status, ['pending', 'assigned'], true)) {
                                    $badgeClasses .= 'bg-yellow-100 text-yellow-700';
                                } elseif ($status === 'scheduled') {
                                    $badgeClasses .= 'bg-brand-gold-soft text-brand-green-deep';
                                } elseif ($status === 'active') {
                                    $badgeClasses .= 'bg-brand-green-soft text-brand-green-deep';
                                } else {
                                    $badgeClasses .= 'bg-slate-100 text-slate-700';
                                }
                            @endphp
                            <span class="{{ $badgeClasses }}">
                                {{ ucfirst($status) }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm">
                            <div class="flex flex-wrap gap-2">
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

                                @php
                                    $hasFollowUp = $consultation->type === 'follow_up';
                                    $completedAt = $consultation->consultationSession?->completed_at ?? $consultation->updated_at;
                                    $isWithinFollowUpWindow = $completedAt && $completedAt->greaterThanOrEqualTo(now()->subDays(7));
                                    $hasExistingFollowUp = (bool) ($consultation->has_existing_follow_up ?? false);
                                @endphp

                                @if($consultation->consultationSession && !$hasFollowUp && !$hasExistingFollowUp && $isWithinFollowUpWindow)
                                    <button
                                        type="button"
                                        data-consultation-id="{{ $consultation->request_id }}"
                                        data-physician-id="{{ $physician->user_id }}"
                                        data-follow-up-url="{{ route('physician.follow_up.create', ['physician' => $physician->user_id, 'session' => $consultation->consultationSession->id]) }}"
                                        data-slots-url="{{ route('physician.follow_up.available_slots', ['physician' => $physician->user_id, 'session' => $consultation->consultationSession->id]) }}"
                                        onclick="scheduleFollowUpFromHistory(this)"
                                        class="inline-flex items-center gap-1 rounded-md bg-brand-green px-3 py-2 text-xs font-semibold text-white hover:bg-brand-green-deep"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2" />
                                        </svg>
                                        <span>{{ __('Schedule Follow-up') }}</span>
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif