<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    {{ __("Hello $patientInfo->first_name!") }}
                </div>
            </div>

            @if(!empty($activeConsultation))
                <div class="mt-6">
                    <a href="{{ route('consultations.show', $activeConsultation) }}" class="block rounded-3xl border border-gray-200 bg-white hover:border-blue-300 hover:shadow-lg transition shadow-sm">
                        <div class="p-6 sm:p-8">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Active Consultation</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-900">{{ ucfirst($activeConsultation->concern_category) }} Consultation</h3>
                                    <p class="mt-1 text-sm text-slate-600">{{ 'Summary of symptoms: ' . ($activeConsultationSummary ?? 'No symptoms recorded') }}</p>
                                </div>
                                <div class="inline-flex items-center gap-3">
                                    @php
                                        $status = $activeConsultation->request_status;
                                        $statusClasses = 'inline-flex items-center rounded-full px-4 py-2 text-sm font-semibold ';
                                        if (in_array($status, ['rejected', 'cancelled'])) {
                                            $statusClasses .= 'bg-red-100 text-red-700';
                                        } elseif ($status === 'completed') {
                                            $statusClasses .= 'bg-emerald-100 text-emerald-700';
                                        } elseif (in_array($status, ['pending', 'assigned'])) {
                                            $statusClasses .= 'bg-yellow-100 text-yellow-700';
                                        } elseif ($status === 'scheduled') {
                                            $statusClasses .= 'bg-indigo-100 text-indigo-700';
                                        } elseif ($status === 'active') {
                                            $statusClasses .= 'bg-blue-100 text-blue-700';
                                        }else {
                                            $statusClasses .= 'bg-slate-100 text-slate-700';
                                        }
                                    @endphp

                                    <span class="{{ $statusClasses }}">{{ ucfirst($status) }}</span>
                                    @if(in_array($activeConsultation->request_status, ['active', 'completed']) && $activeConsultation->consultationSession)
                                        <span class="inline-flex items-center rounded-full bg-indigo-100 px-2.5 py-1 text-indigo-700" title="Messaging available">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75h6.75m-6.75 3h4.5m6.375 7.5-3.375-2.025a3.75 3.75 0 0 0-1.928-.525H6.75A3.75 3.75 0 0 1 3 13.95V7.5A3.75 3.75 0 0 1 6.75 3.75h10.5A3.75 3.75 0 0 1 21 7.5v8.25a3.75 3.75 0 0 1-1.5 3z" />
                                            </svg>
                                            <span class="ml-1 hidden rounded-full bg-indigo-600 px-1.5 py-0.5 text-[10px] font-bold text-white" data-unread-badge="{{ $activeConsultation->consultationSession->id }}">0</span>
                                            <span class="sr-only">Messaging available</span>
                                        </span>
                                    @endif
                                    @if($activeConsultation->consultationSession)
                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $activeConsultation->consultationSession->hasClinicalDocumentation() ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">
                                            {{ $activeConsultation->consultationSession->hasClinicalDocumentation() ? __('Assessment ready') : __('Assessment pending') }}
                                        </span>
                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $activeConsultation->consultationSession->hasPrescription() ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-600' }}">
                                            {{ $activeConsultation->consultationSession->hasPrescription() ? __('Prescription uploaded') : __('No prescription') }}
                                        </span>
                                    @endif
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                            </div>
                            <div class="mt-6 grid gap-4 sm:grid-cols-2">
                                <div class="rounded-2xl border border-gray-200 bg-slate-50 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Submitted</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900">{{ $activeConsultation->submitted_at->format('M d, Y') }}</p>
                                </div>
                                <div class="rounded-2xl border border-gray-200 bg-slate-50 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Status</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900">{{ ucfirst($activeConsultation->request_status) }}</p>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
            @endif
        </div>
    </div>

    @if(!empty($activeConsultation) && $activeConsultation->consultationSession && $activeConsultation->consultationSession->consultation_status === 'active')
        <script>
            (function () {
                const unreadUrl = '{{ route('consultations.messaging.unread_counts') }}';

                function updateUnreadBadge() {
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

                updateUnreadBadge();
                setInterval(updateUnreadBadge, 6000);
            })();
        </script>
    @endif
</x-app-layout>
