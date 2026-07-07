<nav class="bg-white border-b border-gray-200 mb-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-center gap-2 py-3">
            <a href="{{ route('nurse.dashboard', ['nurse' => $nurse]) }}" class="px-4 py-2 rounded-md text-sm font-medium {{ request()->routeIs('nurse.dashboard') ? 'bg-indigo-600 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                {{ __('Dashboard') }}
            </a>
            <a href="{{ route('nurse.consultation_inbox', ['nurse' => $nurse]) }}" class="px-4 py-2 rounded-md text-sm font-medium {{ request()->routeIs('nurse.consultation_inbox') ? 'bg-indigo-600 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                {{ __('Consultation Inbox') }}
            </a>
            <a href="{{ route('nurse.follow_up_requests', ['nurse' => $nurse]) }}" class="px-4 py-2 rounded-md text-sm font-medium {{ request()->routeIs('nurse.follow_up_requests') ? 'bg-indigo-600 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                {{ __('Follow-up Requests') }}
            </a>
            <a href="{{ route('nurse.consultation_history', ['nurse' => $nurse]) }}" class="px-4 py-2 rounded-md text-sm font-medium {{ request()->routeIs('nurse.consultation_history') ? 'bg-indigo-600 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                {{ __('Consultation History') }}
            </a>
        </div>
    </div>
</nav>
