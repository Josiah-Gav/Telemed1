<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Sweet Alert -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

        <!-- jQuery -->
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-[#f4f8f3] text-slate-800">
        <div class="min-h-dvh bg-[radial-gradient(circle_at_top,_rgba(217,182,72,0.12),transparent_30%),linear-gradient(180deg,_#eff8f1_0%,_#f5f7f3_100%)]">
            <div x-data="{ sidebarOpen: true }" class="flex min-h-dvh flex-col lg:flex-row">
                @include('layouts.navigation')

                <div class="min-w-0 flex-1 transition-all duration-300">
                    <!-- Page Heading -->
                    @isset($header)
                        <header class="border-b border-brand-border bg-gradient-to-r from-brand-green to-brand-green-deep text-white shadow-sm">
                            <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8 flex items-center justify-between gap-4">
                                <div class="flex-1 min-w-0">
                                    {{ $header }}
                                </div>
                                @auth
                                    <div class="flex-shrink-0 flex items-center gap-1">
                                        @include('layouts.notificationUI')

                                        <form method="POST" action="{{ route('logout') }}" class="md:hidden">
                                            @csrf
                                            <button
                                                type="submit"
                                                class="inline-flex items-center justify-center rounded-full bg-red-600 p-2 text-white/90 transition hover:bg-red-700 hover:text-white"
                                                aria-label="{{ __('Log Out') }}"
                                            >
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l3 3m0 0-3 3m3-3H2.25" />
                                                </svg>
                                            </button>
                                        </form>
                                    </div>
                                @endauth
                            </div>
                        </header>
                    @endisset

                    <!-- Page Content -->
                    <main class="pb-24 sm:pb-0">
                        {{ $slot }}
                    </main>
                </div>
            </div>
        </div>

        @auth
        <script>
            (function () {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

                if (!csrfToken) {
                    return;
                }

                function sendHeartbeat() {
                    fetch('{{ route('presence.heartbeat') }}', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        }
                    }).catch(() => {});
                }

                // Send an initial heartbeat on page load
                sendHeartbeat();

                // Send a heartbeat every 60 seconds to keep presence fresh
                window.setInterval(sendHeartbeat, 60000);
            })();
        </script>
        @endauth
    </body>
</html>
