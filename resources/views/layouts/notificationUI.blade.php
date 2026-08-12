<div class="relative ml-auto" x-data="notificationPanel()" x-init="init()">
    <!-- Notification Bell Button -->
    <button 
        type="button" 
        @click="togglePanel()" 
        class="relative inline-flex items-center justify-center rounded-full p-2 text-slate-600 transition hover:bg-brand-green-soft hover:text-brand-green-deep"
        aria-label="Notifications"
    >
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
        </svg>
        
        <!-- Unread Count Badge -->
        <span 
            x-show="unreadCount > 0" 
            x-text="unreadCount" 
            class="absolute -right-0.5 -top-0.5 inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-red-500 px-1.5 text-[11px] font-bold text-white"
        ></span>
    </button>

    <!-- Notification Dropdown Panel -->
    <div 
        x-show="open" 
        x-cloak 
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        @click.away="open = false"
        class="absolute right-0 mt-2 w-96 max-w-[calc(100vw-2rem)] rounded-2xl border border-brand-border bg-white shadow-xl z-50"
    >
        <!-- Panel Header -->
        <div class="flex items-center justify-between border-b border-brand-border px-5 py-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.22em] text-brand-green">Notifications</p>
                <p class="mt-1 text-sm font-semibold text-slate-900">Recent updates</p>
            </div>
            <button 
                type="button" 
                @click="markAllRead()" 
                class="text-xs font-semibold text-brand-green hover:text-brand-green-deep transition"
            >
                Mark all read
            </button>
        </div>

        <!-- Notification List -->
        <div class="max-h-96 overflow-y-auto p-3">
            <!-- Loading State -->
            <template x-if="loading">
                <div class="rounded-2xl border border-dashed border-brand-border bg-slate-50 p-5 text-sm text-slate-500">
                    Loading notifications...
                </div>
            </template>

            <!-- Empty State -->
            <template x-if="!loading && notifList.length === 0">
                <div class="rounded-2xl border border-dashed border-brand-border bg-slate-50 p-5 text-sm text-slate-500">
                    No notifications yet. New alerts will appear here.
                </div>
            </template>

            <!-- Notification Items -->
            <template x-for="n in notifList.slice(0, 5)" :key="n.notification_id">
                <button 
                    type="button" 
                    @click="markAsRead(n)" 
                    class="mb-2 w-full rounded-2xl border p-4 text-left transition hover:-translate-y-0.5 hover:shadow-sm"
                    :class="n.read_at ? 'border-slate-200 bg-white' : 'border-brand-green/30 bg-brand-green-soft'"
                >
                    <div class="flex items-center justify-between gap-3">
                        <span 
                            class="inline-flex items-center rounded-full px-2 py-1 text-[10px] font-bold uppercase tracking-wide"
                            :class="n.read_at ? 'bg-slate-200 text-slate-600' : 'bg-brand-green text-white'"
                            x-text="n.read_at ? 'Read' : 'New'"
                        ></span>
                        <span class="text-[11px] font-medium text-slate-500" x-text="formatTime(n.created_at)"></span>
                    </div>
                    <p class="mt-2 text-sm font-bold text-slate-900" x-text="n.title"></p>
                    <p class="mt-1 text-xs text-slate-600 line-clamp-2" x-text="n.message"></p>
                </button>
            </template>
        </div>

        <!-- Panel Footer
        <div class="border-t border-brand-border px-5 py-3">
            <a 
                href="{{ route('notifications.index') }}" 
                class="block text-center text-sm font-semibold text-brand-green hover:text-brand-green-deep transition"
            >
                View all notifications
            </a>
        </div> -->
    </div>
</div>