<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Consultation History') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 bg-white border-b border-gray-200">
                    <div class="flex flex-col gap-6">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-semibold text-slate-900">Your Consultation History</h3>
                                <p class="mt-1 text-sm text-slate-500">Review your past consultation requests and their status.</p>
                            </div>
                            <a href="{{ route('consultations.create') }}" class="inline-flex items-center justify-center rounded-full bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">New Consultation</a>
                        </div>

                        @if($consultations->isEmpty())
                            <div class="rounded-3xl border border-gray-200 bg-slate-50 p-8 text-center">
                                <p class="text-lg font-semibold text-slate-900">No consultation history found.</p>
                                <p class="mt-2 text-sm text-slate-500">Submit your first consultation request to see it listed here.</p>
                            </div>
                        @else
                            <div class="space-y-4">
                                @foreach($consultations as $consultation)
                                    <a href="{{ route('consultations.show', $consultation) }}" class="block rounded-3xl border border-gray-200 bg-white p-6 shadow-sm transition hover:border-blue-300 hover:bg-slate-50">
                                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                            <div>
                                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ ucfirst($consultation->concern_category) }} Consultation</p>
                                                <h4 class="mt-2 text-xl font-semibold text-slate-900">{{ ucfirst($consultation->request_status) }}</h4>
                                                <p class="mt-1 text-sm text-slate-600">Submitted {{ $consultation->submitted_at->format('M d, Y @ h:i A') }}</p>
                                            </div>
                                            <div class="inline-flex items-center gap-3">
                                                @php
                                                    $status = $consultation->request_status;
                                                    $badgeClasses = 'inline-flex items-center rounded-full px-4 py-2 text-sm font-semibold ';
                                                    if (in_array($status, ['rejected', 'cancelled'])) {
                                                        $badgeClasses .= 'bg-red-100 text-red-700';
                                                    } elseif ($status === 'completed') {
                                                        $badgeClasses .= 'bg-emerald-100 text-emerald-700';
                                                    } else {
                                                        $badgeClasses .= 'bg-slate-100 text-slate-700';
                                                    }
                                                @endphp
                                                <span class="{{ $badgeClasses }}">{{ ucfirst($status) }}</span>
                                                <span class="text-sm text-slate-400">View details</span>
                                            </div>
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>