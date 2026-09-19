<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name', 'Laravel Base Project') }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/adminlte/css/adminlte.min.css') }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="{{ asset('vendor/theme.css') }}">
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
                @yield('content')
            </div>
        </div>
    </main>

    @include('layouts.partials.footer')
    @include('layouts.partials.modals.confirmation')
</div>

<script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('vendor/adminlte/js/adminlte.min.js') }}"></script>
@vite('resources/js/app.js')
<script src="{{ \Illuminate\Support\Facades\Vite::asset('resources/js/app.js') }}" defer></script>
@stack('scripts')
</body>
</html>
