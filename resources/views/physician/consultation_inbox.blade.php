<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Consultation Inbox') }}
        </h2>
    </x-slot>

    <script>
        window.physicianInboxData = @json($physicianInboxData);

        function physicianConsultationInbox(initialData, refreshUrl) {
            return {
                activeTab: 'normal',
                showModal: false,
                selectedConsultation: null,
                normalPriorityConsultations: initialData.normalPriorityConsultations || [],
                highPriorityConsultations: initialData.highPriorityConsultations || [],
                refreshUrl,
                pollTimer: null,
                init() {
                    if (this.pollTimer) {
                        return;
                    }

                    this.poll();
                    this.pollTimer = window.setInterval(() => this.poll(), 5000);
                },
                get normalCount() {
                    return this.normalPriorityConsultations.length;
                },
                get highCount() {
                    return this.highPriorityConsultations.length;
                },
                get consultations() {
                    return [
                        ...this.normalPriorityConsultations,
                        ...this.highPriorityConsultations,
                    ];
                },
                poll() {
                    $.ajax({
                        url: this.refreshUrl,
                        method: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        success: (data) => {
                            this.normalPriorityConsultations = Array.isArray(data?.normalPriorityConsultations) ? data.normalPriorityConsultations : [];
                            this.highPriorityConsultations = Array.isArray(data?.highPriorityConsultations) ? data.highPriorityConsultations : [];
                            this.syncSelectedConsultation();
                        }
                    });
                },
                syncSelectedConsultation() {
                    if (!this.showModal || !this.selectedConsultation) {
                        return;
                    }

                    const selectedRequestId = Number(this.selectedConsultation.request_id);
                    const updatedConsultation = this.consultations.find((consultation) => Number(consultation.request_id) === selectedRequestId);

                    if (updatedConsultation) {
                        this.selectedConsultation = updatedConsultation;
                        return;
                    }

                    this.closeModal();
                },
                openModal(requestId) {
                    this.selectedConsultation = this.consultations.find((consultation) => Number(consultation.request_id) === Number(requestId)) || null;
                    this.showModal = Boolean(this.selectedConsultation);
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
                summarizeSymptoms(symptoms) {
                    if (!symptoms) {
                        return '{{ __('N/A') }}';
                    }

                    if (Array.isArray(symptoms)) {
                        const labels = symptoms
                            .map((item) => typeof item === 'object' ? (item.name ?? item['name'] ?? '') : item)
                            .filter(Boolean);

                        return labels.length ? labels.join(', ') : '{{ __('N/A') }}';
                    }

                    return symptoms;
                },
                requestStatusBadgeClass(status) {
                    const classes = {
                        reviewed: 'text-yellow-700 bg-yellow-100',
                        assigned: 'text-yellow-700 bg-yellow-100',
                        scheduled: 'text-indigo-700 bg-indigo-100',
                        active: 'text-green-700 bg-green-100',
                    };

                    return classes[status] || 'text-gray-700 bg-gray-100';
                },
                priorityBadgeClass(priority) {
                    return priority === 'High'
                        ? 'text-red-700 bg-red-100'
                        : 'text-blue-700 bg-blue-100';
                },
                formatSymptoms(symptoms) {
                    if (!symptoms) return '';
                    if (Array.isArray(symptoms)) {
                        return symptoms.map((item) => {
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
                                    this.poll();
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
                scheduleConsultation(slotId) {
                    if (!this.selectedConsultation?.schedule_url) {
                        Swal.fire('Error', 'Unable to find the schedule URL.', 'error');
                        return;
                    }

                    const csrfToken = $('meta[name="csrf-token"]').attr('content');

                    if (!csrfToken) {
                        Swal.fire('Error', 'Missing CSRF token.', 'error');
                        return;
                    }

                    $.ajax({
                        url: this.selectedConsultation.schedule_url,
                        type: 'POST',
                        contentType: 'application/json',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        data: JSON.stringify({
                            physician_id: {{ $physician->user_id }},
                            slot_id: Number(slotId)
                        }),
                        dataType: 'json',
                        success: (data) => {
                            if (data.success) {
                                Swal.fire('Scheduled!', data.message, 'success').then(() => {
                                    this.poll();
                                });
                            } else {
                                Swal.fire('Error', data.message || 'Unable to schedule consultation.', 'error');
                            }
                        },
                        error: (xhr) => {
                            const message = xhr.responseJSON?.message || 'Unable to schedule consultation.';
                            Swal.fire('Error', message, 'error');
                        }
                    });
                },
                promptScheduleSlot() {
                    if (!this.selectedConsultation?.available_slots_url) {
                        Swal.fire('Error', 'Unable to load available slots.', 'error');
                        return;
                    }

                    $.ajax({
                        url: this.selectedConsultation.available_slots_url,
                        type: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        dataType: 'json',
                        success: (data) => {
                            const slots = Array.isArray(data?.slots) ? data.slots : [];

                            if (!slots.length) {
                                Swal.fire({
                                    title: 'No Available Slots Today',
                                    text: 'Create available slots for today first.',
                                    icon: 'info',
                                    showCancelButton: true,
                                    confirmButtonText: 'Go To Schedule Slots',
                                    cancelButtonText: 'Close'
                                }).then((result) => {
                                    if (result.isConfirmed && data?.manage_schedule_url) {
                                        window.location.href = data.manage_schedule_url;
                                    }
                                });
                                return;
                            }

                            const options = slots.reduce((carry, slot) => {
                                carry[String(slot.slot_id)] = `${slot.label} (${slot.slot_date})`;
                                return carry;
                            }, {});

                            Swal.fire({
                                title: 'Select Schedule Slot',
                                input: 'select',
                                inputOptions: options,
                                inputPlaceholder: 'Select an available slot',
                                showCancelButton: true,
                                confirmButtonText: 'Assign Slot',
                                inputValidator: (value) => {
                                    if (!value) {
                                        return 'Please select a slot.';
                                    }
                                }
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    this.scheduleConsultation(result.value);
                                }
                            });
                        },
                        error: (xhr) => {
                            const message = xhr.responseJSON?.message || 'Unable to load available slots.';
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
                                    this.poll();
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
            };
        }
    </script>

    <div class="py-12" x-data="physicianConsultationInbox(window.physicianInboxData, '{{ route('physician.consultation_inbox.refresh', ['physician' => $physician]) }}')" x-init="init()" @keydown.escape.window="closeModal()">
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
                            {{ __('Normal Priority') }} (<span x-text="normalCount"></span>)
                        </button>
                        <button
                            type="button"
                            @click="activeTab = 'high'"
                            :class="activeTab === 'high' ? 'bg-red-600 text-white shadow-sm' : 'bg-white text-gray-700 hover:bg-gray-100'"
                            class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold transition"
                        >
                            {{ __('High Priority') }} (<span x-text="highCount"></span>)
                        </button>
                    </div>

                    <div x-show="activeTab === 'normal'" x-cloak>
                        <p class="text-sm text-gray-500" x-show="normalCount === 0">{{ __('No assigned consultations with normal priority.') }}</p>
                        <div class="overflow-x-auto" x-show="normalCount > 0">
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
                                    <template x-for="consultation in normalPriorityConsultations" :key="`normal-${consultation.request_id}`">
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="consultation.patient_name"></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="summarizeSymptoms(consultation.symptoms_desc)"></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="consultation.assigned_nurse_name || '{{ __('Unassigned') }}'"></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="consultation.submitted_at || '{{ __('Unknown') }}'"></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="requestStatusBadgeClass(consultation.request_status)" x-text="consultation.request_status ? consultation.request_status.charAt(0).toUpperCase() + consultation.request_status.slice(1) : '{{ __('N/A') }}'"></span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="priorityBadgeClass(consultation.priority_level)" x-text="consultation.priority_level || '{{ __('Normal') }}'"></span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <button type="button" @click="openModal(consultation.request_id)" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-xs font-semibold rounded-md hover:bg-indigo-700 transition">
                                                    {{ __('Review') }}
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div x-show="activeTab === 'high'" x-cloak>
                        <p class="text-sm text-gray-500" x-show="highCount === 0">{{ __('No assigned consultations with high priority.') }}</p>
                        <div class="overflow-x-auto" x-show="highCount > 0">
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
                                    <template x-for="consultation in highPriorityConsultations" :key="`high-${consultation.request_id}`">
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="consultation.patient_name"></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="summarizeSymptoms(consultation.symptoms_desc)"></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="consultation.assigned_nurse_name || '{{ __('Unassigned') }}'"></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="consultation.submitted_at || '{{ __('Unknown') }}'"></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="requestStatusBadgeClass(consultation.request_status)" x-text="consultation.request_status ? consultation.request_status.charAt(0).toUpperCase() + consultation.request_status.slice(1) : '{{ __('N/A') }}'"></span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="priorityBadgeClass(consultation.priority_level)" x-text="consultation.priority_level || '{{ __('High') }}'"></span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <button type="button" @click="openModal(consultation.request_id)" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-xs font-semibold rounded-md hover:bg-indigo-700 transition">
                                                    {{ __('Review') }}
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
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

                    <div class="rounded-xl border border-gray-200 bg-indigo-50 p-3" x-show="selectedConsultation?.scheduled_slot" x-cloak>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-indigo-600">{{ __('Scheduled Slot') }}</p>
                        <p class="mt-2 text-sm font-semibold text-indigo-900" x-text="selectedConsultation?.scheduled_slot ? `${selectedConsultation.scheduled_slot.slot_date} ${selectedConsultation.scheduled_slot.label}` : ''"></p>
                        <p class="mt-1 text-xs text-indigo-700" x-text="selectedConsultation?.can_start_message || ''"></p>
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
                    <template x-if="selectedConsultation && ['reviewed', 'assigned', 'scheduled'].includes(selectedConsultation.request_status)">
                        <button type="button" @click="promptScheduleSlot()"
                        class="inline-flex justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700">
                            {{ __('Schedule') }}
                        </button>
                    </template>
                    <template x-if="selectedConsultation && ['reviewed', 'assigned', 'scheduled'].includes(selectedConsultation.request_status)">
                        <button type="button" @click="Swal.fire({
                            title: 'Start Consultation',
                            text: selectedConsultation?.can_start ? 'Are you sure you want to start this consultation?' : (selectedConsultation?.can_start_message || 'You cannot start this consultation yet.'),
                            icon: 'question',
                            showConfirmButton: selectedConsultation?.can_start,
                            showCancelButton: true,
                            confirmButtonText: 'Yes, start it!',
                            cancelButtonText: 'Cancel'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                startConsultation();
                            }
                        })"
                        :disabled="!selectedConsultation?.can_start"
                        :class="selectedConsultation?.can_start ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-slate-400 cursor-not-allowed'"
                        class="inline-flex justify-center rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700">
                            {{ __('Start') }}
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
