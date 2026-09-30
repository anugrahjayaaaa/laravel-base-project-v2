<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name', 'Laravel') }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/adminlte/css/adminlte.min.css') }}">
    {{-- Self-hosted, like bootstrap and adminlte above. Off a CDN this cost 730 ms
         of blocking CSS before first paint on a warm connection, with ten remote
         font files behind it. --}}
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome/fontawesome.min.css') }}">
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
<body>
<div class="app-wrapper">
    @yield('content')
</div>
<script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('vendor/adminlte/js/adminlte.min.js') }}"></script>
@vite('resources/js/app.js')
@stack('scripts')
</body>
</html>