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

            <!-- Mobile Logout (replaces hamburger) -->
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
