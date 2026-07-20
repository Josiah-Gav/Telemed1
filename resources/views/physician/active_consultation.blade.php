<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Active Consultations') }}
        </h2>
    </x-slot>

    @php
        $activeConsultationsJson = $activeConsultations->map(function ($consultation) {
            $session = $consultation->consultationSession;

            return [
                'request_id' => $consultation->request_id,
                'patient_name' => optional($consultation->patient)->first_name
                    ? optional($consultation->patient)->first_name . ' ' . optional($consultation->patient)->last_name
                    : 'Unknown Patient',
                'assigned_nurse_name' => trim(optional($consultation->nurse)->first_name . ' ' . optional($consultation->nurse)->last_name) ?: 'Unassigned',
                'submitted_at' => $consultation->submitted_at ? $consultation->submitted_at->format('Y-m-d H:i') : null,
                'priority_level' => $consultation->priority_level,
                'concern_category' => $consultation->concern_category,
                'symptoms_desc' => $consultation->symptoms_desc,
                'online_reason' => $consultation->online_reason,
                'file_attachments' => array_values($consultation->file_attachments ?? []),
                'session_status' => $session?->consultation_status,
                'has_clinical_documentation' => $session?->hasClinicalDocumentation() ?? false,
                'has_prescription' => $session?->hasPrescription() ?? false,
            ];
        })->values()->toArray();
    @endphp

    <script>
        window.activeConsultationsData = @json($activeConsultationsJson);

        function activeConsultationsPage(consultations) {
            return {
                consultations: consultations,
                showModal: false,
                selectedConsultation: null,
                openModal(requestId) {
                    this.selectedConsultation = this.consultations.find(consultation => consultation.request_id === requestId) || null;
                    this.showModal = !!this.selectedConsultation;
                },
                closeModal() {
                    this.showModal = false;
                    this.selectedConsultation = null;
                },
                formatSeverityLabel(severity) {
                    const labels = {
                        1: '1 - Very Mild',
                        2: '2 - Mild',
                        3: '3 - Moderate',
                        4: '4 - Severe',
                    };

                    return labels[severity] || 'N/A';
                },
                formatSymptoms(symptoms) {
                    if (!symptoms) return '';
                    if (Array.isArray(symptoms)) {
                        return symptoms.map(item => {
                            const name = typeof item === 'object' ? (item.name ?? item['name'] ?? '') : item;
                            const severity = typeof item === 'object' ? (item.severity ?? item['severity'] ?? null) : null;
                            const startedDate = typeof item === 'object' ? (item.date ?? item['date'] ?? null) : null;
                            const startedTime = typeof item === 'object' ? (item.time ?? item['time'] ?? null) : null;
                            let severityClass = 'bg-slate-100 text-slate-700';

                            if (severity === 1) {
                                severityClass = 'bg-green-100 text-green-800';
                            } else if (severity === 2) {
                                severityClass = 'bg-yellow-100 text-yellow-800';
                            } else if (severity === 3) {
                                severityClass = 'bg-orange-100 text-orange-800';
                            } else if (severity === 4) {
                                severityClass = 'bg-red-100 text-red-800';
                            }

                            const severityBadge = severity !== null && severity !== undefined && severity !== ''
                                ? `<span class="ml-2 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ${severityClass}">${this.formatSeverityLabel(severity)}</span>`
                                : '';

                            const startedAt = [startedDate, startedTime].filter(Boolean).join(' ').trim();
                            const startedAtText = startedAt
                                ? `<p class="mt-1 w-full text-xs text-slate-500">Started: ${startedAt}</p>`
                                : `<p class="mt-1 w-full text-xs text-slate-400">Started: N/A</p>`;

                            return `<li class="flex items-center flex-wrap gap-2">${name}${severityBadge}${startedAtText}</li>`;
                        }).join('');
                    }

                    return `<li>${symptoms}</li>`;
                }
            }
        }
    </script>

    <div class="py-12" x-data="activeConsultationsPage(window.activeConsultationsData)" @keydown.escape.window="closeModal()">
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
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Status') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Actions') }}</th>
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
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                @if($consultation->consultationSession)
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $consultation->consultationSession->hasClinicalDocumentation() ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">
                                                            {{ $consultation->consultationSession->hasClinicalDocumentation() ? __('Assessment ready') : __('Assessment pending') }}
                                                        </span>
                                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $consultation->consultationSession->hasPrescription() ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-600' }}">
                                                            {{ $consultation->consultationSession->hasPrescription() ? __('Prescription uploaded') : __('No prescription') }}
                                                        </span>
                                                        @if($consultation->consultationSession->consultation_status === 'completed')
                                                            <span class="inline-flex items-center rounded-full bg-slate-900 px-2.5 py-1 text-[11px] font-semibold text-white">{{ __('Completed') }}</span>
                                                        @endif
                                                    </div>
                                                @else
                                                    <span class="text-xs text-gray-500">{{ __('No badges') }}</span>
                                                @endif
                                            </td>

                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                @if($consultation->consultationSession)
                                                    <div class="inline-flex items-center gap-2">
                                                        <button
                                                            type="button"
                                                            @click="openModal({{ $consultation->request_id }})"
                                                            class="inline-flex items-center gap-1 rounded-md bg-slate-600 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-700"
                                                            aria-label="View consultation details"
                                                        >
                                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4" aria-hidden="true">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1 1 0 0 1 0-.644C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178a1 1 0 0 1 0 .644C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" />
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                                            </svg>
                                                            <span class="sr-only">{{ __('View consultation details') }}</span>
                                                        </button>

                                                        <a
                                                            href="{{ route('consultations.messaging.show', $consultation->consultationSession) }}"
                                                            class="inline-flex items-center gap-1 rounded-md {{ $consultation->consultationSession->consultation_status === 'completed' ? 'bg-slate-700 hover:bg-slate-800' : 'bg-indigo-600 hover:bg-indigo-700' }} px-3 py-2 text-xs font-semibold text-white"
                                                            aria-label="Open messaging"
                                                            data-session-id="{{ $consultation->consultationSession->id }}"
                                                        >
                                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4" aria-hidden="true">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75h6.75m-6.75 3h4.5m6.375 7.5-3.375-2.025a3.75 3.75 0 0 0-1.928-.525H6.75A3.75 3.75 0 0 1 3 13.95V7.5A3.75 3.75 0 0 1 6.75 3.75h10.5A3.75 3.75 0 0 1 21 7.5v8.25a3.75 3.75 0 0 1-1.5 3z" />
                                                            </svg>
                                                            <span class="hidden rounded-full bg-white px-1.5 py-0.5 text-[10px] font-bold text-indigo-700" data-unread-badge="{{ $consultation->consultationSession->id }}">0</span>
                                                            <span>{{ $consultation->consultationSession->consultation_status === 'completed' ? __('View') : __('Chat') }}</span>
                                                            <span class="sr-only">{{ __('Open messaging') }}</span>
                                                        </a>
                                                    </div>
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

        <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-3 sm:p-6">
            <div class="flex w-[80vw] h-[80vh] sm:h-[40vw] max-h-[90vh] max-w-[72rem] min-h-[22rem] min-w-[18rem] flex-col overflow-hidden rounded-2xl bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ __('Consultation Details') }}</h3>
                        <p class="text-sm text-gray-500">{{ __('Review the selected active consultation.') }}</p>
                    </div>
                    <button type="button" @click="closeModal()" class="rounded-full p-1.5 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700">
                        <span class="sr-only">{{ __('Close') }}</span>
                        ✕
                    </button>
                </div>

                <div class="flex-1 space-y-3 overflow-y-auto p-4">
                    <div class="grid gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3 sm:grid-cols-2">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Patient Name') }}</p>
                            <p class="mt-1 text-sm font-medium text-gray-900" x-text="selectedConsultation?.patient_name"></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Assigned Nurse') }}</p>
                            <p class="mt-1 text-sm font-medium text-gray-900" x-text="selectedConsultation?.assigned_nurse_name"></p>
                        </div>
                    </div>

                    <div class="grid gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Submitted At') }}</p>
                            <p class="mt-1 text-sm font-medium text-gray-900" x-text="selectedConsultation?.submitted_at ?? '{{ __('Unknown') }}'"></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Priority') }}</p>
                            <p class="mt-1 text-sm font-medium text-gray-900" x-text="selectedConsultation?.priority_level ?? '{{ __('N/A') }}'"></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Concern Category') }}</p>
                            <p class="mt-1 text-sm font-medium text-gray-900" x-text="selectedConsultation?.concern_category ?? '{{ __('N/A') }}'"></p>
                        </div>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Symptoms') }}</p>
                        <template x-if="selectedConsultation?.symptoms_desc">
                            <ul class="mt-2 space-y-2 text-sm text-gray-900" x-html="formatSymptoms(selectedConsultation?.symptoms_desc)"></ul>
                        </template>
                        <p class="mt-2 text-sm text-gray-500" x-show="!selectedConsultation?.symptoms_desc">{{ __('No symptom details provided.') }}</p>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Reason for online consultation') }}</p>
                        <p class="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-sm leading-6 text-gray-700" x-text="selectedConsultation?.online_reason ?? '{{ __('N/A') }}'"></p>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Attachments') }}</p>
                        <template x-if="selectedConsultation?.file_attachments && selectedConsultation.file_attachments.length">
                            <ul class="mt-2 space-y-2 text-sm text-gray-900">
                                <template x-for="file in selectedConsultation.file_attachments" :key="file">
                                    <li class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
                                        <a :href="file" target="_blank" class="font-medium text-indigo-600 hover:underline" x-text="file.split('/').pop()"></a>
                                    </li>
                                </template>
                            </ul>
                        </template>
                        <p class="mt-2 text-sm text-gray-500" x-show="!selectedConsultation?.file_attachments || !selectedConsultation.file_attachments.length">{{ __('No attachments.') }}</p>
                    </div>
                </div>

                <div class="flex border-t border-gray-200 bg-gray-50 px-4 py-3 sm:justify-end">
                    <button type="button" @click="closeModal()" class="inline-flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-100">
                        {{ __('Close') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const unreadUrl = '{{ route('consultations.messaging.unread_counts') }}';

            function updateUnreadBadges() {
                $.ajax({
                    url: unreadUrl,
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    success: function (data) {
                        const counts = data.counts || {};

                        $('[data-unread-badge]').each(function () {
                            const sessionId = String($(this).data('unread-badge'));
                            const count = Number(counts[sessionId] || 0);

                            if (count > 0) {
                                $(this).text(count > 99 ? '99+' : String(count)).removeClass('hidden');
                            } else {
                                $(this).addClass('hidden');
                            }
                        });
                    }
                });
            }

            updateUnreadBadges();
            setInterval(updateUnreadBadges, 6000);
        })();
    </script>
</x-app-layout>