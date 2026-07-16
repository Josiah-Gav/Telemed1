<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Consultation Inbox') }}
        </h2>
    </x-slot>

    @php
        $allInboxRequests = $pendingRequests
            ->concat($assignedToCurrentNurse)
            ->concat($assignedToOtherNurses)
            ->unique('request_id')
            ->values();

        $inboxRequestsJson = $allInboxRequests->map(function ($request) {
            return [
                'request_id' => $request->request_id,
                'patient_id' => $request->patient_id,
                'patient_name' => trim(optional($request->patient)->first_name . ' ' . optional($request->patient)->last_name) ?: 'Unknown Patient',
                'concern_category' => $request->concern_category,
                'submitted_at' => $request->submitted_at ? $request->submitted_at->format('Y-m-d H:i') : null,
                'request_status' => $request->request_status,
                'assigned_nurse_id' => $request->assigned_nurse_id,
                'assigned_nurse_name' => trim(optional($request->nurse)->first_name . ' ' . optional($request->nurse)->last_name) ?: null,
                'symptoms_desc' => $request->symptoms_desc,
                'online_reason' => $request->online_reason,
                'file_attachments' => array_map(function ($p) use ($request) { return url('/consultations/' . $request->request_id . '/attachments/' . basename($p)); }, $request->file_attachments ?? []),
            ];
        })->toArray();
    @endphp

    <script>
        window.inboxRequests = @json($inboxRequestsJson);
        function consultationInbox(requests) {
            return {
                showModal: false,
                selectedRequest: null,
                requests: requests,
                activeTab: 'pending',
                setTab(tab) {
                    this.activeTab = tab;
                },
                openModal(requestId) {
                    this.selectedRequest = this.requests.find(request => request.request_id === requestId);
                    this.showModal = true;
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

    <div class="py-12" x-data="consultationInbox(window.inboxRequests)" @keydown.escape.window="closeModal()">
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
                            {{ __('Pending') }} ({{ $pendingRequests->count() }})
                        </button>
                        <button
                            type="button"
                            @click="setTab('assigned')"
                            :class="activeTab === 'assigned' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-white text-gray-700 hover:bg-gray-100'"
                            class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold transition"
                        >
                            {{ __('Assigned') }} ({{ $assignedToCurrentNurse->count() + $assignedToOtherNurses->count() }})
                        </button>
                    </div>

                    <div x-show="activeTab === 'pending'" x-cloak>
                        @if($pendingRequests->isEmpty())
                            <div class="text-gray-500">{{ __('No pending consultation requests found.') }}</div>
                        @else
                            <div class="overflow-x-auto">
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
                                        @foreach($pendingRequests as $request)
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ optional($request->patient)->first_name ? optional($request->patient)->first_name . ' ' . optional($request->patient)->last_name : __('Unknown Patient') }}</td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    @php
                                                        $symptomsDisplay = __('N/A');
                                                        $symptomsData = $request->symptoms_desc;

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
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    @php
                                                        $highestSeverity = null;
                                                        $severityClass = 'bg-gray-100 text-gray-700';
                                                        $severityLabel = __('N/A');
                                                        $symptomsData = $request->symptoms_desc;

                                                        if (!empty($symptomsData) && is_array($symptomsData)) {
                                                            $severityValues = collect($symptomsData)
                                                                ->map(function ($item) {
                                                                    return is_array($item) ? ($item['severity'] ?? null) : null;
                                                                })
                                                                ->filter(fn($value) => is_numeric($value))
                                                                ->map(fn($value) => (int) $value)
                                                                ->all();

                                                            if (!empty($severityValues)) {
                                                                $highestSeverity = max($severityValues);
                                                            }
                                                        }

                                                        if ($highestSeverity === 1) {
                                                            $severityClass = 'bg-green-100 text-green-800';
                                                            $severityLabel = __('1 - Very Mild');
                                                        } elseif ($highestSeverity === 2) {
                                                            $severityClass = 'bg-yellow-100 text-yellow-800';
                                                            $severityLabel = __('2 - Mild');
                                                        } elseif ($highestSeverity === 3) {
                                                            $severityClass = 'bg-orange-100 text-orange-800';
                                                            $severityLabel = __('3 - Moderate');
                                                        } elseif ($highestSeverity === 4) {
                                                            $severityClass = 'bg-red-100 text-red-800';
                                                            $severityLabel = __('4 - Severe');
                                                        }
                                                    @endphp
                                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 font-semibold {{ $severityClass }}">
                                                        {{ $highestSeverity !== null ? $severityLabel : __('N/A') }}
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $request->submitted_at ? $request->submitted_at->format('Y-m-d H:i') : __('Unknown') }}</td>
                                                @php
                                                    $statusClasses = [
                                                        'pending' => 'text-orange-700 bg-orange-100',
                                                        'assigned' => 'text-yellow-700 bg-yellow-100',
                                                        'scheduled' => 'text-indigo-700 bg-indigo-100',
                                                        'active' => 'text-green-700 bg-green-100',
                                                        'completed' => 'text-green-900 bg-green-100',
                                                        'cancelled' => 'text-red-700 bg-red-100',
                                                    ];
                                                    $badgeClass = $statusClasses[$request->request_status] ?? 'text-gray-700 bg-gray-100';
                                                @endphp
                                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full font-semibold text-xs {{ $badgeClass }}">
                                                        {{ ucfirst($request->request_status) }}
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                    <button type="button" @click="openModal({{ $request->request_id }})" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-xs font-semibold rounded-md hover:bg-indigo-700 transition">
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

                    <div x-show="activeTab === 'assigned'" x-cloak class="space-y-6">
                        <div>
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-indigo-700">{{ __('Assigned To Me') }}</h3>
                            @if($assignedToCurrentNurse->isEmpty())
                                <p class="mt-2 text-sm text-gray-500">{{ __('No consultations are currently assigned to you.') }}</p>
                            @else
                                <div class="mt-3 overflow-x-auto">
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
                                            @foreach($assignedToCurrentNurse as $request)
                                                <tr>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ optional($request->patient)->first_name ? optional($request->patient)->first_name . ' ' . optional($request->patient)->last_name : __('Unknown Patient') }}</td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                        @php
                                                            $symptomsDisplay = __('N/A');
                                                            $symptomsData = $request->symptoms_desc;

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
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                        @php
                                                        $highestSeverity = null;
                                                        $severityClass = 'bg-gray-100 text-gray-700';
                                                        $severityLabel = __('N/A');
                                                        $symptomsData = $request->symptoms_desc;

                                                        if (!empty($symptomsData) && is_array($symptomsData)) {
                                                            $severityValues = collect($symptomsData)
                                                                ->map(function ($item) {
                                                                    return is_array($item) ? ($item['severity'] ?? null) : null;
                                                                })
                                                                ->filter(fn($value) => is_numeric($value))
                                                                ->map(fn($value) => (int) $value)
                                                                ->all();

                                                            if (!empty($severityValues)) {
                                                                $highestSeverity = max($severityValues);
                                                            }
                                                        }

                                                        if ($highestSeverity === 1) {
                                                            $severityClass = 'bg-green-100 text-green-800';
                                                            $severityLabel = __('1 - Very Mild');
                                                        } elseif ($highestSeverity === 2) {
                                                            $severityClass = 'bg-yellow-100 text-yellow-800';
                                                            $severityLabel = __('2 - Mild');
                                                        } elseif ($highestSeverity === 3) {
                                                            $severityClass = 'bg-orange-100 text-orange-800';
                                                            $severityLabel = __('3 - Moderate');
                                                        } elseif ($highestSeverity === 4) {
                                                            $severityClass = 'bg-red-100 text-red-800';
                                                            $severityLabel = __('4 - Severe');
                                                        }
                                                    @endphp
                                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 font-semibold {{ $severityClass }}">
                                                        {{ $highestSeverity !== null ? $severityLabel : __('N/A') }}
                                                    </span>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $request->submitted_at ? $request->submitted_at->format('Y-m-d H:i') : __('Unknown') }}</td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                        @php
                                                            $statusClasses = [
                                                                'pending' => 'text-orange-700 bg-orange-100',
                                                                'assigned' => 'text-yellow-700 bg-yellow-100',
                                                                'scheduled' => 'text-indigo-700 bg-indigo-100',
                                                                'active' => 'text-blue-100 bg-blue-700',
                                                                'completed' => 'text-green-900 bg-green-100',
                                                                'cancelled' => 'text-red-700 bg-red-100',
                                                            ];
                                                            $badgeClass = $statusClasses[$request->request_status] ?? 'text-gray-700 bg-gray-100';
                                                        @endphp    
                                                        
                                                        <span class="inline-flex items-center px-2 py-1 rounded-full font-semibold text-xs {{ $badgeClass }}">
                                                            {{ ucfirst($request->request_status) }}
                                                        </span>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                         @php
                                                            $priorityClass = [
                                                                'High' => 'text-red-700 bg-red-100',
                                                                'Normal' => 'text-yellow-700 bg-yellow-100',
                                                            ];
                                                            $badgeClass = $priorityClass[$request->priority_level] ?? 'text-gray-700 bg-gray-100';
                                                        @endphp
                                                        <span class="inline-flex items-center px-2 py-1 rounded-full font-semibold text-xs {{ $badgeClass }}">
                                                            {{ ucfirst($request->priority_level) }}
                                                        </span>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                        <button type="button" @click="openModal({{ $request->request_id }})" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-xs font-semibold rounded-md hover:bg-indigo-700 transition">
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

                        <div>
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-700">{{ __('Assigned To Other Nurses') }}</h3>
                            @if($assignedToOtherNurses->isEmpty())
                                <p class="mt-2 text-sm text-gray-500">{{ __('No consultations are assigned to other nurses right now.') }}</p>
                            @else
                                <div class="mt-3 overflow-x-auto">
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
                                            @foreach($assignedToOtherNurses as $request)
                                                <tr>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ optional($request->patient)->first_name ? optional($request->patient)->first_name . ' ' . optional($request->patient)->last_name : __('Unknown Patient') }}</td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ trim(optional($request->nurse)->first_name . ' ' . optional($request->nurse)->last_name) ?: __('Unassigned') }}</td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $request->submitted_at ? $request->submitted_at->format('Y-m-d H:i') : __('Unknown') }}</td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                        <span class="inline-flex items-center px-2 py-1 rounded-full font-semibold text-xs text-blue-700 bg-blue-100">
                                                            {{ ucfirst($request->request_status) }}
                                                        </span>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                        <button type="button" @click="openModal({{ $request->request_id }})" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-xs font-semibold rounded-md hover:bg-indigo-700 transition">
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
                                        <a :href="file" target="_blank" class="font-medium text-indigo-600 hover:underline" x-text="file.split('/').pop()"></a>
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
                            const rejectionReason = result.value;

                            window.rejectionConsultation(selectedRequest?.request_id, rejectionReason);
                        }
                            
                    });"
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
    // Explicitly bind the function to the window object to make it globally accessible
    window.rejectionConsultation = function(consultationId, reason) {
        if (!consultationId) {
            Swal.fire('Error', 'Missing consultation request ID. Please reopen the request and try again.', 'error');
            return;
        }

        // 1. Retrieve CSRF token securely
        const csrfToken = $('meta[name="csrf-token"]').attr('content');

        if (!csrfToken) {
            console.error('CSRF token not found. Please ensure <meta name="csrf-token" content="{{ csrf_token() }}"> is in your <head>.');
            return;
        }

        // 2. Perform the AJAX Request using jQuery
        $.ajax({
            url: `/consultations/${consultationId}/reject`,
            type: 'POST',
            contentType: 'application/json',
            headers: {
                'X-CSRF-TOKEN': csrfToken
            },
            data: JSON.stringify({
                rejection_reason: reason // Matches 'rejection_reason' in your Controller validation
            }),
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    // Show success SweetAlert and refresh/redirect on close
                    Swal.fire({
                        title: 'Rejected!',
                        text: data.message,
                        icon: 'success',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        window.location.reload(); // Refresh the inbox to see updated statuses
                    });
                } else {
                    Swal.fire('Error', data.message || 'Something went wrong.', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                
                // Retrieve Laravel's validation or custom error message if available
                let errorMessage = 'Could not connect to the server. Please try again.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMessage = xhr.responseJSON.message;
                }
                
                Swal.fire('Error', errorMessage, 'error');
            }
        });
    }
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
                            window.location.reload();
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
        }
    </script>
</x-app-layout>
