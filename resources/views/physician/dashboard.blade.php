<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="overflow-hidden rounded-3xl border border-brand-border bg-gradient-to-r from-brand-green-soft via-white to-brand-gold-soft shadow-sm">
                <div class="p-6 text-brand-green-deep sm:p-8">
                    <p class="text-xs font-bold uppercase text-brand-green">Physician Dashboard</p>
                    <h2 class="mt-2 text-2xl font-bold text-slate-900">
                        {{ __("Hello Doc ". Auth::user()->first_name ."!") }}
                    </h2>
                </div>
            </div>

            <div class="mt-6" x-data="dashboardNotifications()" x-init="init()">
                <div class="overflow-hidden rounded-3xl border border-brand-border bg-white shadow-sm">
                    <div class="flex flex-col gap-4 border-b border-brand-border px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase text-brand-green">Notifications</p>
                            <h3 class="mt-2 text-xl font-bold text-slate-900">Recent updates</h3>
                        </div>
                        <button type="button" @click="markAllRead()" class="inline-flex items-center justify-center rounded-full border border-brand-border bg-brand-green-soft px-3 py-2 text-sm font-semibold text-brand-green-deep hover:bg-brand-green hover:text-white transition">
                            Mark all as read
                        </button>
                    </div>

                    <div class="grid gap-4 p-6 md:grid-cols-2">
                        <template x-if="loading">
                            <div class="rounded-2xl border border-dashed border-brand-border bg-slate-50 p-5 text-sm text-slate-500">Loading notifications...</div>
                        </template>

                        <template x-if="!loading && notifList.length === 0">
                            <div class="rounded-2xl border border-dashed border-brand-border bg-slate-50 p-5 text-sm text-slate-500 md:col-span-2">No notifications yet. New alerts will appear here.</div>
                        </template>

                        <template x-for="n in notifList.slice(0, 4)" :key="n.notification_id">
                            <button type="button" @click="markAsRead(n)" class="rounded-2xl border p-4 text-left transition hover:-translate-y-0.5 hover:shadow-sm" :class="n.read_at ? 'border-slate-200 bg-white' : 'border-brand-green/30 bg-brand-green-soft'">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="inline-flex items-center rounded-full px-2 py-1 text-[10px] font-bold uppercase tracking-wide" :class="n.read_at ? 'bg-slate-200 text-slate-600' : 'bg-brand-green text-white'" x-text="n.read_at ? 'Read' : 'New'"></span>
                                    <span class="text-[11px] font-medium text-slate-500" x-text="formatTime(n.created_at)"></span>
                                </div>
                                <p class="mt-3 text-base font-bold text-slate-900" x-text="n.title"></p>
                                <p class="mt-2 text-sm text-slate-600" x-text="n.message"></p>
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
