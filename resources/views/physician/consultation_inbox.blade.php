<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Consultation Inbox') }}
        </h2>
    </x-slot>

    @php
        $currentPhysicianId = auth()->user()->user_id;

        $allAssignedConsultations = $normalPriorityConsultations
            ->concat($highPriorityConsultations)
            ->unique('request_id')
            ->values();

        $assignedConsultationsJson = $allAssignedConsultations->map(function ($consultation) use ($currentPhysicianId) {
            return [
                'request_id' => $consultation->request_id,
                'patient_name' => trim(optional($consultation->patient)->first_name . ' ' . optional($consultation->patient)->last_name) ?: 'Unknown Patient',
                'assigned_nurse_name' => trim(optional($consultation->nurse)->first_name . ' ' . optional($consultation->nurse)->last_name) ?: 'Unassigned',
                'concern_category' => $consultation->concern_category,
                'submitted_at' => $consultation->submitted_at ? $consultation->submitted_at->format('Y-m-d H:i') : null,
                'request_status' => $consultation->request_status,
                'priority_level' => $consultation->priority_level,
                'symptoms_desc' => $consultation->symptoms_desc,
                'online_reason' => $consultation->online_reason,
                'reject_url' => route('physician.consultations.reject_reviewed', ['physician' => $currentPhysicianId, 'consultation' => $consultation]),
                'start_url' => route('physician.consultations.start', ['physician' => $currentPhysicianId, 'consultation' => $consultation]),
                'file_attachments' => array_values($consultation->file_attachments ?? []),
            ];
        })->toArray();
    @endphp

    <script>
        window.assignedConsultations = @json($assignedConsultationsJson);

        function physicianConsultationInbox(consultations) {
            return {
                activeTab: 'normal',
                showModal: false,
                selectedConsultation: null,
                consultations: consultations,
                openModal(requestId) {
                    this.selectedConsultation = this.consultations.find(consultation => consultation.request_id === requestId);
                    this.showModal = true;
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
                },
                startConsultation() {
                    if (!this.selectedConsultation?.start_url) {
                        Swal.fire('Error', 'Unable to find the start consultation URL.', 'error');
                        return;
                    }

                    const csrfToken = $('meta[name="csrf-token"]').attr('content');

                    if (!csrfToken) {
                        Swal.fire('Error', 'Missing CSRF token.', 'error');
                        return;
                    }

                    $.ajax({
                        url: this.selectedConsultation.start_url,
                        type: 'POST',
                        contentType: 'application/json',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken
                        },
                        data: JSON.stringify({
                            physician_id: {{ $physician->user_id }}
                        }),
                        dataType: 'json',
                        success: (data) => {
                            if (data.success) {
                                Swal.fire({
                                    title: 'Started!',
                                    text: data.message,
                                    icon: 'success',
                                    confirmButtonText: 'OK'
                                }).then(() => {
                                    window.location.reload();
                                });
                            } else {
                                Swal.fire('Error', data.message || 'Something went wrong.', 'error');
                            }
                        },
                        error: (xhr) => {
                            const message = xhr.responseJSON?.message || 'Could not start the consultation.';
                            Swal.fire('Error', message, 'error');
                        }
                    });
                },
                rejectReviewedConsultation(rejectionReason) {
                    if (!this.selectedConsultation?.reject_url) {
                        Swal.fire('Error', 'Unable to find the reject URL.', 'error');
                        return;
                    }

                    const csrfToken = $('meta[name="csrf-token"]').attr('content');
                    if (!csrfToken) {
                        Swal.fire('Error', 'Missing CSRF token.', 'error');
                        return;
                    }

                    $.ajax({
                        url: this.selectedConsultation.reject_url,
                        type: 'POST',
                        contentType: 'application/json',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        data: JSON.stringify({
                            rejection_reason: rejectionReason
                        }),
                        dataType: 'json',
                        success: (data) => {
                            if (data.success) {
                                Swal.fire('Rejected!', data.message, 'success').then(() => {
                                    window.location.reload();
                                });
                            } else {
                                Swal.fire('Error', data.message || 'Unable to reject consultation.', 'error');
                            }
                        },
                        error: (xhr) => {
                            const message = xhr.responseJSON?.message || 'Unable to reject consultation.';
                            Swal.fire('Error', message, 'error');
                        }
                    });
                }
            }
        }
    </script>

    <div class="py-12" x-data="physicianConsultationInbox(window.assignedConsultations)" @keydown.escape.window="closeModal()">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <div class="mb-6 flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 p-2">
                        <button
                            type="button"
                            @click="activeTab = 'normal'"
                            :class="activeTab === 'normal' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-white text-gray-700 hover:bg-gray-100'"
                            class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold transition"
                        >
                            {{ __('Normal Priority') }} ({{ $normalPriorityConsultations->count() }})
                        </button>
                        <button
                            type="button"
                            @click="activeTab = 'high'"
                            :class="activeTab === 'high' ? 'bg-red-600 text-white shadow-sm' : 'bg-white text-gray-700 hover:bg-gray-100'"
                            class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold transition"
                        >
                            {{ __('High Priority') }} ({{ $highPriorityConsultations->count() }})
                        </button>
                    </div>

                    <div x-show="activeTab === 'normal'" x-cloak>
                        @if($normalPriorityConsultations->isEmpty())
                            <p class="text-sm text-gray-500">{{ __('No assigned consultations with normal priority.') }}</p>
                        @else
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Patient Name') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Symptoms') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Assigned Nurse') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Submitted At') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Status') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Priority') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        @foreach($normalPriorityConsultations as $consultation)
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
                                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold text-yellow-700 bg-yellow-100">
                                                        {{ ucfirst($consultation->request_status) }}
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold text-blue-700 bg-blue-100">
                                                        {{ $consultation->priority_level ?? __('Normal') }}
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                    <button type="button" @click="openModal({{ $consultation->request_id }})" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-xs font-semibold rounded-md hover:bg-indigo-700 transition">
                                                        {{ __('Review') }}
                                                    </button>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>

                    <div x-show="activeTab === 'high'" x-cloak>
                        @if($highPriorityConsultations->isEmpty())
                            <p class="text-sm text-gray-500">{{ __('No assigned consultations with high priority.') }}</p>
                        @else
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Patient Name') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Symptoms') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Assigned Nurse') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Submitted At') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Status') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Priority') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        @foreach($highPriorityConsultations as $consultation)
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
                                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold text-yellow-700 bg-yellow-100">
                                                        {{ ucfirst($consultation->request_status) }}
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold text-red-700 bg-red-100">
                                                        {{ $consultation->priority_level ?? __('High') }}
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                    <button type="button" @click="openModal({{ $consultation->request_id }})" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-xs font-semibold rounded-md hover:bg-indigo-700 transition">
                                                        {{ __('Review') }}
                                                    </button>
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

        <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-3 sm:p-6">
            <div class="flex w-[80vw] h-[80vh] sm:h-[40vw] max-h-[90vh] max-w-[72rem] min-h-[22rem] min-w-[18rem] flex-col overflow-hidden rounded-2xl bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ __('Consultation Details') }}</h3>
                        <p class="text-sm text-gray-500">{{ __('Review the selected consultation request.') }}</p>
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

                    <div class="grid gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3 sm:grid-cols-3">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Submitted At') }}</p>
                            <p class="mt-1 text-sm font-medium text-gray-900" x-text="selectedConsultation?.submitted_at ?? '{{ __('Unknown') }}'"></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Status') }}</p>
                            <p class="mt-1 text-sm font-medium text-gray-900" x-text="selectedConsultation?.request_status ? selectedConsultation.request_status.charAt(0).toUpperCase() + selectedConsultation.request_status.slice(1) : '{{ __('N/A') }}'"></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Priority') }}</p>
                            <p class="mt-1 text-sm font-medium text-gray-900" x-text="selectedConsultation?.priority_level ?? '{{ __('N/A') }}'"></p>
                        </div>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Concern Category') }}</p>
                        <p class="mt-2 rounded-lg bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-700" x-text="selectedConsultation?.concern_category ?? '{{ __('N/A') }}'"></p>
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
                                        <a :href="file" target="_blank" rel="noopener noreferrer" class="font-medium text-indigo-600 hover:underline" x-text="decodeURIComponent(file.split('/').pop().split('?')[0])"></a>
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
                    <template x-if="selectedConsultation?.request_status === 'reviewed'">
                        <button type="button" @click="Swal.fire({
                            title: 'Reject Consultation',
                            text: 'Please provide a reason for rejecting this consultation.',
                            icon: 'warning',
                            input: 'textarea',
                            inputPlaceholder: 'Type rejection reason here...',
                            inputAttributes: {
                                'aria-label': 'Type rejection reason here'
                            },
                            showCancelButton: true,
                            confirmButtonColor: '#dc2626',
                            cancelButtonColor: '#6b7280',
                            confirmButtonText: 'Reject',
                            inputValidator: (value) => {
                                if (!value) {
                                    return 'A rejection reason is required.';
                                }
                            }
                        }).then((result) => {
                            if (result.isConfirmed) {
                                rejectReviewedConsultation(result.value);
                            }
                        })" 
                        class="inline-flex justify-center rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-700">
                            {{ __('Reject') }}
                        </button>
                    </template>
                    <template x-if="selectedConsultation && ['reviewed', 'assigned'].includes(selectedConsultation.request_status)">
                        <button type="button" @click="Swal.fire({
                            title: 'Start Consultation',
                            text: 'Are you sure you want to start this consultation?',
                            icon: 'question',
                            showCancelButton: true,
                            confirmButtonText: 'Yes, start it!',
                            cancelButtonText: 'Cancel'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                startConsultation();
                            }
                        })" 
                        class="inline-flex justify-center rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700">
                            {{ __('Start') }}
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <script>
        
    </script>
</x-app-layout>