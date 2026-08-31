<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('Active Consultations') }}
        </h2>
    </x-slot>

    @php
        $activeConsultationsJson = $activeConsultations->map(function ($consultation) use ($onlinePatientIds, $attachmentUrlsByRequestId) {
            $session = $consultation->consultationSession;

            return [
                'request_id' => $consultation->request_id,
                'patient_name' => optional($consultation->patient)->first_name
                    ? optional($consultation->patient)->first_name . ' ' . optional($consultation->patient)->last_name
                    : 'Unknown Patient',
                'patient_is_online' => in_array($consultation->patient_id, $onlinePatientIds, true),
                'assigned_nurse_name' => trim(optional($consultation->nurse)->first_name . ' ' . optional($consultation->nurse)->last_name) ?: 'Unassigned',
                'submitted_at' => $consultation->submitted_at ? $consultation->submitted_at->format('M. j, Y g:i A') : null,
                // Same App\Support\StatusBadge tokens the consultation inbox
                // renders — one map, so a colour change reaches both pages.
                'status_badge' => \App\Support\StatusBadge::status($consultation->request_status),
                'priority_badge' => \App\Support\StatusBadge::priority($consultation->priority_level),
                'priority_level' => $consultation->priority_level,
                'symptoms_desc' => $consultation->symptoms_desc,
                'online_reason' => $consultation->online_reason,
                'additional_information' => $consultation->additional_information,
                'file_attachments' => array_values($attachmentUrlsByRequestId[$consultation->request_id] ?? []),
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
                previewFile: null,
                openModal(requestId) {
                    this.selectedConsultation = this.consultations.find(consultation => consultation.request_id === requestId) || null;
                    this.showModal = !!this.selectedConsultation;
                },
                closeModal() {
                    this.showModal = false;
                    this.selectedConsultation = null;
                },
                openAttachmentPreview(file) {
                    this.previewFile = file;
                },
                closeAttachmentPreview() {
                    this.previewFile = null;
                },
                attachmentName(file) {
                    return decodeURIComponent(file.split('/').pop().split('?')[0]);
                },
                patientInitial(name) {
                    return (name || '?').charAt(0).toUpperCase();
                },
                /**
                 * Renders one badge from the App\Support\StatusBadge tokens
                 * serialized for this consultation — identical to the
                 * consultation inbox's badgeHtml(), so the two pages never
                 * drift. Every value here is a server-side constant.
                 */
                badgeHtml(token, size = 'sm') {
                    if (!token) {
                        return '';
                    }

                    const sizeClasses = size === 'sm' ? 'px-2 py-0.5 text-[11px]' : 'px-2.5 py-1 text-xs';
                    const icon = token.icon_path
                        ? `<svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="${token.icon_path}" /></svg>`
                        : '';

                    return `<span class="inline-flex items-center gap-1 rounded-full font-semibold ${sizeClasses} ${token.classes}">${icon}${token.label}</span>`;
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

    <div class="py-12" x-data="activeConsultationsPage(window.activeConsultationsData)" @keydown.escape.window="previewFile ? closeAttachmentPreview() : closeModal()">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    @if($activeConsultations->isEmpty())
                        <x-dash.empty :message="__('You do not have any active consultations yet.')" />
                    @else
                        <div class="overflow-hidden rounded-xl border border-gray-200">
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Patient Name') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Symptoms') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Submitted At') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Priority') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Status') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 bg-white">
                                        @foreach($activeConsultations as $consultation)
                                            <tr class="transition hover:bg-gray-50">
                                                <td class="whitespace-nowrap px-6 py-4 text-sm">
                                                    <div class="flex items-center gap-3">
                                                        <div class="relative h-9 w-9 flex-shrink-0">
                                                            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-green text-sm font-semibold text-white">
                                                                {{ strtoupper(substr(optional($consultation->patient)->first_name ?? '?', 0, 1)) }}
                                                            </div>
                                                            @php $isPatientOnline = in_array($consultation->patient_id, $onlinePatientIds, true); @endphp
                                                            <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white {{ $isPatientOnline ? 'bg-emerald-500' : 'bg-gray-300' }}">
                                                                <span class="sr-only">{{ $isPatientOnline ? __('Online') : __('Offline') }}</span>
                                                            </span>
                                                        </div>
                                                        <span class="font-medium text-gray-900">{{ optional($consultation->patient)->first_name ? optional($consultation->patient)->first_name . ' ' . optional($consultation->patient)->last_name : __('Unknown Patient') }}</span>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 text-sm text-gray-900">
                                                    @php
                                                        $symptomNames = collect($consultation->symptoms_desc ?? [])
                                                            ->map(fn ($item) => is_array($item) ? ($item['name'] ?? null) : $item)
                                                            ->filter()
                                                            ->values();
                                                        $visibleSymptomNames = $symptomNames->take(3);
                                                        $remainingSymptomsCount = max($symptomNames->count() - 3, 0);
                                                    @endphp
                                                    <div class="flex flex-wrap items-center gap-1.5">
                                                        @forelse($visibleSymptomNames as $symptomName)
                                                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-medium text-slate-700">{{ $symptomName }}</span>
                                                        @empty
                                                            <span class="text-xs text-gray-400">{{ __('N/A') }}</span>
                                                        @endforelse
                                                        @if($remainingSymptomsCount > 0)
                                                            <span class="inline-flex items-center rounded-full bg-slate-200 px-2.5 py-1 text-[11px] font-semibold text-slate-600" title="{{ $symptomNames->slice(3)->implode(', ') }}">
                                                                {{ __('+:count more', ['count' => $remainingSymptomsCount]) }}
                                                            </span>
                                                        @endif
                                                    </div>
                                                </td>
                                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ $consultation->submitted_at ? $consultation->submitted_at->format('M. j, Y g:i A') : __('Unknown') }}</td>
                                                <td class="whitespace-nowrap px-6 py-4 text-sm">
                                                    <x-dash.badge :priority="$consultation->priority_level" size="sm" />
                                                </td>
                                                <td class="whitespace-nowrap px-6 py-4 text-sm">
                                                    @if($consultation->consultationSession)
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $consultation->consultationSession->hasClinicalDocumentation() ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">
                                                                {{ $consultation->consultationSession->hasClinicalDocumentation() ? __('Assessment ready') : __('Assessment pending') }}
                                                            </span>
                                                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $consultation->consultationSession->hasPrescription() ? 'bg-brand-green-soft text-brand-green-deep' : 'bg-slate-100 text-slate-600' }}">
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

                                                <td class="whitespace-nowrap px-6 py-4 text-sm">
                                                    @if($consultation->consultationSession)
                                                        <div class="inline-flex items-center gap-2">
                                                            <button
                                                                type="button"
                                                                @click="openModal({{ $consultation->request_id }})"
                                                                class="inline-flex items-center gap-1 rounded-lg bg-slate-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2"
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
                                                                class="inline-flex items-center gap-1 rounded-lg {{ $consultation->consultationSession->consultation_status === 'completed' ? 'bg-slate-700 hover:bg-slate-800 focus:ring-slate-500' : 'bg-brand-green hover:bg-brand-green-deep focus:ring-brand-green' }} px-3 py-2 text-xs font-semibold text-white transition focus:outline-none focus:ring-2 focus:ring-offset-2"
                                                                aria-label="Open messaging"
                                                                data-session-id="{{ $consultation->consultationSession->id }}"
                                                            >
                                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4" aria-hidden="true">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75h6.75m-6.75 3h4.5m6.375 7.5-3.375-2.025a3.75 3.75 0 0 0-1.928-.525H6.75A3.75 3.75 0 0 1 3 13.95V7.5A3.75 3.75 0 0 1 6.75 3.75h10.5A3.75 3.75 0 0 1 21 7.5v8.25a3.75 3.75 0 0 1-1.5 3z" />
                                                                </svg>
                                                                <span class="hidden rounded-full bg-white px-1.5 py-0.5 text-[10px] font-bold text-brand-green" data-unread-badge="{{ $consultation->consultationSession->id }}">0</span>
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
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div
            x-show="showModal"
            x-cloak
            @click.self="closeModal()"
            x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-3 backdrop-blur-sm sm:p-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="active-consultation-details-heading"
        >
            <div
                x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="flex w-[80vw] h-[80vh] sm:h-[40vw] max-h-[90vh] max-w-[72rem] min-h-[22rem] min-w-[18rem] flex-col overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-black/5"
            >
                <div class="flex items-start justify-between gap-3 border-b border-gray-200 bg-gradient-to-r from-brand-green-soft/60 via-white to-white px-4 py-3 sm:px-5 sm:py-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-brand-green text-white">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l1.5 1.5 3-3.75M9 5.25H7.5A2.25 2.25 0 005.25 7.5v11.25A2.25 2.25 0 007.5 21h9a2.25 2.25 0 002.25-2.25V7.5A2.25 2.25 0 0016.5 5.25H15M9 5.25v1.5A1.5 1.5 0 0010.5 8.25h3A1.5 1.5 0 0015 6.75v-1.5m-6 0h6" />
                            </svg>
                        </div>
                        <div>
                            <h3 id="active-consultation-details-heading" class="text-base font-bold text-gray-900 sm:text-lg">{{ __('Consultation Details') }}</h3>
                            <p class="text-xs text-gray-500 sm:text-sm">{{ __('Review the selected active consultation.') }}</p>
                        </div>
                    </div>
                    <button
                        type="button"
                        @click="closeModal()"
                        class="inline-flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-brand-green focus:ring-offset-2"
                    >
                        <span class="sr-only">{{ __('Close') }}</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="flex-1 space-y-3 overflow-y-auto p-4 sm:p-5">
                    <div class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-3">
                            <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-brand-green text-lg font-semibold text-white"
                                 x-text="patientInitial(selectedConsultation?.patient_name)"></div>
                            <div>
                                <p class="text-sm font-semibold text-gray-900" x-text="selectedConsultation?.patient_name"></p>
                                <p class="mt-0.5 inline-flex items-center gap-1.5 text-xs" :class="selectedConsultation?.patient_is_online ? 'text-emerald-600' : 'text-gray-400'">
                                    <span class="h-1.5 w-1.5 rounded-full" :class="selectedConsultation?.patient_is_online ? 'bg-emerald-500' : 'bg-gray-300'"></span>
                                    <span x-text="selectedConsultation?.patient_is_online ? '{{ __('Online now') }}' : '{{ __('Offline') }}'"></span>
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5 text-xs font-medium text-gray-500 sm:text-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z" />
                            </svg>
                            <span x-text="selectedConsultation?.submitted_at ?? '{{ __('Unknown') }}'"></span>
                        </div>
                    </div>

                    <div class="grid gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3 sm:grid-cols-3">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Request Status') }}</p>
                            <div class="mt-1.5" x-html="badgeHtml(selectedConsultation?.status_badge, 'md')"></div>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Priority Level') }}</p>
                            <div class="mt-1.5" x-html="badgeHtml(selectedConsultation?.priority_badge, 'md')"></div>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Assigned Nurse') }}</p>
                            <p class="mt-1.5 text-sm font-medium text-gray-900" x-text="selectedConsultation?.assigned_nurse_name || '{{ __('Unassigned') }}'"></p>
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
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Additional Information') }}</p>
                        <p class="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-sm leading-6 text-gray-700" x-text="selectedConsultation?.additional_information || '{{ __('No additional information provided.') }}'"></p>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Attachments') }}</p>
                        <template x-if="selectedConsultation?.file_attachments && selectedConsultation.file_attachments.length">
                            <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <template x-for="file in selectedConsultation.file_attachments" :key="file">
                                    <button
                                        type="button"
                                        @click="openAttachmentPreview(file)"
                                        class="group relative h-20 overflow-hidden rounded-xl border border-gray-200 bg-gray-100 shadow-sm transition hover:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green focus:ring-offset-2 sm:h-24"
                                    >
                                        <span class="sr-only" x-text="attachmentName(file)"></span>
                                        <img
                                            :src="file"
                                            :alt="attachmentName(file)"
                                            x-on:error="$el.style.display = 'none'; $el.nextElementSibling.style.display = 'flex';"
                                            class="h-full w-full object-cover transition group-hover:scale-105"
                                        >
                                        <div class="h-full w-full items-center justify-center p-1 text-center text-[10px] text-gray-400" style="display: none;">{{ __('Image unavailable') }}</div>
                                    </button>
                                </template>
                            </div>
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

        <div
            x-show="previewFile"
            x-cloak
            @click.self="closeAttachmentPreview()"
            x-transition:enter="ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/80 p-3 backdrop-blur-sm sm:p-6"
            role="dialog"
            aria-modal="true"
            aria-label="{{ __('Attachment preview') }}"
        >
            <div
                x-effect="if (previewFile) { $nextTick(() => $refs.previewCloseButton?.focus()); }"
                x-transition:enter="ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl"
            >
                <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                    <p class="truncate text-sm font-semibold text-gray-900" x-text="previewFile ? attachmentName(previewFile) : ''"></p>
                    <button
                        type="button"
                        x-ref="previewCloseButton"
                        @click="closeAttachmentPreview()"
                        class="inline-flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-brand-green focus:ring-offset-2"
                    >
                        <span class="sr-only">{{ __('Close') }}</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="flex-1 overflow-auto bg-gray-100 p-2 sm:p-4">
                    <img :src="previewFile" :alt="previewFile ? attachmentName(previewFile) : ''" class="mx-auto max-h-[70vh] w-auto max-w-full rounded-lg object-contain">
                </div>
                <div class="flex justify-end border-t border-gray-200 px-4 py-3">
                    <a :href="previewFile" target="_blank" rel="noopener noreferrer" class="text-sm font-semibold text-brand-green hover:underline">
                        {{ __('Open in new tab') }}
                    </a>
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