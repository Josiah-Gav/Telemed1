<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Consultation Inbox') }}
        </h2>
    </x-slot>

    @php
        $serializeRequest = function ($request) {
            return [
                'request_id' => $request->request_id,
                'patient_id' => $request->patient_id,
                'patient_name' => trim(optional($request->patient)->first_name . ' ' . optional($request->patient)->last_name) ?: 'Unknown Patient',
                'patient_is_online' => $request->patient
                    && $request->patient->online_status === 'online'
                    && $request->patient->last_seen_at
                    && $request->patient->last_seen_at->gt(now()->subMinutes(2)),
                'concern_category' => $request->concern_category,
                'submitted_at' => $request->submitted_at ? $request->submitted_at->format('Y-m-d H:i') : null,
                'request_status' => $request->request_status,
                'assigned_nurse_id' => $request->assigned_nurse_id,
                'assigned_nurse_name' => trim(optional($request->nurse)->first_name . ' ' . optional($request->nurse)->last_name) ?: null,
                'assigned_physician_id' => $request->assigned_physician_id,
                'assigned_physician_name' => trim(optional($request->physician)->first_name . ' ' . optional($request->physician)->last_name) ?: null,
                'priority_level' => $request->priority_level,
                'symptoms_desc' => $request->symptoms_desc,
                'online_reason' => $request->online_reason,
                'file_attachments' => array_map(function ($path) use ($request) {
                    return url('/consultations/' . $request->request_id . '/attachments/' . basename($path));
                }, $request->file_attachments ?? []),
            ];
        };

        $nurseInboxData = [
            'pendingRequests' => $pendingRequests->map($serializeRequest)->values()->toArray(),
            'assignedToCurrentNurse' => $assignedToCurrentNurse->map($serializeRequest)->values()->toArray(),
            'assignedToOtherNurses' => $assignedToOtherNurses->map($serializeRequest)->values()->toArray(),
        ];
    @endphp

    <script>
        window.nurseInboxData = @json($nurseInboxData);

        function consultationInbox(initialData, refreshUrl) {
            return {
                showModal: false,
                selectedRequest: null,
                activeTab: 'pending',
                pendingRequests: initialData.pendingRequests || [],
                assignedToCurrentNurse: initialData.assignedToCurrentNurse || [],
                assignedToOtherNurses: initialData.assignedToOtherNurses || [],
                refreshUrl,
                pollTimer: null,
                init() {
                    if (this.pollTimer) {
                        return;
                    }

                    this.poll();
                    this.pollTimer = window.setInterval(() => this.poll(), 5000);
                },
                get pendingCount() {
                    return this.pendingRequests.length;
                },
                get assignedCount() {
                    return this.assignedToCurrentNurse.length + this.assignedToOtherNurses.length;
                },
                get allRequests() {
                    return [
                        ...this.pendingRequests,
                        ...this.assignedToCurrentNurse,
                        ...this.assignedToOtherNurses,
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
                            this.pendingRequests = Array.isArray(data?.pendingRequests) ? data.pendingRequests : [];
                            this.assignedToCurrentNurse = Array.isArray(data?.assignedToCurrentNurse) ? data.assignedToCurrentNurse : [];
                            this.assignedToOtherNurses = Array.isArray(data?.assignedToOtherNurses) ? data.assignedToOtherNurses : [];
                            this.syncSelectedRequest();
                        }
                    });
                },
                syncSelectedRequest() {
                    if (!this.showModal || !this.selectedRequest) {
                        return;
                    }

                    const selectedRequestId = Number(this.selectedRequest.request_id);
                    const updatedRequest = this.allRequests.find((request) => Number(request.request_id) === selectedRequestId);

                    if (updatedRequest) {
                        this.selectedRequest = updatedRequest;
                        return;
                    }

                    this.closeModal();
                },
                setTab(tab) {
                    this.activeTab = tab;
                },
                openModal(requestId) {
                    this.selectedRequest = this.allRequests.find((request) => Number(request.request_id) === Number(requestId)) || null;
                    this.showModal = Boolean(this.selectedRequest);
                },
                closeModal() {
                    this.showModal = false;
                    this.selectedRequest = null;
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
                highestSeverity(symptoms) {
                    if (!Array.isArray(symptoms)) {
                        return null;
                    }

                    const severityValues = symptoms
                        .map((item) => typeof item === 'object' ? Number(item.severity ?? item['severity'] ?? NaN) : NaN)
                        .filter((value) => Number.isFinite(value));

                    if (!severityValues.length) {
                        return null;
                    }

                    return Math.max(...severityValues);
                },
                severityBadgeClass(symptoms) {
                    const severity = this.highestSeverity(symptoms);

                    if (severity === 1) {
                        return 'bg-green-100 text-green-800';
                    }

                    if (severity === 2) {
                        return 'bg-yellow-100 text-yellow-800';
                    }

                    if (severity === 3) {
                        return 'bg-orange-100 text-orange-800';
                    }

                    if (severity === 4) {
                        return 'bg-red-100 text-red-800';
                    }

                    return 'bg-gray-100 text-gray-700';
                },
                severityText(symptoms) {
                    const severity = this.highestSeverity(symptoms);
                    return severity !== null ? this.formatSeverityLabel(severity) : '{{ __('N/A') }}';
                },
                requestStatusBadgeClass(status) {
                    const classes = {
                        pending: 'text-orange-700 bg-orange-100',
                        reviewed: 'text-blue-700 bg-blue-100',
                        assigned: 'text-yellow-700 bg-yellow-100',
                        scheduled: 'text-indigo-700 bg-indigo-100',
                        active: 'text-blue-700 bg-blue-100',
                        completed: 'text-green-900 bg-green-100',
                        cancelled: 'text-red-700 bg-red-100',
                        rejected: 'text-red-700 bg-red-100',
                    };

                    return classes[status] || 'text-gray-700 bg-gray-100';
                },
                priorityBadgeClass(priority) {
                    const classes = {
                        High: 'text-red-700 bg-red-100',
                        Normal: 'text-yellow-700 bg-yellow-100',
                    };

                    return classes[priority] || 'text-gray-700 bg-gray-100';
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
                }
            };
        }
    </script>

    <div class="py-12" x-data="consultationInbox(window.nurseInboxData, '{{ route('nurse.consultation_inbox.refresh', ['nurse' => $nurse]) }}')" x-init="init()" @nurse-inbox-refresh-requested.window="poll()" @keydown.escape.window="closeModal()">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <div class="mb-6 flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 p-2">
                        <button
                            type="button"
                            @click="setTab('pending')"
                            :class="activeTab === 'pending' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-white text-gray-700 hover:bg-gray-100'"
                            class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold transition"
                        >
                            {{ __('Pending') }} (<span x-text="pendingCount"></span>)
                        </button>
                        <button
                            type="button"
                            @click="setTab('assigned')"
                            :class="activeTab === 'assigned' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-white text-gray-700 hover:bg-gray-100'"
                            class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold transition"
                        >
                            {{ __('Assigned') }} (<span x-text="assignedCount"></span>)
                        </button>
                    </div>

                    <div x-show="activeTab === 'pending'" x-cloak>
                        <div class="text-gray-500" x-show="pendingCount === 0">{{ __('No pending consultation requests found.') }}</div>
                        <div class="overflow-x-auto" x-show="pendingCount > 0">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Patient Name') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Symptoms') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Severity') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Submitted At') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Status') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <template x-for="request in pendingRequests" :key="`pending-${request.request_id}`">
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                <span class="inline-flex items-center gap-2">
                                                    <span
                                                        class="inline-block h-[0.625em] w-[0.625em] rounded-full shrink-0"
                                                        :class="request.patient_is_online ? 'bg-emerald-500' : 'bg-slate-300'"
                                                        :title="request.patient_is_online ? 'Online' : 'Offline'"
                                                    ></span>
                                                    <span x-text="request.patient_name"></span>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="summarizeSymptoms(request.symptoms_desc)"></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 font-semibold" :class="severityBadgeClass(request.symptoms_desc)" x-text="severityText(request.symptoms_desc)"></span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="request.submitted_at || '{{ __('Unknown') }}'"></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <span class="inline-flex items-center px-2 py-1 rounded-full font-semibold text-xs" :class="requestStatusBadgeClass(request.request_status)" x-text="request.request_status ? request.request_status.charAt(0).toUpperCase() + request.request_status.slice(1) : '{{ __('N/A') }}'"></span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <button type="button" @click="openModal(request.request_id)" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-xs font-semibold rounded-md hover:bg-indigo-700 transition">
                                                    {{ __('Review') }}
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div x-show="activeTab === 'assigned'" x-cloak class="space-y-6">
                        <div>
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-indigo-700">{{ __('Assigned To Me') }}</h3>
                            <p class="mt-2 text-sm text-gray-500" x-show="assignedToCurrentNurse.length === 0">{{ __('No consultations are currently assigned to you.') }}</p>
                            <div class="mt-3 overflow-x-auto" x-show="assignedToCurrentNurse.length > 0">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Patient Name') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Symptoms') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Severity') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Submitted At') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Status') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Priority') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <template x-for="request in assignedToCurrentNurse" :key="`mine-${request.request_id}`">
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    <span class="inline-flex items-center gap-2">
                                                        <span
                                                            class="inline-block h-[0.625em] w-[0.625em] rounded-full shrink-0"
                                                            :class="request.patient_is_online ? 'bg-emerald-500' : 'bg-slate-300'"
                                                            :title="request.patient_is_online ? 'Online' : 'Offline'"
                                                        ></span>
                                                        <span x-text="request.patient_name"></span>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="summarizeSymptoms(request.symptoms_desc)"></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 font-semibold" :class="severityBadgeClass(request.symptoms_desc)" x-text="severityText(request.symptoms_desc)"></span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="request.submitted_at || '{{ __('Unknown') }}'"></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full font-semibold text-xs" :class="requestStatusBadgeClass(request.request_status)" x-text="request.request_status ? request.request_status.charAt(0).toUpperCase() + request.request_status.slice(1) : '{{ __('N/A') }}'"></span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full font-semibold text-xs" :class="priorityBadgeClass(request.priority_level)" x-text="request.priority_level ? request.priority_level.charAt(0).toUpperCase() + request.priority_level.slice(1) : '{{ __('N/A') }}'"></span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                    <button type="button" @click="openModal(request.request_id)" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-xs font-semibold rounded-md hover:bg-indigo-700 transition">
                                                        {{ __('Review') }}
                                                    </button>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-700">{{ __('Assigned To Other Nurses') }}</h3>
                            <p class="mt-2 text-sm text-gray-500" x-show="assignedToOtherNurses.length === 0">{{ __('No consultations are assigned to other nurses right now.') }}</p>
                            <div class="mt-3 overflow-x-auto" x-show="assignedToOtherNurses.length > 0">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Patient Name') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Assigned Nurse') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Submitted At') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Status') }}</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <template x-for="request in assignedToOtherNurses" :key="`other-${request.request_id}`">
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    <span class="inline-flex items-center gap-2">
                                                        <span
                                                            class="inline-block h-[0.625em] w-[0.625em] rounded-full shrink-0"
                                                            :class="request.patient_is_online ? 'bg-emerald-500' : 'bg-slate-300'"
                                                            :title="request.patient_is_online ? 'Online' : 'Offline'"
                                                        ></span>
                                                        <span x-text="request.patient_name"></span>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="request.assigned_nurse_name || '{{ __('Unassigned') }}'"></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" x-text="request.submitted_at || '{{ __('Unknown') }}'"></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full font-semibold text-xs" :class="requestStatusBadgeClass(request.request_status)" x-text="request.request_status ? request.request_status.charAt(0).toUpperCase() + request.request_status.slice(1) : '{{ __('N/A') }}'"></span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                    <button type="button" @click="openModal(request.request_id)" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-xs font-semibold rounded-md hover:bg-indigo-700 transition">
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
                            <p class="mt-1 text-sm font-medium text-gray-900" x-text="selectedRequest?.patient_name"></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Submitted At') }}</p>
                            <p class="mt-1 text-sm font-medium text-gray-900" x-text="selectedRequest?.submitted_at ?? '{{ __('Unknown') }}'"></p>
                        </div>
                    </div>

                    <div class="grid gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3 sm:grid-cols-2">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Request Status') }}</p>
                            <p class="mt-1 inline-flex items-center rounded-full px-2 py-1 text-sm font-semibold" :class="requestStatusBadgeClass(selectedRequest?.request_status)" x-text="selectedRequest?.request_status ? selectedRequest.request_status.charAt(0).toUpperCase() + selectedRequest.request_status.slice(1) : '{{ __('N/A') }}'"></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Priority Level') }}</p>
                            <p class="mt-1 inline-flex items-center rounded-full px-2 py-1 text-sm font-semibold" :class="priorityBadgeClass(selectedRequest?.priority_level)" x-text="selectedRequest?.priority_level ? selectedRequest.priority_level.charAt(0).toUpperCase() + selectedRequest.priority_level.slice(1) : '{{ __('N/A') }}'"></p>
                        </div>
                    </div>
                    
                    <div class="grid gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3 sm:grid-cols-2">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Assigned Physician') }}</p>
                        <p class="mt-1 text-sm font-medium text-gray-900" x-text="selectedRequest?.assigned_physician_name || '{{ __('Unassigned') }}'"></p>
                    </div>
                    </div>
                    
                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Concern Category') }}</p>
                        <p class="mt-2 rounded-lg bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-700" x-text="selectedRequest?.concern_category ?? '{{ __('N/A') }}'"></p>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Symptoms') }}</p>
                        <template x-if="selectedRequest?.symptoms_desc">
                            <ul class="mt-2 space-y-2 text-sm text-gray-900" x-html="formatSymptoms(selectedRequest?.symptoms_desc)"></ul>
                        </template>
                        <p class="mt-2 text-sm text-gray-500" x-show="!selectedRequest?.symptoms_desc">{{ __('No symptom details provided.') }}</p>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Reason for online consultation') }}</p>
                        <p class="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-sm leading-6 text-gray-700" x-text="selectedRequest?.online_reason ?? '{{ __('N/A') }}'"></p>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-500">{{ __('Attachments') }}</p>
                        <template x-if="selectedRequest?.file_attachments && selectedRequest.file_attachments.length">
                            <ul class="mt-2 space-y-2 text-sm text-gray-900">
                                <template x-for="file in selectedRequest.file_attachments" :key="file">
                                    <li class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
                                        <a :href="file" target="_blank" rel="noopener noreferrer" class="font-medium text-indigo-600 hover:underline" x-text="file.split('/').pop()"></a>
                                    </li>
                                </template>
                            </ul>
                        </template>
                        <p class="mt-2 text-sm text-gray-500" x-show="!selectedRequest?.file_attachments || !selectedRequest.file_attachments.length">{{ __('No attachments.') }}</p>
                    </div>
                </div>

                <div class="flex flex-col gap-2 border-t border-gray-200 bg-gray-50 px-4 py-3 sm:flex-row sm:justify-end">
                    <button type="button" @click="closeModal()" class="inline-flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-100">
                        {{ __('Close') }}
                    </button>
                    <template x-if="selectedRequest?.request_status === 'pending'">
                        <button type="button"
                            @click="Swal.fire({
                                title: 'Reject Consultation Request?',
                                text: 'Please provide a reason for rejecting this consultation:',
                                icon: 'warning',
                                input: 'textarea',
                                inputPlaceholder: 'Type the rejection reason here...',
                                inputAttributes: {
                                    'aria-label': 'Type your rejection reason here'
                                },
                                showCancelButton: true,
                                confirmButtonColor: '#d33',
                                cancelButtonColor: '#6b7280',
                                confirmButtonText: 'Yes, reject it!',
                                cancelButtonText: 'Cancel',
                                inputValidator: (value) => {
                                    if (!value) {
                                        return 'You must provide a reason for rejection!';
                                    }
                                }
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    window.rejectionConsultation(selectedRequest?.request_id, result.value);
                                }
                            })"
                            class="inline-flex justify-center rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-700">
                            {{ __('Reject') }}
                        </button>
                    </template>
                    <template x-if="selectedRequest?.request_status === 'pending'">
                        <button type="button"
                            @click="Swal.fire({
                                title: `{{ __('Approve Consultation Request?') }}`,
                                text: `{{ __('Select a priority level before approving this consultation.') }}`,
                                icon: 'warning',
                                input: 'select',
                                inputOptions: {
                                    High: '{{ __('High') }}',
                                    Normal: '{{ __('Normal') }}'
                                },
                                inputValue: 'Normal',
                                inputPlaceholder: '{{ __('Choose priority level') }}',
                                showCancelButton: true,
                                confirmButtonColor: '#10b981',
                                cancelButtonColor: '#6b7280',
                                confirmButtonText: `{{ __('Approve') }}`,
                                inputValidator: (value) => {
                                    if (!value) {
                                        return '{{ __('You must select a priority level.') }}';
                                    }
                                }
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    window.approveConsultation(selectedRequest?.request_id, result.value);
                                }
                            })"
                            class="inline-flex justify-center rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700">
                            {{ __('Approve') }}
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.rejectionConsultation = function(consultationId, reason) {
            if (!consultationId) {
                Swal.fire('Error', 'Missing consultation request ID. Please reopen the request and try again.', 'error');
                return;
            }

            const csrfToken = $('meta[name="csrf-token"]').attr('content');

            if (!csrfToken) {
                console.error('CSRF token not found. Please ensure <meta name="csrf-token" content="{{ csrf_token() }}"> is in your <head>.');
                return;
            }

            $.ajax({
                url: `/consultations/${consultationId}/reject`,
                type: 'POST',
                contentType: 'application/json',
                headers: {
                    'X-CSRF-TOKEN': csrfToken
                },
                data: JSON.stringify({
                    rejection_reason: reason
                }),
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        Swal.fire({
                            title: 'Rejected!',
                            text: data.message,
                            icon: 'success',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            window.dispatchEvent(new CustomEvent('nurse-inbox-refresh-requested'));
                        });
                    } else {
                        Swal.fire('Error', data.message || 'Something went wrong.', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);

                    let errorMessage = 'Could not connect to the server. Please try again.';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMessage = xhr.responseJSON.message;
                    }

                    Swal.fire('Error', errorMessage, 'error');
                }
            });
        };
    </script>

    <script>
        window.approveConsultation = function(consultationId, priorityLevel) {
            if (!consultationId) {
                Swal.fire('Error', 'Missing consultation request ID. Please reopen the request and try again.', 'error');
                return;
            }

            if (!priorityLevel || !['High', 'Normal'].includes(priorityLevel)) {
                Swal.fire('Error', 'Please select a valid priority level before approving.', 'error');
                return;
            }

            const csrfToken = $('meta[name="csrf-token"]').attr('content');

            if (!csrfToken) {
                console.error('CSRF token not found. Please ensure <meta name="csrf-token" content="{{ csrf_token() }}"> is in your <head>.');
                return;
            }

            $.ajax({
                url: `/consultations/${consultationId}/approve`,
                type: 'POST',
                contentType: 'application/json',
                headers: {
                    'X-CSRF-TOKEN': csrfToken
                },
                data: JSON.stringify({
                    priority_level: priorityLevel
                }),
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        Swal.fire({
                            title: 'Approved!',
                            text: data.message,
                            icon: 'success',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            window.dispatchEvent(new CustomEvent('nurse-inbox-refresh-requested'));
                        });
                    } else {
                        Swal.fire('Error', data.message || 'Something went wrong.', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);

                    let errorMessage = 'Could not connect to the server. Please try again.';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMessage = xhr.responseJSON.message;
                    }

                    Swal.fire('Error', errorMessage, 'error');
                }
            });
        };
    </script>
</x-app-layout>
