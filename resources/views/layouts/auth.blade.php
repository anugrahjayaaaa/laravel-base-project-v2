<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name', 'Laravel') }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/adminlte/css/adminlte.min.css') }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="{{ asset('vendor/theme.css') }}">
    <style>
        :root {
            --lbp-primary: #6366f1;
            --lbp-radius: 12px;
            --lbp-radius-sm: 8px;
            --lbp-font: 'Instrument Sans', system-ui, -apple-system, sans-serif;
        }
        body.auth-page {
            font-family: var(--lbp-font);
            font-size: 14px;
            line-height: 1.55;
            background: var(--lbp-surface-alt, #f5f6f8);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .auth-card .card {
            border-radius: var(--lbp-radius);
            border: 1px solid rgba(0,0,0,0.08);
        }
        .auth-card .auth-icon {
            width: 48px;
            height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: var(--lbp-primary, #6366f1);
            opacity: 0.1;
        }
        .auth-card .auth-icon i {
            font-size: 1.25rem;
        }
        .auth-card .btn {
            border-radius: var(--lbp-radius-sm);
            font-weight: 500;
        }
        .auth-card .alert {
            border: none;
        }
        [data-bs-theme="dark"] body.auth-page {
            background: var(--lbp-surface, #0f1115);
        }
        [data-bs-theme="dark"] .auth-card .card {
            border-color: rgba(255,255,255,0.08);
        }
        [data-bs-theme="dark"] .auth-card .auth-icon {
            background: var(--lbp-primary, #818cf8);
        }
        .auth-theme-toggle {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 1050;
        }
    </style>
    @stack('styles')
</head>
<body class="auth-page">
    @include('layouts.partials.scripts.theme-toggle', ['class' => 'nav-link text-secondary auth-theme-toggle'])
    <div class="auth-wrapper w-100 px-3">
        @yield('content')
    </div>
    <script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    @vite('resources/js/app.js')
    @include('layouts.partials.scripts.password-toggle')
    @stack('scripts')
</body>
</html>