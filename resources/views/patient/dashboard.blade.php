<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    @php
        $patientConsultationPayload = null;

        if (!empty($activeConsultation)) {
            $status = $activeConsultation->request_status;
            $statusClasses = 'inline-flex items-center rounded-full px-4 py-2 text-sm font-semibold ';

            if (in_array($status, ['rejected', 'cancelled'], true)) {
                $statusClasses .= 'bg-red-100 text-red-700';
            } elseif ($status === 'completed') {
                $statusClasses .= 'bg-emerald-100 text-emerald-700';
            } elseif (in_array($status, ['pending', 'assigned'], true)) {
                $statusClasses .= 'bg-yellow-100 text-yellow-700';
            } elseif ($status === 'scheduled') {
                $statusClasses .= 'bg-indigo-100 text-indigo-700';
            } elseif ($status === 'active') {
                $statusClasses .= 'bg-blue-100 text-blue-700';
            } else {
                $statusClasses .= 'bg-slate-100 text-slate-700';
            }

            $patientConsultationPayload = [
                'request_id' => $activeConsultation->request_id,
                'details_url' => route('consultations.show', $activeConsultation),
                'concern_category' => $activeConsultation->concern_category,
                'summary' => $activeConsultationSummary ?? 'No symptoms recorded',
                'request_status' => $status,
                'status_badge_class' => $statusClasses,
                'status_label' => ucfirst($status),
                'submitted_at' => optional($activeConsultation->submitted_at)->format('M d, Y'),
                'show_messaging' => in_array($status, ['active', 'completed'], true) && $activeConsultation->consultationSession,
                'session' => $activeConsultation->consultationSession ? [
                    'id' => $activeConsultation->consultationSession->id,
                    'consultation_status' => $activeConsultation->consultationSession->consultation_status,
                    'has_clinical_documentation' => $activeConsultation->consultationSession->hasClinicalDocumentation(),
                    'clinical_badge_class' => $activeConsultation->consultationSession->hasClinicalDocumentation() ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700',
                    'clinical_label' => $activeConsultation->consultationSession->hasClinicalDocumentation() ? __('Assessment ready') : __('Assessment pending'),
                    'has_prescription' => $activeConsultation->consultationSession->hasPrescription(),
                    'prescription_badge_class' => $activeConsultation->consultationSession->hasPrescription() ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-600',
                    'prescription_label' => $activeConsultation->consultationSession->hasPrescription() ? __('Prescription uploaded') : __('No prescription'),
                    'unread_count' => 0,
                ] : null,
            ];
        }
    @endphp

    <script>
        window.patientConsultation = @json($patientConsultationPayload);

        function patientDashboard(initialConsultation, refreshUrl, unreadUrl) {
            return {
                consultation: initialConsultation,
                refreshUrl,
                unreadUrl,
                refreshTimer: null,
                unreadTimer: null,
                init() {
                    if (this.refreshTimer || this.unreadTimer) {
                        return;
                    }

                    this.refreshConsultation();
                    this.updateUnreadBadge();
                    this.refreshTimer = window.setInterval(() => this.refreshConsultation(), 5000);
                    this.unreadTimer = window.setInterval(() => this.updateUnreadBadge(), 5000);
                },
                refreshConsultation() {
                    $.ajax({
                        url: this.refreshUrl,
                        method: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        success: (data) => {
                            const nextConsultation = data?.consultation ?? null;
                            const previousUnreadCount = this.consultation?.session?.unread_count ?? 0;

                            this.consultation = nextConsultation;

                            if (this.consultation?.session) {
                                this.consultation.session.unread_count = previousUnreadCount;
                            }
                        }
                    });
                },
                updateUnreadBadge() {
                    if (!this.consultation?.session || this.consultation.session.consultation_status !== 'active') {
                        if (this.consultation?.session) {
                            this.consultation.session.unread_count = 0;
                        }
                        return;
                    }

                    $.ajax({
                        url: this.unreadUrl,
                        method: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        success: (data) => {
                            const counts = data?.counts || {};
                            const sessionId = String(this.consultation.session.id);
                            this.consultation.session.unread_count = Number(counts[sessionId] || 0);
                        }
                    });
                },
                formatUnreadCount(count) {
                    return count > 99 ? '99+' : String(count);
                },
                consultationTitle() {
                    if (!this.consultation?.concern_category) {
                        return 'Consultation';
                    }

                    const concernCategory = this.consultation.concern_category;
                    return concernCategory.charAt(0).toUpperCase() + concernCategory.slice(1) + ' Consultation';
                }
            };
        }
    </script>

    <div class="py-12" x-data="patientDashboard(window.patientConsultation, '{{ route('dashboard.active_consultation') }}', '{{ route('consultations.messaging.unread_counts') }}')" x-init="init()">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    {{ __("Hello $patientInfo->first_name!") }}
                </div>
            </div>

            <div class="mt-6" x-show="consultation" x-cloak>
                <a :href="consultation?.details_url" class="block rounded-3xl border border-gray-200 bg-white hover:border-blue-300 hover:shadow-lg transition shadow-sm">
                    <template x-if="consultation">
                        <div class="p-6 sm:p-8">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Active Consultation</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-900" x-text="consultationTitle()"></h3>
                                    <p class="mt-1 text-sm text-slate-600" x-text="'Summary of symptoms: ' + (consultation?.summary || 'No symptoms recorded')"></p>
                                </div>
                                <div class="inline-flex items-center gap-3">
                                    <span :class="consultation?.status_badge_class" x-text="consultation?.status_label"></span>
                                    <template x-if="consultation?.show_messaging && consultation?.session">
                                        <span class="inline-flex items-center rounded-full bg-indigo-100 px-2.5 py-1 text-indigo-700" title="Messaging available">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75h6.75m-6.75 3h4.5m6.375 7.5-3.375-2.025a3.75 3.75 0 0 0-1.928-.525H6.75A3.75 3.75 0 0 1 3 13.95V7.5A3.75 3.75 0 0 1 6.75 3.75h10.5A3.75 3.75 0 0 1 21 7.5v8.25a3.75 3.75 0 0 1-1.5 3z" />
                                            </svg>
                                            <span x-show="(consultation?.session?.unread_count || 0) > 0" x-cloak class="ml-1 rounded-full bg-indigo-600 px-1.5 py-0.5 text-[10px] font-bold text-white" :data-unread-badge="consultation?.session?.id" x-text="formatUnreadCount(consultation?.session?.unread_count || 0)"></span>
                                            <span class="sr-only">Messaging available</span>
                                        </span>
                                    </template>
                                    <template x-if="consultation?.session">
                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="consultation?.session?.clinical_badge_class" x-text="consultation?.session?.clinical_label">
                                        </span>
                                    </template>
                                    <template x-if="consultation?.session">
                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="consultation?.session?.prescription_badge_class" x-text="consultation?.session?.prescription_label">
                                        </span>
                                    </template>
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                            </div>
                            <div class="mt-6 grid gap-4 sm:grid-cols-2">
                                <div class="rounded-2xl border border-gray-200 bg-slate-50 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Submitted</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900" x-text="consultation?.submitted_at"></p>
                                </div>
                                <div class="rounded-2xl border border-gray-200 bg-slate-50 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Status</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900" x-text="consultation?.status_label"></p>
                                </div>
                            </div>
                        </div>
                    </template>
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
