<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name', 'Laravel Base Project') }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/adminlte/css/adminlte.min.css') }}">
    {{-- Self-hosted, like bootstrap and adminlte above. Off a CDN these two cost
         730 ms of blocking CSS before first paint on a warm connection, and ten
         remote font files behind them (4.4 s if fetched serially) — so a slow CDN
         held the whole page back, not just the icons. No integrity attribute: it
         only applies to subresources fetched from a third party. --}}
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome/fontawesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    {{-- mtime appended: this file is a project asset under public/, not a Vite
         input, so it gets no content hash. Without a changing URL the browser
         is free to keep the copy it has, and every theme fix would need a
         manual hard refresh to see. --}}
    <link rel="stylesheet" href="{{ asset('vendor/theme.css') }}?v={{ filemtime(public_path('vendor/theme.css')) }}">
    <script>
        (function() {
            var saved = localStorage.getItem('theme');
            var systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            var theme = saved || (systemDark ? 'dark' : 'light');
            document.documentElement.setAttribute('data-bs-theme', theme);
        })();
    </script>
    @stack('styles')
</head>
<body class="layout-fixed sidebar-mini">
<div class="app-wrapper">
    @include('layouts.partials.header')
    @include('layouts.partials.sidebar')

    <main class="app-main">
        <div class="app-content py-3">
            <div class="container-fluid">
                @include('partials.password-expiry-warning')
                @yield('content')
            </div>
        </div>
    </main>

    @include('layouts.partials.footer')
</div>

@include('layouts.partials.modals.confirmation')

<script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('vendor/adminlte/js/adminlte.min.js') }}"></script>
@vite('resources/js/app.js')
@include('layouts.partials.scripts.password-toggle')
@stack('scripts')
</body>
</html>
