<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('Notifications') }}
        </h2>
    </x-slot>

    <div
        class="py-10"
        x-data="notificationsPage(@js($initialResponse))"
        x-init="init()"
    >
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- Screen-reader-only status line: announces the outcome of mark-as-read
                 actions as one full sentence, rather than exposing the raw unread
                 count as its own competing live region. --}}
            <span role="status" aria-atomic="true" class="sr-only" x-text="statusMessage"></span>

            <div class="rounded-2xl border border-brand-border bg-white shadow-sm overflow-hidden">
                <div class="flex flex-col gap-4 border-b border-brand-border px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="flex items-center gap-2" role="tablist" aria-label="{{ __('Filter notifications') }}">
                            <button
                                type="button"
                                role="tab"
                                :aria-pressed="filter === 'all' ? 'true' : 'false'"
                                @click="setFilter('all')"
                                class="rounded-full px-4 py-2 text-sm font-semibold transition"
                                :class="filter === 'all' ? 'bg-brand-green text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                            >
                                {{ __('All') }}
                            </button>
                            <button
                                type="button"
                                role="tab"
                                :aria-pressed="filter === 'unread' ? 'true' : 'false'"
                                @click="setFilter('unread')"
                                class="rounded-full px-4 py-2 text-sm font-semibold transition"
                                :class="filter === 'unread' ? 'bg-brand-green text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                            >
                                {{ __('Unread') }}
                            </button>
                        </div>

                        <div>
                            <label for="date_filter" class="sr-only">{{ __('Date range') }}</label>
                            <select
                                id="date_filter"
                                x-model="dateFilter"
                                @change="setDateFilter($event.target.value)"
                                class="rounded-full border border-brand-border bg-white px-4 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-green-100"
                            >
                                <option value="all">{{ __('All time') }}</option>
                                <option value="today">{{ __('Today') }}</option>
                                <option value="last_7_days">{{ __('Last 7 Days') }}</option>
                                <option value="last_30_days">{{ __('Last 30 Days') }}</option>
                            </select>
                        </div>
                    </div>

                    <button
                        type="button"
                        @click="markAllRead()"
                        x-show="hasUnread"
                        class="inline-flex items-center justify-center rounded-full border border-brand-border bg-white px-4 py-2 text-sm font-semibold text-brand-green transition hover:bg-brand-green-soft"
                    >
                        {{ __('Mark all read') }}
                    </button>
                </div>

                <div class="p-4 sm:p-6">
                    {{-- Loading state --}}
                    <template x-if="loading">
                        <div class="space-y-3">
                            <template x-for="i in 3" :key="i">
                                <div class="animate-pulse rounded-2xl border border-slate-200 bg-slate-50 p-5">
                                    <div class="h-3 w-24 rounded bg-slate-200"></div>
                                    <div class="mt-3 h-4 w-2/3 rounded bg-slate-200"></div>
                                    <div class="mt-2 h-3 w-full rounded bg-slate-200"></div>
                                </div>
                            </template>
                        </div>
                    </template>

                    {{-- Empty state --}}
                    <template x-if="!loading && list.length === 0">
                        <div class="rounded-2xl border border-dashed border-brand-border bg-slate-50 p-10 text-center">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="mx-auto h-10 w-10 text-slate-300" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                            </svg>
                            <p class="mt-4 text-sm font-semibold text-slate-700" x-text="dateFilter !== 'all' ? '{{ __('No notifications in this range') }}' : (filter === 'unread' ? '{{ __('You\'re all caught up') }}' : '{{ __('No notifications yet') }}')"></p>
                            <p class="mt-1 text-sm text-slate-500" x-text="dateFilter !== 'all' ? '{{ __('Try a wider date range.') }}' : (filter === 'unread' ? '{{ __('No unread notifications right now.') }}' : '{{ __('New alerts about your consultations will appear here.') }}')"></p>
                        </div>
                    </template>

                    {{-- Notification list --}}
                    <ul class="space-y-3" role="list">
                        <template x-for="n in list" :key="n.notification_id">
                            <li>
                                <button
                                    type="button"
                                    @click="markAsRead(n)"
                                    class="w-full rounded-2xl border p-5 text-left transition hover:-translate-y-0.5 hover:shadow-sm"
                                    :class="n.read_at ? 'border-slate-200 bg-white' : 'border-brand-green/30 bg-brand-green-soft'"
                                >
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="inline-flex items-center rounded-full px-2 py-1 text-[10px] font-bold uppercase tracking-wide"
                                                :class="n.read_at ? 'bg-slate-200 text-slate-600' : 'bg-brand-green text-white'"
                                                x-text="n.read_at ? '{{ __('Read') }}' : '{{ __('New') }}'"
                                            ></span>
                                            <span class="inline-flex items-center rounded-full bg-brand-gold-soft px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-brand-green-deep" x-text="typeLabel(n.type)"></span>
                                        </div>
                                        <span class="text-xs font-medium text-slate-500" x-text="formatTime(n.created_at)"></span>
                                    </div>
                                    <p class="mt-3 text-sm font-bold text-slate-900" x-text="n.title"></p>
                                    <p class="mt-1 text-sm text-slate-600" x-text="n.message"></p>
                                </button>
                            </li>
                        </template>
                    </ul>

                    {{-- Load more --}}
                    <div class="mt-6 text-center" x-show="!loading && list.length > 0">
                        <p class="text-xs text-slate-500" x-text="`{{ __('Showing') }} ${list.length} {{ __('of') }} ${total}`"></p>
                        <button
                            type="button"
                            @click="loadMore()"
                            x-show="page < lastPage"
                            :disabled="loadingMore"
                            class="mt-3 inline-flex items-center justify-center rounded-full border border-brand-border bg-white px-5 py-2 text-sm font-semibold text-brand-green transition hover:bg-brand-green-soft disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <span x-show="!loadingMore">{{ __('Load more') }}</span>
                            <span x-show="loadingMore">{{ __('Loading...') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function notificationsPage(initial) {
            return {
                csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                userId: {{ (int) auth()->id() }},
                role: @js(auth()->user()->role),
                routes: {
                    index: '{{ route('notifications.index') }}',
                    readAll: '{{ route('notifications.read_all') }}'
                },
                filter: 'all',
                dateFilter: 'all',
                loading: false,
                loadingMore: false,
                list: initial?.data ?? [],
                page: initial?.meta?.current_page ?? 1,
                lastPage: initial?.meta?.last_page ?? 1,
                total: initial?.meta?.total ?? 0,
                statusMessage: '',
                init() {
                    // The server already rendered `initial` using this same
                    // query string (NotificationController::all() passes the
                    // request straight into index()), so this only syncs the
                    // tab/select UI to match — it must not re-fetch, or the
                    // page would double-request itself on every load.
                    const params = new URLSearchParams(window.location.search);
                    const allowedDateFilters = ['today', 'last_7_days', 'last_30_days'];

                    this.filter = params.get('filter') === 'unread' ? 'unread' : 'all';
                    this.dateFilter = allowedDateFilters.includes(params.get('date_filter')) ? params.get('date_filter') : 'all';
                },
                get hasUnread() {
                    return this.list.some(n => !n.read_at);
                },
                setFilter(filter) {
                    if (this.filter === filter) return;
                    this.filter = filter;
                    this.applyFilters();
                },
                setDateFilter(dateFilter) {
                    this.dateFilter = dateFilter;
                    this.applyFilters();
                },
                applyFilters() {
                    const url = new URL(window.location.href);
                    if (this.filter === 'unread') {
                        url.searchParams.set('filter', 'unread');
                    } else {
                        url.searchParams.delete('filter');
                    }
                    if (this.dateFilter !== 'all') {
                        url.searchParams.set('date_filter', this.dateFilter);
                    } else {
                        url.searchParams.delete('date_filter');
                    }
                    window.history.replaceState({}, '', url);

                    this.fetchPage(1, true);
                },
                fetchPage(page, replace) {
                    if (page === 1) {
                        this.loading = true;
                    } else {
                        this.loadingMore = true;
                    }

                    $.ajax({
                        url: this.routes.index,
                        method: 'GET',
                        data: { page, unread: this.filter === 'unread' ? 1 : 0, date_filter: this.dateFilter },
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        success: (data) => {
                            const items = data?.data ?? [];
                            this.list = replace ? items : this.list.concat(items);
                            this.page = data?.meta?.current_page ?? page;
                            this.lastPage = data?.meta?.last_page ?? page;
                            this.total = data?.meta?.total ?? this.list.length;
                        },
                        complete: () => {
                            this.loading = false;
                            this.loadingMore = false;
                        }
                    });
                },
                loadMore() {
                    if (this.page >= this.lastPage) return;
                    this.fetchPage(this.page + 1, false);
                },
                markAsRead(n) {
                    if (n.read_at) {
                        this.navigate(n);
                        return;
                    }

                    const url = this.routes.index + '/' + n.notification_id + '/read';

                    $.ajax({
                        url: url,
                        method: 'PATCH',
                        headers: {
                            'X-CSRF-TOKEN': this.csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        },
                        success: () => {
                            n.read_at = new Date().toISOString();
                            this.statusMessage = `Marked "${n.title}" as read`;
                            this.navigate(n);
                        },
                        error: () => {
                            this.navigate(n);
                        }
                    });
                },
                markAllRead() {
                    $.ajax({
                        url: this.routes.readAll,
                        method: 'PATCH',
                        headers: {
                            'X-CSRF-TOKEN': this.csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        },
                        success: () => {
                            if (this.filter === 'unread') {
                                this.list = [];
                            } else {
                                this.list.forEach(n => {
                                    if (!n.read_at) {
                                        n.read_at = new Date().toISOString();
                                    }
                                });
                            }
                            this.statusMessage = 'All notifications marked as read';
                        }
                    });
                },
                navigate(n) {
                    window.location.href = window.notificationNav.resolveUrl(n, this.role, this.userId);
                },
                formatTime(iso) {
                    return window.notificationNav.formatTime(iso);
                },
                typeLabel(type) {
                    return (type || '').split('_').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
                }
            };
        }
    </script>
</x-app-layout>
