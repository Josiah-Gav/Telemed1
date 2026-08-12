<nav x-data="{ open: false }" class="lg:w-72 lg:flex-shrink-0">
    <aside class="hidden h-screen w-72 flex-col border-r border-brand-border bg-white/90 shadow-[0_0_0_1px_rgba(15,23,42,0.04)] backdrop-blur-sm lg:flex">
        <div class="flex items-center gap-3 border-b border-brand-border px-5 py-4">
            <a href="{{ Auth::check() && Auth::user()->role === 'nurse' ? route('nurse.dashboard', ['nurse' => Auth::user()]) : route('dashboard') }}" class="flex items-center gap-3">
                <x-application-logo class="block h-9 w-auto fill-current text-brand-green" />
                <span class="text-base font-bold tracking-wide text-slate-900">CLSU Telemedicine</span>
            </a>
        </div>

        <div class="flex-1 overflow-y-auto px-3 py-5">
            <div class="space-y-1">
                @if(Auth::check() && Auth::user()->role === 'nurse')
                    <x-nav-link :href="route('nurse.dashboard', ['nurse' => Auth::user()])" :active="request()->routeIs('nurse.dashboard')">
                        {{ __('Dashboard') }}
                    </x-nav-link>

                    <x-nav-link :href="route('nurse.consultation_inbox', ['nurse' => Auth::user()])" :active="request()->routeIs('nurse.consultation_inbox')">
                        {{ __('Consultation Inbox') }}
                    </x-nav-link>

                    <x-nav-link :href="route('nurse.follow_up_requests', ['nurse' => Auth::user()])" :active="request()->routeIs('nurse.follow_up_requests')">
                        {{ __('Follow-up Requests') }}
                    </x-nav-link>

                    <x-nav-link :href="route('nurse.consultation_history', ['nurse' => Auth::user()])" :active="request()->routeIs('nurse.consultation_history')">
                        {{ __('Consultation History') }}
                    </x-nav-link>
                @elseif(Auth::check() && Auth::user()->role === 'physician')
                    <x-nav-link :href="route('physician.dashboard', ['physician' => Auth::user()])" :active="request()->routeIs('physician.dashboard')">
                        {{ __('Dashboard') }}
                    </x-nav-link>
                    <x-nav-link :href="route('physician.consultation_inbox', ['physician' => Auth::user()])" :active="request()->routeIs('physician.consultation_inbox')">
                        {{ __('Consultation Inbox') }}
                    </x-nav-link>
                    <x-nav-link :href="route('physician.consultation_history', ['physician' => Auth::user()])" :active="request()->routeIs('physician.consultation_history')">
                        {{ __('Consultation History') }}
                    </x-nav-link>
                    <x-nav-link :href="route('physician.follow_up_requests', ['physician' => Auth::user()])" :active="request()->routeIs('physician.follow_up_requests')">
                        {{ __('Follow-up Requests') }}
                    </x-nav-link>
                    <x-nav-link :href="route('physician.active_consultation', ['physician' => Auth::user()])" :active="request()->routeIs('physician.active_consultation')">
                        {{ __('Active Consultations') }}
                    </x-nav-link>
                    <x-nav-link :href="route('physician.scheduled_consultation', ['physician' => Auth::user()])" :active="request()->routeIs('physician.scheduled_consultation')">
                        {{ __('Scheduled Consultations') }}
                    </x-nav-link>
                @elseif(Auth::check() && Auth::user()->role === 'admin')
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                        {{ __('Dashboard') }}
                    </x-nav-link>

                    <x-nav-link :href="route('admin.users.index')" :active="request()->routeIs('admin.users.index')">
                        {{ __('User Management') }}
                    </x-nav-link>
                @else
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                        {{ __('Dashboard') }}
                    </x-nav-link>

                    <x-nav-link :href="route('newconsultation')" :active="request()->routeIs('newconsultation')">
                        {{ __('New Consultation') }}
                    </x-nav-link>

                    <x-nav-link :href="route('consultations.history')" :active="request()->routeIs('consultations.history')">
                        {{ __('Consultation History') }}
                    </x-nav-link>
                @endif
            </div>
        </div>

        @auth
        <div class="border-t border-brand-border bg-brand-green-soft/70 px-3 py-4">
            <div class="flex items-center gap-2">
                <div class="flex-1">
                    <div class="rounded-xl border border-brand-border bg-white p-2 shadow-sm">
                        <div class="mb-2 truncate px-2 text-sm font-semibold text-slate-800">{{ Auth::user()->name }}</div>

                        <div class="flex items-center gap-2">
                            <a href="{{ route('profile.edit') }}" class="inline-flex flex-1 items-center justify-center rounded-lg border border-brand-border bg-slate-50 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-brand-green-soft hover:text-brand-green-deep">
                                {{ __('Profile') }}
                            </a>

                            <form method="POST" action="{{ route('logout') }}" class="flex-1">
                                @csrf

                                <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg border border-brand-border bg-brand-green px-3 py-2 text-sm font-medium text-white transition hover:bg-brand-green-deep">
                                    {{ __('Log Out') }}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @endauth
    </aside>

    <!-- Responsive navigation removed; mobile access provided by bottom nav and logout button -->
