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

        <!-- Bootstrap --> 
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" defer></script>
        
        <!-- Sweet Alert -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

        <!-- jQuery -->
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-[#f4f8f3] text-slate-800">
        <div class="min-h-screen bg-[radial-gradient(circle_at_top,_rgba(217,182,72,0.12),transparent_30%),linear-gradient(180deg,_#eff8f1_0%,_#f5f7f3_100%)]">
            <div x-data="{ sidebarOpen: true }" class="flex min-h-screen flex-col lg:flex-row">
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
                                    <div class="flex-shrink-0">
                                        @include('layouts.notificationUI')
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
