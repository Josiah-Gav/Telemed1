<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Consultation Inbox') }}
        </h2>
    </x-slot>

    @php
        $pendingRequestsJson = $pendingRequests->map(function ($request) {
            return [
                'request_id' => $request->request_id,
                'patient_id' => $request->patient_id,
                'patient_name' => trim(optional($request->patient)->first_name . ' ' . optional($request->patient)->last_name) ?: 'Unknown Patient',
                'concern_category' => $request->concern_category,
                'submitted_at' => $request->submitted_at ? $request->submitted_at->format('Y-m-d H:i') : null,
                'request_status' => $request->request_status,
                'symptoms_desc' => $request->symptoms_desc,
                'online_reason' => $request->online_reason,
                'file_attachments' => array_map(function ($p) use ($request) { return url('/consultations/' . $request->request_id . '/attachments/' . basename($p)); }, $request->file_attachments ?? []),
            ];
        })->toArray();
    @endphp

    <script>
        window.pendingRequests = @json($pendingRequestsJson);
        function consultationInbox(requests) {
            return {
                showModal: false,
                selectedRequest: null,
                requests: requests,
                openModal(requestId) {
                    this.selectedRequest = this.requests.find(request => request.request_id === requestId);
                    this.showModal = true;
                },
                closeModal() {
                    this.showModal = false;
                    this.selectedRequest = null;
                },
                formatSymptoms(symptoms) {
                    if (!symptoms) return '';
                    if (Array.isArray(symptoms)) {
                        return symptoms.map(item => `<li>${item['name'] ?? item}</li>`).join('');
                    }
                    return `<li>${symptoms}</li>`;
                }
            }
        }
    </script>

    <div class="py-12" x-data="consultationInbox(window.pendingRequests)" @keydown.escape.window="closeModal()">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    @if($pendingRequests->isEmpty())
                        <div class="text-gray-500">{{ __('No pending consultation requests found.') }}</div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Patient Name') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Request ID') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Patient ID') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Concern') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Submitted At') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Status') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($pendingRequests as $request)
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ optional($request->patient)->first_name ? optional($request->patient)->first_name . ' ' . optional($request->patient)->last_name : __('Unknown Patient') }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $request->request_id }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $request->patient_id }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $request->concern_category ?? __('N/A') }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $request->submitted_at ? $request->submitted_at->format('Y-m-d H:i') : __('Unknown') }}</td>
                                            @php
                                                $statusClasses = [
                                                    'pending' => 'text-orange-700 bg-orange-100',
                                                    'assigned' => 'text-blue-700 bg-blue-100',
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
            </div>
        </div>

        <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4 py-6">
            <div class="w-full max-w-2xl bg-white rounded-2xl shadow-xl overflow-hidden">
                <div class="border-b border-gray-200 p-6 flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">{{ __('Consultation Details') }}</h3>
                        <p class="text-sm text-gray-500">{{ __('Review and decide on the selected consultation request.') }}</p>
                    </div>
                    <button type="button" @click="closeModal()" class="text-gray-400 hover:text-gray-700">
                        <span class="sr-only">{{ __('Close') }}</span>
                        ✕
                    </button>
                </div>

                <div class="p-6 space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <p class="text-xs font-semibold text-gray-500 uppercase">{{ __('Request ID') }}</p>
                            <p class="mt-1 text-sm text-gray-900" x-text="selectedRequest?.request_id"></p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 uppercase">{{ __('Patient Name') }}</p>
                            <p class="mt-1 text-sm text-gray-900" x-text="selectedRequest?.patient_name"></p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 uppercase">{{ __('Patient ID') }}</p>
                            <p class="mt-1 text-sm text-gray-900" x-text="selectedRequest?.patient_id"></p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 uppercase">{{ __('Submitted At') }}</p>
                            <p class="mt-1 text-sm text-gray-900" x-text="selectedRequest?.submitted_at ?? '{{ __('Unknown') }}'"></p>
                        </div>
                    </div>

                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase">{{ __('Concern Category') }}</p>
                        <p class="mt-1 text-sm text-gray-900" x-text="selectedRequest?.concern_category ?? '{{ __('N/A') }}'"></p>
                    </div>

                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase">{{ __('Symptoms') }}</p>
                        <template x-if="selectedRequest?.symptoms_desc">
                            <ul class="mt-2 list-disc list-inside text-sm text-gray-900" x-html="formatSymptoms(selectedRequest?.symptoms_desc)"></ul>
                        </template>
                        <p class="mt-2 text-sm text-gray-500" x-show="!selectedRequest?.symptoms_desc">{{ __('No symptom details provided.') }}</p>
                    </div>

                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase">{{ __('Reason for online consultation') }}</p>
                        <p class="mt-1 text-sm text-gray-900" x-text="selectedRequest?.online_reason ?? '{{ __('N/A') }}'"></p>
                    </div>

                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase">{{ __('Attachments') }}</p>
                        <template x-if="selectedRequest?.file_attachments && selectedRequest.file_attachments.length">
                            <ul class="mt-1 list-disc list-inside text-sm text-gray-900">
                                <template x-for="file in selectedRequest.file_attachments" :key="file">
                                    <li>
                                        <a :href="file" target="_blank" class="text-indigo-600 hover:underline" x-text="file.split('/').pop()"></a>
                                    </li>
                                </template>
                            </ul>
                        </template>
                        <p class="mt-1 text-sm text-gray-500" x-show="!selectedRequest?.file_attachments || !selectedRequest.file_attachments.length">{{ __('No attachments.') }}</p>
                    </div>
                </div>

                <div class="border-t border-gray-200 p-6 flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <button type="button" @click="closeModal()" class="inline-flex justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        {{ __('Close') }}
                    </button>
                    <button type="button" class="inline-flex justify-center rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                        {{ __('Reject') }}
                    </button>
                    <button type="button" class="inline-flex justify-center rounded-md bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700">
                        {{ __('Approve') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

</x-app-layout>
