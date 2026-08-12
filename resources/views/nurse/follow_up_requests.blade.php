<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Follow-up Requests') }}
        </h2>
    </x-slot>

    @php
        $pendingFollowUpPayload = collect($pendingRequests)->map(function ($followUp) use ($nurse) {
            return [
                'id' => $followUp->id,
                'patient_name' => trim((optional($followUp->patient)->first_name ?? '') . ' ' . (optional($followUp->patient)->last_name ?? '')) ?: 'Unknown Patient',
                'original_consultation' => optional(optional($followUp->consultation)->request)->concern_category ?? 'Completed Consultation',
                'reason' => $followUp->reason,
                'forward_url' => route('nurse.follow_up_requests.forward', ['nurse' => $nurse->user_id, 'followUpRequest' => $followUp->id]),
                'reject_url' => route('nurse.follow_up_requests.reject', ['nurse' => $nurse->user_id, 'followUpRequest' => $followUp->id]),
            ];
        })->values();
    @endphp

    <script>
        window.pendingFollowUpRequests = @json($pendingFollowUpPayload);

        function nurseFollowUpRequests(initialRequests) {
            return {
                requests: initialRequests || [],
                submitDecision(requestItem, payload) {
                    const csrfToken = $('meta[name="csrf-token"]').attr('content');

                    if (!csrfToken) {
                        Swal.fire('Error', 'Missing CSRF token.', 'error');
                        return;
                    }

                    $.ajax({
                        url: payload.action === 'forward' ? requestItem.forward_url : requestItem.reject_url,
                        type: 'POST',
                        contentType: 'application/json',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        },
                        data: JSON.stringify(payload),
                        dataType: 'json',
                        success: (data) => {
                            if (data.success) {
                                Swal.fire('Success', data.message, 'success').then(() => {
                                    window.location.reload();
                                });
                            } else {
                                Swal.fire('Error', data.message || 'Unable to process follow-up request.', 'error');
                            }
                        },
                        error: (xhr) => {
                            const message = xhr.responseJSON?.message || 'Unable to process follow-up request.';
                            Swal.fire('Error', message, 'error');
                        }
                    });
                },
                forwardRequest(requestItem) {
                    Swal.fire({
                        title: 'Forward Follow-up Request',
                        text: 'Add optional screening notes before forwarding to the physician.',
                        icon: 'question',
                        input: 'textarea',
                        inputPlaceholder: 'Optional nurse screening notes...',
                        inputAttributes: {
                            'aria-label': 'Optional nurse screening notes'
                        },
                        showCancelButton: true,
                        confirmButtonText: 'Forward',
                    }).then((result) => {
                        if (!result.isConfirmed) {
                            return;
                        }

                        this.submitDecision(requestItem, {
                            action: 'forward',
                            decision_notes: result.value || null,
                        });
                    });
                },
                rejectRequest(requestItem) {
                    Swal.fire({
                        title: 'Reject Follow-up Request',
                        text: 'Please provide a reason for rejection.',
                        icon: 'warning',
                        input: 'textarea',
                        inputPlaceholder: 'Required rejection reason...',
                        inputAttributes: {
                            'aria-label': 'Required rejection reason'
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
                        if (!result.isConfirmed) {
                            return;
                        }

                        this.submitDecision(requestItem, {
                            action: 'reject',
                            decision_notes: result.value,
                        });
                    });
                }
            };
        }
    </script>

    <div class="py-10" x-data="nurseFollowUpRequests(window.pendingFollowUpRequests)">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if(session('status'))
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-slate-200 bg-slate-50 px-6 py-4">
                    <p class="text-sm text-slate-600">Review patient follow-up requests and either forward to physician review or reject with a reason.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Patient</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Original Consultation</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Reason</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white">
                            @forelse($pendingRequests as $followUp)
                                @php
                                    $patientName = trim((optional($followUp->patient)->first_name ?? '') . ' ' . (optional($followUp->patient)->last_name ?? '')) ?: 'Unknown Patient';
                                @endphp
                                <tr>
                                    <td class="px-6 py-4 text-sm text-slate-900">
                                        {{ $patientName }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-700">
                                        {{ optional(optional($followUp->consultation)->request)->concern_category ?? 'Completed Consultation' }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-700 max-w-md">
                                        {{ $followUp->reason }}
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <div class="flex flex-wrap gap-2">
                                            <button type="button" @click="forwardRequest({
                                                id: {{ $followUp->id }},
                                                patient_name: @js($patientName),
                                                original_consultation: @js(optional(optional($followUp->consultation)->request)->concern_category ?? 'Completed Consultation'),
                                                reason: @js($followUp->reason),
                                                forward_url: @js(route('nurse.follow_up_requests.forward', ['nurse' => $nurse->user_id, 'followUpRequest' => $followUp->id])),
                                                reject_url: @js(route('nurse.follow_up_requests.reject', ['nurse' => $nurse->user_id, 'followUpRequest' => $followUp->id]))
                                            })" class="inline-flex items-center rounded-lg bg-brand-green px-3 py-2 text-xs font-semibold text-white hover:bg-brand-green-deep">
                                                Forward
                                            </button>
                                            <button type="button" @click="rejectRequest({
                                                id: {{ $followUp->id }},
                                                patient_name: @js($patientName),
                                                original_consultation: @js(optional(optional($followUp->consultation)->request)->concern_category ?? 'Completed Consultation'),
                                                reason: @js($followUp->reason),
                                                forward_url: @js(route('nurse.follow_up_requests.forward', ['nurse' => $nurse->user_id, 'followUpRequest' => $followUp->id])),
                                                reject_url: @js(route('nurse.follow_up_requests.reject', ['nurse' => $nurse->user_id, 'followUpRequest' => $followUp->id]))
                                            })" class="inline-flex items-center rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white hover:bg-red-700">
                                                Reject
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-10 text-center text-sm text-slate-500">No pending follow-up requests to review.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
