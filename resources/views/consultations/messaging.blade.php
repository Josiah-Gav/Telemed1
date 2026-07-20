<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Consultation Messaging') }}
        </h2>
    </x-slot>

    @php
        $consultationRequest = $session->request;
        $patientName = trim((optional($consultationRequest->patient)->first_name ?? '') . ' ' . (optional($consultationRequest->patient)->last_name ?? '')) ?: 'Patient';
        $physicianName = trim((optional($session->physician)->first_name ?? '') . ' ' . (optional($session->physician)->last_name ?? '')) ?: 'Physician';
        $nurseName = trim((optional($consultationRequest->nurse)->first_name ?? '') . ' ' . (optional($consultationRequest->nurse)->last_name ?? '')) ?: 'Unassigned';
        $currentUser = auth()->user();
        $backUrl = $currentUser && $currentUser->role === 'physician'
            ? route('physician.active_consultation', ['physician' => $currentUser->user_id])
            : route('dashboard');
        $backLabel = $currentUser && $currentUser->role === 'physician'
            ? 'Back to Active Consultations'
            : 'Back to Dashboard';
        $isAssignedPhysician = $currentUser && $currentUser->role === 'physician' && (int) $session->physician_id === (int) $currentUser->user_id;
        $isCompletedSession = $session->consultation_status === 'completed';
    @endphp

    <div class="py-10" x-data="consultationMessaging()" x-init="init()" @keydown.escape.window="clearAttachmentSelection()">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            <div class="mb-4">
                <a href="{{ $backUrl }}" class="inline-flex items-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    {{ __($backLabel) }}
                </a>
            </div>
            <div class="bg-white shadow-sm sm:rounded-xl border border-slate-200 overflow-hidden">
                <div class="border-b border-slate-200 bg-white px-6 py-3">
                    <div class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 p-1">
                        <button
                            type="button"
                            @click="activeTab = 'messages'"
                            :class="activeTab === 'messages' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-white text-slate-600 hover:bg-slate-100'"
                            class="rounded-lg px-4 py-2 text-sm font-semibold transition"
                        >
                            Messages
                        </button>
                        <button
                            type="button"
                            @click="activeTab = 'details'"
                            :class="activeTab === 'details' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-white text-slate-600 hover:bg-slate-100'"
                            class="rounded-lg px-4 py-2 text-sm font-semibold transition"
                        >
                            Details
                        </button>
                        <button
                            type="button"
                            @click="activeTab = 'assessment'"
                            :class="activeTab === 'assessment' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-white text-slate-600 hover:bg-slate-100'"
                            class="rounded-lg px-4 py-2 text-sm font-semibold transition"
                        >
                            Assessment
                        </button>
                    </div>
                </div>
                <div class="border-b border-slate-200 bg-slate-50 px-6 py-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-slate-500 font-semibold">Consultation Session #{{ $session->id }}</p>
                            <h3 class="text-lg font-semibold text-slate-900 mt-1">{{ $patientName }} and {{ $physicianName }}</h3>
                        </div>
                        <div class="text-sm text-slate-600">
                            <p><span class="font-semibold text-slate-800">Physician:</span> {{ $physicianName }}</p>
                            <p><span class="font-semibold text-slate-800">Status:</span> {{ ucfirst($session->consultation_status) }}</p>
                            @if($session->completed_at)
                                <p><span class="font-semibold text-slate-800">Completed:</span> {{ $session->completed_at->format('M d, Y @ h:i A') }}</p>
                            @endif
                            <p class="text-xs text-slate-500" x-text="presenceText"></p>
                        </div>
                    </div>
                </div>

                <div x-show="activeTab === 'messages'" x-cloak>
                    <div id="messagesContainer" class="h-[55vh] overflow-y-auto bg-gradient-to-b from-white to-slate-50 px-4 py-4 space-y-3">
                        <template x-if="messages.length === 0">
                            <div class="h-full flex items-center justify-center text-sm text-slate-500">
                                No messages yet. Start the consultation conversation.
                            </div>
                        </template>

                        <template x-for="msg in messages" :key="msg.message_id">
                            <div class="flex" :class="isMine(msg) ? 'justify-end' : 'justify-start'">
                                <div class="max-w-[80%] rounded-2xl px-4 py-3 shadow-sm"
                                    :class="isMine(msg) ? 'bg-indigo-600 text-white' : 'bg-white border border-slate-200 text-slate-800'">
                                    <p class="text-xs mb-1" :class="isMine(msg) ? 'text-indigo-100' : 'text-slate-500'" x-text="msg.sender_name || 'Unknown user'"></p>
                                    <template x-if="msg.message">
                                        <p class="text-sm whitespace-pre-wrap break-words" x-text="msg.message"></p>
                                    </template>

                                    <template x-if="msg.attachments && msg.attachments.length">
                                        <div class="mt-3 space-y-2">
                                            <template x-for="file in msg.attachments" :key="file.attachment_id">
                                                <a :href="file.download_url" class="block rounded-lg px-3 py-2 text-xs font-semibold"
                                                    :class="isMine(msg) ? 'bg-indigo-500 text-indigo-50 hover:bg-indigo-400' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'">
                                                    <span x-text="file.file_name"></span>
                                                    <span class="ml-2 opacity-80" x-text="formatFileSize(file.file_size)"></span>
                                                </a>
                                            </template>
                                        </div>
                                    </template>

                                    <div class="mt-2 flex items-center gap-1 text-[11px]" :class="isMine(msg) ? 'text-indigo-100 justify-end' : 'text-slate-400 justify-start'">
                                        <span x-text="formatTime(msg.created_at)"></span>

                                        <template x-if="isMine(msg)">
                                            <span class="inline-flex items-center" :title="msg.read_at ? 'Seen' : 'Sent'">
                                                <template x-if="!msg.read_at">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                                    </svg>
                                                </template>
                                                <template x-if="msg.read_at">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4 text-emerald-200" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13l4 4L17 7" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 13l4 4L21 7" />
                                                    </svg>
                                                </template>
                                            </span>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div class="border-t border-slate-200 bg-white px-4 py-4">
                        @if($isCompletedSession)
                            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                                This consultation has been completed. Messaging is now read-only.
                            </div>
                        @else
                        <form @submit.prevent="sendMessage" class="space-y-3">
                            <textarea
                                x-model="draft"
                                @input="handleDraftInput"
                                @blur="handleDraftBlur"
                                rows="3"
                                maxlength="2000"
                                class="w-full rounded-xl border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="Type your message..."></textarea>

                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex items-center gap-3">
                                    <input type="file" x-ref="attachments" @change="handleAttachments" multiple class="text-sm text-slate-600" />
                                    <button type="button" @click="clearAttachmentSelection" class="text-xs font-semibold text-slate-500 hover:text-slate-700">Clear files</button>
                                </div>
                                <button
                                    type="submit"
                                    :disabled="isSending"
                                    class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60">
                                    <span x-show="!isSending">Send</span>
                                    <span x-show="isSending">Sending...</span>
                                </button>
                            </div>

                            <template x-if="selectedFiles.length">
                                <div class="flex flex-wrap gap-2 pt-1">
                                    <template x-for="(file, idx) in selectedFiles" :key="idx">
                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700" x-text="file.name"></span>
                                    </template>
                                </div>
                            </template>
                        </form>
                        @endif
                    </div>
                </div>

                <div x-show="activeTab === 'details'" x-cloak class="bg-slate-50 px-4 py-4 sm:px-6">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="rounded-2xl border border-slate-200 bg-white p-5">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Consultation Summary</p>
                            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Patient</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900">{{ $patientName }}</p>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Physician</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900">{{ $physicianName }}</p>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Assigned Nurse</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900">{{ $nurseName }}</p>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Status</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900">{{ ucfirst($consultationRequest->request_status) }}</p>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Concern Category</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900">{{ ucfirst($consultationRequest->concern_category) }}</p>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Submitted</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900">{{ optional($consultationRequest->submitted_at)->format('M d, Y @ h:i A') }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-white p-5">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Reason for Online Consultation</p>
                            <p class="mt-4 text-sm leading-6 text-slate-700">{{ $consultationRequest->online_reason ?? 'No reason provided.' }}</p>
                        </div>
                    </div>

                    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Symptoms</p>
                        <div class="mt-4 space-y-3 text-sm text-slate-700">
                            @if(is_array($consultationRequest->symptoms_desc) && count($consultationRequest->symptoms_desc) > 0)
                                @foreach($consultationRequest->symptoms_desc as $symptom)
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <p class="font-semibold text-slate-900">{{ $symptom['name'] ?? $symptom }}</p>
                                        @if(!empty($symptom['date']) || !empty($symptom['time']))
                                            <p class="mt-1 text-xs text-slate-500">Started: {{ $symptom['date'] ?? 'Unknown' }} {{ $symptom['time'] ?? '' }}</p>
                                        @endif
                                        @if(!empty($symptom['severity']))
                                            <p class="mt-1 text-xs text-slate-500">Severity: {{ $symptom['severity'] }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            @else
                                <p class="text-sm text-slate-500">No symptoms were recorded for this consultation.</p>
                            @endif
                        </div>
                    </div>

                    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Attachments</p>
                        @if(!empty($consultationRequest->file_attachments))
                            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach($consultationRequest->file_attachments as $attachment)
                                    <a href="{{ $attachment }}" target="_blank" class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-900 hover:bg-slate-100">
                                        View attachment
                                    </a>
                                @endforeach
                            </div>
                        @else
                            <p class="mt-4 text-sm text-slate-500">No attachments were uploaded for this consultation.</p>
                        @endif
                    </div>
                </div>

                <div x-show="activeTab === 'assessment'" x-cloak class="bg-slate-50 px-4 py-4 sm:px-6">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Clinical Documentation</p>
                                <h4 class="mt-1 text-lg font-semibold text-slate-900">Assessment, plan, recommendations, diagnosis, and prescription</h4>
                            </div>
                            <div class="flex flex-col items-start gap-2 sm:items-end">
                                <template x-if="saveMessage">
                                    <p class="text-sm font-medium text-emerald-600" x-text="saveMessage"></p>
                                </template>
                                @if($isCompletedSession)
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">Completed</span>
                                @endif
                            </div>
                        </div>

                        @if($isAssignedPhysician && !$isCompletedSession)
                            <form @submit.prevent="saveClinicalDetails" class="mt-6 space-y-5">
                                <div class="grid gap-5 lg:grid-cols-2">
                                    <div>
                                        <label class="block text-sm font-semibold text-slate-800">Diagnosis</label>
                                        <input
                                            type="text"
                                            x-model="clinical.diagnosis"
                                            maxlength="255"
                                            class="mt-2 w-full rounded-xl border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            placeholder="Enter diagnosis"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-semibold text-slate-800">Prescription Upload</label>
                                        <input type="file" x-ref="prescription" @change="handlePrescription" class="mt-2 block w-full text-sm text-slate-600" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" />
                                        <div class="mt-2 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                                            <template x-if="selectedPrescriptionName">
                                                <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 font-medium text-slate-700" x-text="selectedPrescriptionName"></span>
                                            </template>
                                            <template x-if="clinical.prescription.download_url && !selectedPrescriptionName">
                                                <a :href="clinical.prescription.download_url" class="font-semibold text-indigo-600 hover:text-indigo-700">Download current prescription</a>
                                            </template>
                                            <template x-if="clinical.prescription.file_name && !selectedPrescriptionName">
                                                <button type="button" @click="removePrescription" class="font-semibold text-rose-600 hover:text-rose-700">Remove current prescription</button>
                                            </template>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-slate-800">Assessment</label>
                                    <textarea
                                        x-model="clinical.assessment"
                                        rows="4"
                                        maxlength="10000"
                                        class="mt-2 w-full rounded-xl border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        placeholder="Document the assessment"></textarea>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-slate-800">Plan</label>
                                    <textarea
                                        x-model="clinical.plan"
                                        rows="4"
                                        maxlength="10000"
                                        class="mt-2 w-full rounded-xl border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        placeholder="Document the treatment plan"></textarea>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-slate-800">Recommendations</label>
                                    <textarea
                                        x-model="clinical.recommendations"
                                        rows="4"
                                        maxlength="10000"
                                        class="mt-2 w-full rounded-xl border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        placeholder="Provide physician recommendations"></textarea>
                                </div>

                                <div class="flex items-center justify-end gap-3">
                                    <button
                                        type="button"
                                        @click="completeConsultation"
                                        :disabled="isCompletingConsultation"
                                        class="inline-flex items-center justify-center rounded-lg border border-emerald-600 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50 disabled:opacity-60">
                                        <span x-show="!isCompletingConsultation">Complete consultation</span>
                                        <span x-show="isCompletingConsultation">Completing...</span>
                                    </button>
                                    <button
                                        type="submit"
                                        :disabled="isSavingClinical"
                                        class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60">
                                        <span x-show="!isSavingClinical">Save clinical details</span>
                                        <span x-show="isSavingClinical">Saving...</span>
                                    </button>
                                </div>
                            </form>
                        @else
                            <div class="mt-6 grid gap-5 lg:grid-cols-2">
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Diagnosis</p>
                                    <p class="mt-2 text-sm text-slate-700" x-text="clinical.diagnosis || 'No diagnosis added yet.'"></p>
                                </div>
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Prescription</p>
                                    <template x-if="clinical.prescription.download_url">
                                        <div class="mt-2 space-y-2">
                                            <p class="text-sm font-medium text-slate-800" x-text="clinical.prescription.file_name"></p>
                                            <a :href="clinical.prescription.download_url" class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Download prescription</a>
                                        </div>
                                    </template>
                                    <template x-if="!clinical.prescription.download_url">
                                        <p class="mt-2 text-sm text-slate-700">No prescription uploaded yet.</p>
                                    </template>
                                </div>
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Assessment</p>
                                    <p class="mt-2 text-sm leading-6 text-slate-700 whitespace-pre-wrap" x-text="clinical.assessment || 'No assessment recorded yet.'"></p>
                                </div>
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Plan</p>
                                    <p class="mt-2 text-sm leading-6 text-slate-700 whitespace-pre-wrap" x-text="clinical.plan || 'No plan recorded yet.'"></p>
                                </div>
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 lg:col-span-2">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Recommendations</p>
                                    <p class="mt-2 text-sm leading-6 text-slate-700 whitespace-pre-wrap" x-text="clinical.recommendations || 'No recommendations recorded yet.'"></p>
                                </div>
                            </div>

                            @if($isCompletedSession)
                                <div class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                                    This clinical documentation is locked because the consultation has been completed.
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function consultationMessaging() {
            return {
                activeTab: 'messages',
                messages: [],
                draft: '',
                selectedFiles: [],
                selectedPrescriptionFile: null,
                selectedPrescriptionName: '',
                removePrescriptionOnSave: false,
                isSending: false,
                isSavingClinical: false,
                isCompletingConsultation: false,
                poller: null,
                presencePoller: null,
                typingTimeout: null,
                isTyping: false,
                presenceText: 'Checking participant status...',
                saveMessage: '',
                consultationStatus: @js($session->consultation_status),
                consultationCompletedAt: @js(optional($session->completed_at)?->toIso8601String()),
                clinical: {
                    assessment: @js($session->assessment),
                    plan: @js($session->plan),
                    recommendations: @js($session->recommendations),
                    diagnosis: @js($session->diagnosis),
                    prescription: {
                        file_name: @js($session->prescription_file_name),
                        file_size: @js($session->prescription_file_size),
                        download_url: @js($session->prescription_file_path ? route('consultations.messaging.prescription.download', $session) : null),
                    }
                },
                currentUserId: {{ (int) auth()->user()->user_id }},
                sessionId: {{ (int) $session->id }},
                fetchUrl: '{{ route('consultations.messaging.index', $session) }}',
                postUrl: '{{ route('consultations.messaging.store', $session) }}',
                readUrl: '{{ route('consultations.messaging.read', $session) }}',
                clinicalUpdateUrl: '{{ route('consultations.messaging.clinical_details.update', $session) }}',
                completeUrl: '{{ route('consultations.messaging.complete', $session) }}',
                typingUrl: '{{ route('consultations.messaging.typing', $session) }}',
                presenceUrl: '{{ route('consultations.messaging.presence', $session) }}',
                init() {
                    this.fetchMessages(true);
                    this.fetchPresence();
                    this.poller = setInterval(() => this.fetchMessages(false), 3000);
                    this.presencePoller = setInterval(() => this.fetchPresence(), 4000);
                    window.addEventListener('beforeunload', () => {
                        if (this.poller) {
                            clearInterval(this.poller);
                        }

                        if (this.presencePoller) {
                            clearInterval(this.presencePoller);
                        }

                        this.sendTypingState(false);
                    });
                },
                isMine(msg) {
                    return Number(msg.sender_id) === Number(this.currentUserId);
                },
                formatTime(iso) {
                    if (!iso) return '';
                    const date = new Date(iso);
                    return date.toLocaleString();
                },
                formatFileSize(size) {
                    if (!size) return '(0 B)';
                    if (size < 1024) return `(${size} B)`;
                    if (size < 1024 * 1024) return `(${(size / 1024).toFixed(1)} KB)`;
                    return `(${(size / (1024 * 1024)).toFixed(1)} MB)`;
                },
                scrollToBottom() {
                    const container = $('#messagesContainer').get(0);
                    if (!container) return;
                    container.scrollTop = container.scrollHeight;
                },
                fetchMessages(scroll) {
                    if (this.consultationStatus !== 'active' && this.consultationStatus !== 'completed') {
                        return;
                    }

                    const csrfToken = $('meta[name="csrf-token"]').attr('content');

                    $.ajax({
                        url: this.fetchUrl,
                        method: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        success: (data) => {
                            const previousCount = this.messages.length;
                            this.messages = data.messages || [];

                            if (scroll || this.messages.length !== previousCount) {
                                this.$nextTick(() => this.scrollToBottom());
                            }

                            $.ajax({
                                url: this.readUrl,
                                method: 'POST',
                                headers: {
                                    'X-CSRF-TOKEN': csrfToken,
                                    'X-Requested-With': 'XMLHttpRequest'
                                }
                            });
                        },
                        error: (xhr) => {
                            console.error('Failed to fetch messages:', xhr);
                        }
                    });
                },
                handleAttachments(event) {
                    this.selectedFiles = Array.from(event.target.files || []);
                },
                clearAttachmentSelection() {
                    this.selectedFiles = [];
                    if (this.$refs.attachments) {
                        this.$refs.attachments.value = '';
                    }
                },
                handlePrescription(event) {
                    this.selectedPrescriptionFile = event.target.files?.[0] || null;
                    this.selectedPrescriptionName = this.selectedPrescriptionFile ? this.selectedPrescriptionFile.name : '';
                    this.removePrescriptionOnSave = false;
                },
                removePrescription() {
                    this.selectedPrescriptionFile = null;
                    this.selectedPrescriptionName = '';
                    this.removePrescriptionOnSave = true;
                    this.clinical.prescription = {
                        file_name: null,
                        file_size: null,
                        download_url: null,
                    };

                    if (this.$refs.prescription) {
                        this.$refs.prescription.value = '';
                    }
                },
                formatLastSeen(iso) {
                    if (!iso) return 'Last seen unavailable';
                    const date = new Date(iso);
                    if (Number.isNaN(date.getTime())) return 'Last seen unavailable';
                    return 'Last seen ' + date.toLocaleString();
                },
                fetchPresence() {
                    if (this.consultationStatus !== 'active') {
                        return;
                    }

                    $.ajax({
                        url: this.presenceUrl,
                        method: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        success: (data) => {
                            const peer = data.peer || {};

                            if (peer.is_typing) {
                                this.presenceText = (peer.name || 'Participant') + ' is typing...';
                                return;
                            }

                            this.presenceText = this.formatLastSeen(peer.last_seen_at);
                        }
                    });
                },
                sendTypingState(isTyping) {
                    if (this.consultationStatus !== 'active') {
                        return;
                    }

                    const csrfToken = $('meta[name="csrf-token"]').attr('content');
                    this.isTyping = isTyping;

                    $.ajax({
                        url: this.typingUrl,
                        method: 'POST',
                        data: {
                            is_typing: isTyping ? 1 : 0
                        },
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                },
                handleDraftInput() {
                    if (this.consultationStatus !== 'active') {
                        return;
                    }

                    if (!this.isTyping) {
                        this.sendTypingState(true);
                    }

                    if (this.typingTimeout) {
                        clearTimeout(this.typingTimeout);
                    }

                    this.typingTimeout = setTimeout(() => {
                        this.sendTypingState(false);
                    }, 2500);
                },
                handleDraftBlur() {
                    if (this.typingTimeout) {
                        clearTimeout(this.typingTimeout);
                        this.typingTimeout = null;
                    }

                    this.sendTypingState(false);
                },
                saveClinicalDetails() {
                    if (this.isSavingClinical) return;
                    if (this.consultationStatus !== 'active') return;

                    this.isSavingClinical = true;
                    this.saveMessage = '';

                    const formData = new FormData();
                    const csrfToken = $('meta[name="csrf-token"]').attr('content');
                    formData.append('assessment', this.clinical.assessment || '');
                    formData.append('plan', this.clinical.plan || '');
                    formData.append('recommendations', this.clinical.recommendations || '');
                    formData.append('diagnosis', this.clinical.diagnosis || '');
                    formData.append('remove_prescription', this.removePrescriptionOnSave ? '1' : '0');

                    if (this.selectedPrescriptionFile) {
                        formData.append('prescription', this.selectedPrescriptionFile);
                    }

                    $.ajax({
                        url: this.clinicalUpdateUrl,
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        success: (data) => {
                            if (!data.success) {
                                Swal.fire('Save failed', data.message || 'Unable to update clinical details.', 'error');
                                return;
                            }

                            if (data.clinical_details) {
                                this.clinical = data.clinical_details;
                                this.consultationStatus = data.clinical_details.status || this.consultationStatus;
                                this.consultationCompletedAt = data.clinical_details.completed_at || this.consultationCompletedAt;
                            }

                            this.selectedPrescriptionFile = null;
                            this.selectedPrescriptionName = '';
                            this.removePrescriptionOnSave = false;
                            if (this.$refs.prescription) {
                                this.$refs.prescription.value = '';
                            }

                            this.saveMessage = data.message || 'Clinical details updated successfully.';
                        },
                        error: (xhr) => {
                            const message = xhr.responseJSON?.message || 'Unable to update clinical details.';
                            Swal.fire('Save failed', message, 'error');
                        },
                        complete: () => {
                            this.isSavingClinical = false;
                        }
                    });
                },
                completeConsultation() {
                    if (this.isCompletingConsultation || this.consultationStatus !== 'active') return;

                    Swal.fire({
                        title: 'Complete consultation?',
                        text: 'This will lock messaging and the assessment tab for further edits.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Complete',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#059669'
                    }).then((result) => {
                        if (!result.isConfirmed) {
                            return;
                        }

                        this.isCompletingConsultation = true;
                        const csrfToken = $('meta[name="csrf-token"]').attr('content');

                        $.ajax({
                            url: this.completeUrl,
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': csrfToken,
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            success: (data) => {
                                this.consultationStatus = data.session_status || 'completed';
                                this.consultationCompletedAt = data.completed_at || null;
                                this.saveMessage = data.message || 'Consultation completed successfully.';
                                this.handleDraftBlur();
                                this.activeTab = 'assessment';
                                window.location.reload();
                            },
                            error: (xhr) => {
                                const message = xhr.responseJSON?.message || 'Unable to complete the consultation.';
                                Swal.fire('Completion failed', message, 'error');
                            },
                            complete: () => {
                                this.isCompletingConsultation = false;
                            }
                        });
                    });
                },
                sendMessage() {
                    if (this.isSending) return;
                    if (this.consultationStatus !== 'active') return;

                    const content = this.draft.trim();
                    if (!content && this.selectedFiles.length === 0) {
                        Swal.fire('Cannot send', 'Add a message or attach a file first.', 'warning');
                        return;
                    }

                    this.isSending = true;

                    const formData = new FormData();
                    const csrfToken = $('meta[name="csrf-token"]').attr('content');
                    formData.append('message', content);
                    this.selectedFiles.forEach(file => formData.append('attachments[]', file));

                    $.ajax({
                        url: this.postUrl,
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        success: (data) => {
                            if (!data.success) {
                                Swal.fire('Send failed', data.message || 'Unable to send message.', 'error');
                                return;
                            }

                            this.draft = '';
                            this.handleDraftBlur();
                            this.clearAttachmentSelection();
                            this.fetchMessages(true);
                        },
                        error: (xhr) => {
                            const message = xhr.responseJSON?.message || 'Unable to send message.';
                            Swal.fire('Send failed', message, 'error');
                        },
                        complete: () => {
                            this.isSending = false;
                        }
                    });
                }
            }
        }
    </script>
</x-app-layout>