</nav>

    <script>
        function notificationPanel() {
            return {
                open: false,
                loading: false,
                notifList: [],
                unreadCount: 0,
                poller: null,
                csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                userId: {{ (int) auth()->id() }},
                role: @js(auth()->user()->role),
                routes: {
                    index: '{{ route('notifications.index') }}',
                    unread: '{{ route('notifications.unread_count') }}',
                    readAll: '{{ route('notifications.read_all') }}'
                },
                init() {
                    this.fetchUnreadCount();
                    this.poller = setInterval(() => this.fetchUnreadCount(), 30000);
                },
                togglePanel() {
                    this.open = !this.open;
                    if (this.open && this.notifList.length === 0) {
                        this.fetchNotifications();
                    }
                },
                fetchUnreadCount() {
                    $.ajax({
                        url: this.routes.unread,
                        method: 'GET',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        success: (data) => {
                            this.unreadCount = data?.data?.unread_count ?? 0;
                        }
                    });
                },
                fetchNotifications() {
                    this.loading = true;
                    $.ajax({
                        url: this.routes.index,
                        method: 'GET',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        success: (data) => {
                            this.notifList = data?.data ?? [];
                        },
                        complete: () => {
                            this.loading = false;
                        }
                    });
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
                            this.unreadCount = Math.max(0, this.unreadCount - 1);
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
                            this.notifList.forEach(n => {
                                if (!n.read_at) {
                                    n.read_at = new Date().toISOString();
                                }
                            });
                            this.unreadCount = 0;
                        }
                    });
                },
                navigate(n) {
                    const d = n.data || {};
                    const id = this.userId;
                    let url = '{{ route('dashboard') }}';

                    switch (n.type) {
                        case 'new_message':
                        case 'new_attachment':
                            if (d.session_id) {
                                url = '{{ url('consultation-sessions') }}' + '/' + d.session_id + '/messaging';
                            }
                            break;
                        case 'consultation_scheduled':
                        case 'consultation_rescheduled':
                        case 'consultation_started':
                        case 'consultation_completed':
                        case 'consultation_missed':
                            if (this.role === 'patient' && d.request_id) {
                                url = '{{ url('consultations') }}' + '/' + d.request_id;
                            } else if (this.role === 'physician') {
                                url = '{{ url('physicians') }}' + '/' + id + '/consultation-inbox';
                            }
                            break;
                        case 'consultation_submitted':
                            url = '{{ url('nurses') }}' + '/' + id + '/consultation-inbox';
                            break;
                        case 'consultation_assigned':
                        case 'high_priority_consultation':
                            url = '{{ url('physicians') }}' + '/' + id + '/consultation-inbox';
                            break;
                        case 'follow_up_submitted':
                            if (this.role === 'nurse') {
                                url = '{{ url('nurses') }}' + '/' + id + '/follow-up-requests';
                            } else if (this.role === 'physician') {
                                url = '{{ url('physicians') }}' + '/' + id + '/follow-up-requests';
                            }
                            break;
                        case 'follow_up_approved':
                        case 'follow_up_rejected':
                        case 'follow_up_scheduled':
                        case 'follow_up_starting_soon':
                        case 'physician_request':
                            if (this.role === 'patient') {
                                url = '{{ route('patient.follow_up_list') }}';
                            }
                            break;
                    }

                    window.location.href = url;
                },
                formatTime(iso) {
                    if (!iso) return '';
                    const date = new Date(iso);
                    if (Number.isNaN(date.getTime())) return '';
                    const diff = (Date.now() - date.getTime()) / 1000;
                    if (diff < 60) return 'Just now';
                    if (diff < 3600) return Math.floor(diff / 60) + ' minutes ago';
                    if (diff < 86400) return Math.floor(diff / 3600) + ' hours ago';
                    if (diff < 604800) return Math.floor(diff / 86400) + ' days ago';
                    return date.toLocaleDateString();
                }
            };
        }

        function dashboardNotifications() {
            return {
                loading: false,
                notifList: [],
                unreadCount: 0,
                poller: null,
                csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                userId: {{ (int) auth()->id() }},
                role: @js(auth()->user()->role),
                routes: {
                    index: '{{ route('notifications.index') }}',
                    unread: '{{ route('notifications.unread_count') }}',
                    readAll: '{{ route('notifications.read_all') }}'
                },
                init() {
                    this.fetchUnreadCount();
                    this.fetchNotifications();
                    this.poller = setInterval(() => {
                        this.fetchUnreadCount();
                        this.fetchNotifications();
                    }, 30000);
                },
                fetchUnreadCount() {
                    $.ajax({
                        url: this.routes.unread,
                        method: 'GET',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        success: (data) => {
                            this.unreadCount = data?.data?.unread_count ?? 0;
                        }
                    });
                },
                fetchNotifications() {
                    this.loading = true;
                    $.ajax({
                        url: this.routes.index,
                        method: 'GET',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        success: (data) => {
                            this.notifList = data?.data ?? [];
                        },
                        complete: () => {
                            this.loading = false;
                        }
                    });
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
                            this.unreadCount = Math.max(0, this.unreadCount - 1);
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
                            this.notifList.forEach(n => {
                                if (!n.read_at) {
                                    n.read_at = new Date().toISOString();
                                }
                            });
                            this.unreadCount = 0;
                        }
                    });
                },
                navigate(n) {
                    const d = n.data || {};
                    const id = this.userId;
                    let url = '{{ route('dashboard') }}';

                    switch (n.type) {
                        case 'new_message':
                        case 'new_attachment':
                            if (d.session_id) {
                                url = '{{ url('consultation-sessions') }}' + '/' + d.session_id + '/messaging';
                            }
                            break;
                        case 'consultation_scheduled':
                        case 'consultation_rescheduled':
                        case 'consultation_started':
                        case 'consultation_completed':
                        case 'consultation_missed':
                            if (this.role === 'patient' && d.request_id) {
                                url = '{{ url('consultations') }}' + '/' + d.request_id;
                            } else if (this.role === 'physician') {
                                url = '{{ url('physicians') }}' + '/' + id + '/consultation-inbox';
                            }
                            break;
                        case 'consultation_submitted':
                            url = '{{ url('nurses') }}' + '/' + id + '/consultation-inbox';
                            break;
                        case 'consultation_assigned':
                        case 'high_priority_consultation':
                            url = '{{ url('physicians') }}' + '/' + id + '/consultation-inbox';
                            break;
                        case 'follow_up_submitted':
                            if (this.role === 'nurse') {
                                url = '{{ url('nurses') }}' + '/' + id + '/follow-up-requests';
                            } else if (this.role === 'physician') {
                                url = '{{ url('physicians') }}' + '/' + id + '/follow-up-requests';
                            }
                            break;
                        case 'follow_up_approved':
                        case 'follow_up_rejected':
                        case 'follow_up_scheduled':
                        case 'follow_up_starting_soon':
                        case 'physician_request':
                            if (this.role === 'patient') {
                                url = '{{ route('patient.follow_up_list') }}';
                            }
                            break;
                    }

                    window.location.href = url;
                },
                formatTime(iso) {
                    if (!iso) return '';
                    const date = new Date(iso);
                    if (Number.isNaN(date.getTime())) return '';
                    const diff = (Date.now() - date.getTime()) / 1000;
                    if (diff < 60) return 'Just now';
                    if (diff < 3600) return Math.floor(diff / 60) + ' minutes ago';
                    if (diff < 86400) return Math.floor(diff / 3600) + ' hours ago';
                    if (diff < 604800) return Math.floor(diff / 86400) + ' days ago';
                    return date.toLocaleDateString();
                }
            };
        }

        window.notificationPanel = notificationPanel;
        window.dashboardNotifications = dashboardNotifications;
    </script>

    <!-- Mobile bottom navigation (visible only on small screens) -->
    <nav class="fixed bottom-0 left-0 right-0 bg-white border-t border-gray-200 sm:hidden z-40">
        <div class="max-w-7xl mx-auto px-4 py-2 flex justify-between items-center">
            @if(Auth::check() && Auth::user()->role === 'nurse')
                <a href="{{ route('nurse.dashboard', ['nurse' => Auth::user()]) }}" class="flex-1 text-center py-2 {{ request()->routeIs('nurse.dashboard') ? 'text-white bg-clsu-green rounded-md' : 'text-gray-600' }} mx-1">
                    <div class="flex flex-col items-center text-sm">
                        <svg class="h-6 w-6 mb-1 stroke-current" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v4a1 1 0 001 1h3m10 0h3a1 1 0 001-1V7M16 3v4M8 3v4"/></svg>
                        <span>Dashboard</span>
                    </div>
                </a>
                <a href="{{ route('nurse.consultation_inbox', ['nurse' => Auth::user()]) }}" class="flex-1 text-center py-2 {{ request()->routeIs('nurse.consultation_inbox') ? 'text-white bg-clsu-green rounded-md' : 'text-gray-600' }} mx-1">
                    <div class="flex flex-col items-center text-sm">
                        <svg class="h-6 w-6 mb-1 stroke-current" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                        <span>Inbox</span>
                    </div>
                </a>
                <a href="{{ route('nurse.consultation_history', ['nurse' => Auth::user()]) }}" class="flex-1 text-center py-2 {{ request()->routeIs('nurse.consultation_history') ? 'text-white bg-clsu-green rounded-md' : 'text-gray-600' }} mx-1">
                    <div class="flex flex-col items-center text-sm">
                        <svg class="h-6 w-6 mb-1 stroke-current" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3M3 11h18M5 21h14a2 2 0 002-2V7H3v12a2 2 0 002 2z"/></svg>
                        <span>History</span>
                    </div>
                </a>
            @else
                <a href="{{ route('dashboard') }}" class="flex-1 text-center py-2 {{ request()->routeIs('dashboard') ? 'text-white bg-clsu-green rounded-md' : 'text-gray-600' }} mx-1">
                    <div class="flex flex-col items-center text-sm">
                        <svg class="h-6 w-6 mb-1 stroke-current" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h4v11H3zM17 10h4v11h-4zM7 3h10v18H7z"/></svg>
                        <span>Dashboard</span>
                    </div>
                </a>
                <a href="{{ route('newconsultation') }}" class="flex-1 text-center py-2 {{ request()->routeIs('newconsultation') ? 'text-white bg-clsu-green rounded-md' : 'text-gray-600' }} mx-1">
                    <div class="flex flex-col items-center text-sm">
                        <svg class="h-6 w-6 mb-1 stroke-current" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        <span>New</span>
                    </div>
                </a>
                <a href="{{ route('consultations.history') }}" class="flex-1 text-center py-2 {{ request()->routeIs('consultations.history') ? 'text-white bg-clsu-green rounded-md' : 'text-gray-600' }} mx-1">
                    <div class="flex flex-col items-center text-sm">
                        <svg class="h-6 w-6 mb-1 stroke-current" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v4m8-4v4M3 11h18M5 21h14a2 2 0 002-2V7H3v12a2 2 0 002 2z"/></svg>
                        <span>History</span>
                    </div>
                </a>
            @endif

            <!-- Profile tab (mobile) -->
            <a href="{{ route('profile.edit') }}" class="flex-1 text-center py-2 {{ request()->routeIs('profile.edit') ? 'text-white bg-clsu-green rounded-md' : 'text-gray-600' }} mx-1">
                <div class="flex flex-col items-center text-sm">
                    <svg class="h-6 w-6 mb-1 stroke-current" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A9 9 0 1118.879 6.196 9 9 0 015.12 17.804zM15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <span>Profile</span>
                </div>
            </a>
        </div>
    </nav>
