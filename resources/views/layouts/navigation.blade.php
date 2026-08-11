<nav x-data="{ open: false }" class="bg-white border-b border-gray-100">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ Auth::check() && Auth::user()->role === 'nurse' ? route('nurse.dashboard', ['nurse' => Auth::user()]) : route('dashboard') }}">
                        <x-application-logo class="block h-9 w-auto fill-current text-gray-800" />
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
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
            <!-- Notification Bell + Settings (desktop) -->
            <div class="hidden sm:flex sm:items-center gap-1">
            <!-- Notification Bell -->
            <div class="relative" x-data="notificationPanel()">
                <button type="button" @click="togglePanel()" class="relative inline-flex items-center justify-center p-2 rounded-full text-gray-500 hover:text-gray-700 hover:bg-gray-100 focus:outline-none transition" aria-label="Notifications">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                    <template x-if="unreadCount > 0">
                        <span class="absolute -top-0.5 -right-0.5 inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1 rounded-full bg-red-500 text-white text-xs font-bold" x-text="unreadCount > 99 ? '99+' : unreadCount"></span>
                    </template>
                </button>

                <!-- Notification dropdown panel -->
                <div x-show="open" x-cloak @click.outside="open = false"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-95"
                    x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="transition ease-in duration-75"
                    x-transition:leave-start="opacity-100 scale-100"
                    x-transition:leave-end="opacity-0 scale-95"
                    class="absolute right-0 z-50 mt-2 w-96 max-w-[calc(100vw-2rem)] rounded-xl bg-white shadow-lg ring-1 ring-black ring-opacity-5 overflow-hidden">

                    <div class="flex items-center justify-between px-4 py-3 border-b border-slate-200 bg-slate-50">
                        <h3 class="text-sm font-bold text-slate-800">Notifications</h3>
                        <button type="button" @click="markAllRead()" class="text-xs font-semibold text-indigo-600 hover:text-indigo-700">Mark all as read</button>
                    </div>

                    <div class="max-h-96 overflow-y-auto">
                        <template x-if="loading">
                            <div class="px-4 py-8 text-center text-sm text-slate-500">Loading notifications...</div>
                        </template>

                        <template x-if="!loading && notifList.length === 0">
                            <div class="px-4 py-8 text-center">
                                <p class="text-2xl mb-2">🔔</p>
                                <p class="text-sm text-slate-500">No notifications yet.</p>
                            </div>
                        </template>

                        <template x-for="n in notifList" :key="n.notification_id">
                            <button type="button" @click="markAsRead(n)"
                                class="w-full text-left px-4 py-3 border-b border-slate-100 hover:bg-slate-50 transition flex gap-3"
                                :class="n.read_at ? 'bg-white' : 'bg-indigo-50/50'">
                                <span class="mt-1.5 inline-block h-2 w-2 shrink-0 rounded-full"
                                    :class="n.read_at ? 'bg-slate-300' : 'bg-indigo-500'"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold text-slate-800" x-text="n.title"></span>
                                    <span class="block text-xs text-slate-600 mt-0.5 line-clamp-2" x-text="n.message"></span>
                                    <span class="block text-xs text-slate-400 mt-1" x-text="formatTime(n.created_at)"></span>
                                </span>
                            </button>
                        </template>
                    </div>
                </div>
            </div>
            </div>
            @endauth

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150">
                            <div>{{ Auth::user()->name }}</div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">
                            {{ __('Profile') }}
                        </x-dropdown-link>

                        <!-- Authentication -->
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf

                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault();
                                                this.closest('form').submit();">
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Mobile Notification Bell + Logout -->
            <div class="relative -me-2 flex items-center sm:hidden" x-data="notificationPanel()">
                <button type="button" @click="togglePanel()" class="relative inline-flex items-center justify-center p-2 rounded-md text-gray-600 hover:text-gray-800 hover:bg-gray-100 focus:outline-none transition" aria-label="Notifications">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                    <template x-if="unreadCount > 0">
                        <span class="absolute -top-0.5 -right-0.5 inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1 rounded-full bg-red-500 text-white text-xs font-bold" x-text="unreadCount > 99 ? '99+' : unreadCount"></span>
                    </template>
                </button>

                <div x-show="open" x-cloak @click.outside="open = false"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-95"
                    x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="transition ease-in duration-75"
                    x-transition:leave-start="opacity-100 scale-100"
                    x-transition:leave-end="opacity-0 scale-95"
                    class="absolute right-0 top-12 z-50 w-[calc(100vw-2rem)] max-w-sm rounded-xl bg-white shadow-lg ring-1 ring-black ring-opacity-5 overflow-hidden">

                    <div class="flex items-center justify-between px-4 py-3 border-b border-slate-200 bg-slate-50">
                        <h3 class="text-sm font-bold text-slate-800">Notifications</h3>
                        <button type="button" @click="markAllRead()" class="text-xs font-semibold text-indigo-600 hover:text-indigo-700">Mark all as read</button>
                    </div>

                    <div class="max-h-80 overflow-y-auto">
                        <template x-if="loading">
                            <div class="px-4 py-8 text-center text-sm text-slate-500">Loading notifications...</div>
                        </template>

                        <template x-if="!loading && notifList.length === 0">
                            <div class="px-4 py-8 text-center">
                                <p class="text-2xl mb-2">🔔</p>
                                <p class="text-sm text-slate-500">No notifications yet.</p>
                            </div>
                        </template>

                        <template x-for="n in notifList" :key="n.notification_id">
                            <button type="button" @click="markAsRead(n)"
                                class="w-full text-left px-4 py-3 border-b border-slate-100 hover:bg-slate-50 transition flex gap-3"
                                :class="n.read_at ? 'bg-white' : 'bg-indigo-50/50'">
                                <span class="mt-1.5 inline-block h-2 w-2 shrink-0 rounded-full"
                                    :class="n.read_at ? 'bg-slate-300' : 'bg-indigo-500'"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold text-slate-800" x-text="n.title"></span>
                                    <span class="block text-xs text-slate-600 mt-0.5 line-clamp-2" x-text="n.message"></span>
                                    <span class="block text-xs text-slate-400 mt-1" x-text="formatTime(n.created_at)"></span>
                                </span>
                            </button>
                        </template>
                    </div>
                </div>
            </div>

            <div class="-me-2 flex items-center sm:hidden">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center justify-center p-2 rounded-md text-gray-600 hover:text-gray-800 hover:bg-gray-100 focus:outline-none transition duration-150 ease-in-out">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h6a2 2 0 012 2v1"></path></svg>
                    </button>
                </form>
            </div>
        </div>
    </div>

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

        window.notificationPanel = notificationPanel;
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
