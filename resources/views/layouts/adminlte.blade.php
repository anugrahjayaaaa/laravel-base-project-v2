<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title ?? config('app.name', 'Laravel') }}</title>

        {{-- AdminLTE vendored CSS (not via npm/Vite) --}}
        <link rel="stylesheet" href="{{ asset('vendor/adminlte/css/adminlte.min.css') }}">

        {{-- Application's own asset pipeline (Vite) for app-specific CSS --}}
        @vite(['resources/css/app.css'])

        @stack('styles')
    </head>

    <body class="layout-navbar-fixed {{ $bodyClass ?? '' }}">

        @include('layouts.partials.adminlte.navbar')

        @yield('content')

        {{-- AdminLTE vendored JS. AdminLTE 4 depends on Bootstrap 5.3 bundle;
             that dependency is provided by the app-vite pipeline, not by AdminLTE --}}
        <script src="{{ asset('vendor/adminlte/js/adminlte.min.js') }}"></script>

        {{-- Application's own JS entry --}}
        @vite(['resources/js/app.js'])

        @stack('scripts')
    </body>
</html>
