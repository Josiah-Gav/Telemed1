<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Follow-up Requests') }}
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
                                <tr>
                                    <td class="px-6 py-4 text-sm text-slate-900">
                                        {{ trim((optional($followUp->patient)->first_name ?? '') . ' ' . (optional($followUp->patient)->last_name ?? '')) ?: 'Unknown Patient' }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-700">
                                        {{ optional(optional($followUp->consultation)->request)->concern_category ?? 'Completed Consultation' }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-700 max-w-md">
                                        {{ $followUp->reason }}
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <div class="grid gap-2 md:grid-cols-2">
                                            <form method="POST" action="{{ route('nurse.follow_up_requests.forward', ['nurse' => $nurse->user_id, 'followUpRequest' => $followUp->id]) }}" class="space-y-2 rounded-xl border border-slate-200 p-3">
                                                @csrf
                                                <textarea name="decision_notes" rows="2" maxlength="2000" class="w-full rounded-lg border-slate-300 text-xs focus:border-indigo-500 focus:ring-indigo-500" placeholder="Optional nurse screening notes"></textarea>
                                                <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700 w-full">
                                                    Forward
                                                </button>
                                            </form>

                                            <form method="POST" action="{{ route('nurse.follow_up_requests.reject', ['nurse' => $nurse->user_id, 'followUpRequest' => $followUp->id]) }}" class="space-y-2 rounded-xl border border-red-200 p-3 bg-red-50">
                                                @csrf
                                                <textarea name="decision_notes" rows="2" maxlength="2000" class="w-full rounded-lg border-red-300 text-xs focus:border-red-500 focus:ring-red-500" placeholder="Required rejection reason" required></textarea>
                                                <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white hover:bg-red-700 w-full">
                                                    Reject
                                                </button>
                                            </form>
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
