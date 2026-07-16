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
</x-app-layout>
