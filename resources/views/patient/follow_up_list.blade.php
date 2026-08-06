<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Follow-up Consultations') }}
        </h2>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if(session('status'))
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-slate-200 bg-slate-50 px-6 py-4">
                    <p class="text-sm text-slate-600">
                        Completed consultations from the last 7 days appear below. Open the consultation details or request a follow-up from here.
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Consultation</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Completed</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Physician</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white">
                            @forelse($completedConsultations as $session)
                                @php
                                    $request = $session->request;
                                    $patientName = trim((optional($request->patient)->first_name ?? '') . ' ' . (optional($request->patient)->last_name ?? '')) ?: 'Patient';
                                    $messagingUrl = route('consultations.messaging.show', ['session' => $session]);
                                    $existingFollowUp = $session->followUpRequests->firstWhere('status', 'pending');
                                @endphp
                                <tr>
                                    <td class="px-6 py-4 text-sm text-slate-900">
                                        <div class="font-semibold">{{ $request->concern_category ?? 'Completed Consultation' }}</div>
                                        <div class="text-slate-500">{{ $patientName }}</div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-700">
                                        {{ optional($session->completed_at)->format('M d, Y @ h:i A') }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-700">
                                        {{ trim((optional($session->physician)->first_name ?? '') . ' ' . (optional($session->physician)->last_name ?? '')) ?: 'Unassigned' }}
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <div class="flex flex-wrap gap-2">
                                            <a href="{{ $messagingUrl }}" class="inline-flex items-center rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800">
                                                View Details
                                            </a>

                                            @if($existingFollowUp)
                                                <span class="inline-flex items-center rounded-lg bg-amber-100 px-3 py-2 text-xs font-semibold text-amber-800">
                                                    Follow-up pending review
                                                </span>
                                            @else
                                                <form method="POST" action="{{ route('patient.follow_up_requests.store', ['session' => $session]) }}" class="flex flex-col gap-2 rounded-xl border border-slate-200 p-3">
                                                    @csrf
                                                    <textarea name="reason" rows="2" maxlength="2000" class="w-full rounded-lg border-slate-300 text-xs focus:border-indigo-500 focus:ring-indigo-500" placeholder="Reason for follow-up" required></textarea>
                                                    <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700">
                                                        Request Follow-up
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-10 text-center text-sm text-slate-500">
                                        No completed consultations are eligible for follow-up right now.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout><div>
    <!-- The biggest battle is the war against ignorance. - Mustafa Kemal Atatürk -->
</div>
