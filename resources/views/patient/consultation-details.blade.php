<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Consultation Details') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 bg-white border-b border-gray-200">
                    <div class="space-y-6">
                        <div class="rounded-3xl border border-gray-200 bg-slate-50 p-6">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">Active Consultation</p>
                                    <h3 class="mt-2 text-2xl font-bold text-slate-900">{{ ucfirst($consultation->concern_category) }} Consultation</h3>
                                </div>
                                @php
                                    $status = $consultation->request_status;
                                    $statusClasses = 'inline-flex items-center rounded-full px-4 py-2 text-sm font-semibold ';
                                    if (in_array($status, ['rejected', 'cancelled'])) {
                                        $statusClasses .= 'bg-red-100 text-red-700';
                                    } elseif ($status === 'completed') {
                                        $statusClasses .= 'bg-emerald-100 text-emerald-700';
                                    } else {
                                        $statusClasses .= 'bg-slate-100 text-slate-700';
                                    }
                                @endphp
                                <div class="flex flex-col gap-3 sm:items-end sm:w-80">
                                    <span class="{{ $statusClasses }}">
                                        {{ ucfirst($status) }}
                                    </span>
                                    <div class="rounded-2xl border border-gray-200 bg-white px-5 py-4 text-right shadow-sm">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Submitted</p>
                                        <p class="mt-2 text-sm font-semibold text-slate-900">{{ $consultation->submitted_at->format('M d, Y @ h:i A') }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-3xl border border-gray-200 bg-white p-6">
                            <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">Summary of Symptoms</p>
                            <div class="mt-4 space-y-3 text-sm text-slate-700">
                                @if(is_array($consultation->symptoms_desc) && count($consultation->symptoms_desc) > 0)
                                    @foreach($consultation->symptoms_desc as $symptom)
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                            <p class="font-semibold text-slate-900">{{ $symptom['name'] ?? $symptom }}</p>
                                            @if(!empty($symptom['date']) || !empty($symptom['time']))
                                                <p class="text-xs text-slate-500 mt-1">Started: {{ ($symptom['date'] ?? 'Unknown') }} {{ ($symptom['time'] ?? '') }}</p>
                                            @endif
                                            @if(!empty($symptom['severity']))
                                                <p class="text-xs text-slate-500 mt-1">Severity: {{ $symptom['severity'] }}</p>
                                            @endif
                                        </div>
                                    @endforeach
                                @else
                                    <p class="text-sm text-slate-500">No symptoms were recorded for this request.</p>
                                @endif
                            </div>
                        </div>

                        <div class="rounded-3xl border border-gray-200 bg-white p-6">
                            <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">Reason for Online Consultation</p>
                            <p class="mt-4 text-sm text-slate-700">{{ $consultation->online_reason ?? 'No reason provided.' }}</p>
                        </div>

                        @if(!empty($consultation->file_attachments))
                            <div class="rounded-3xl border border-gray-200 bg-white p-6">
                                <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">Attachments</p>
                                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    @foreach($consultation->file_attachments as $attachment)
                                        <a href="{{ $attachment }}" target="_blank" class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm font-medium text-slate-900 hover:bg-slate-100 transition">
                                            View attachment
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if(!empty($consultation->assigned_nurse_id))
                            <div class="rounded-3xl border border-gray-200 bg-white p-6">
                                <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">Assigned Nurse</p>
                                @php
                                    $assignedNurseName = trim((optional($consultation->nurse)->first_name ?? '') . ' ' . (optional($consultation->nurse)->last_name ?? ''));
                                @endphp
                                <p class="mt-4 text-sm text-slate-700">{{ $assignedNurseName !== '' ? $assignedNurseName : 'Assigned nurse record not found.' }}</p>
                            </div>
                        @endif

                        
                        <div class="text-right">
                            @if (in_array($consultation->request_status, ['pending', 'reviewed']))
                                <p class="mb-3 text-sm text-slate-500">You can cancel this consultation request if you wish.</p>
                            <a
                                href="javascript:void(0);"
                                class="inline-flex items-center justify-center rounded-full bg-red-700 px-6 py-3 text-sm font-semibold text-white hover:bg-red-600"
                                data-cancel-url="{{ route('consultations.cancel', $consultation) }}"
                                onclick="cancelConsultation(this);"
                            > Cancel </a>
                            @endif
                            @if ($consultation->request_status === 'active' && $consultation->consultationSession)
                                <a href="{{ route('consultations.messaging.show', $consultation->consultationSession) }}" class="inline-flex items-center justify-center rounded-full bg-indigo-700 px-4 py-3 text-sm font-semibold text-white hover:bg-indigo-600" aria-label="Open messaging">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75h6.75m-6.75 3h4.5m6.375 7.5-3.375-2.025a3.75 3.75 0 0 0-1.928-.525H6.75A3.75 3.75 0 0 1 3 13.95V7.5A3.75 3.75 0 0 1 6.75 3.75h10.5A3.75 3.75 0 0 1 21 7.5v8.25a3.75 3.75 0 0 1-1.5 3z" />
                                    </svg>
                                    <span class="sr-only">{{ __('Open Messaging') }}</span>
                                </a>
                            @endif
                            @if ($consultation->request_status === 'completed' && $consultation->consultationSession)
                                <a href="{{ route('consultations.messaging.show', $consultation->consultationSession) }}" class="inline-flex items-center justify-center rounded-full bg-emerald-700 px-6 py-3 text-sm font-semibold text-white hover:bg-emerald-600">
                                    {{ __('View Chats & Assessment') }}
                                </a>
                            @endif
                            <a href="{{ route('dashboard') }}" class="inline-flex items-center justify-center rounded-full bg-slate-900 px-6 py-3 text-sm font-semibold text-white hover:bg-slate-800">Back to Dashboard</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function cancelConsultation(triggerElement) {
            const cancelUrl = triggerElement?.dataset?.cancelUrl;
            if (!cancelUrl) {
                Swal.fire(
                    'Error!',
                    'Unable to find consultation cancel URL.',
                    'error'
                );
                return;
            }

            Swal.fire({
                title: 'Are you sure?',
                text: 'You won\'t be able to revert this!',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, cancel it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Make an AJAX request to cancel the consultation
                    fetch(cancelUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire(
                                'Cancelled!',
                                'Your consultation has been cancelled.',
                                'success'
                            ).then(() => {
                                // Optionally, you can redirect or refresh the page
                                window.location.href = '{{ route('dashboard') }}';
                            });
                        } else {
                            Swal.fire(
                                'Error!',
                                data.message || 'An error occurred while cancelling the consultation.',
                                'error'
                            );
                        }
                    })
                    .catch(error => {
                        Swal.fire(
                            'Error!',
                            'An error occurred while cancelling the consultation.',
                            'error'
                        );
                    });
                }
            });
}
    </script>
</x-app-layout>
