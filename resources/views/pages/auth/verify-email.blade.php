@extends('layouts.auth', ['title' => 'Verify Email'])

@section('content')
<div class="auth-card mx-auto" style="max-width: 420px;">
    <div class="card shadow-sm">
        <div class="card-body p-4 p-md-5 text-center">
            <div class="auth-icon mb-3">
                <i class="fas fa-envelope-open-text text-primary" style="font-size: 2rem;"></i>
            </div>
            <h1 class="page-title mb-1">Verify Your Email</h1>
            <p class="text-muted small mb-4">We've sent a verification link to your email address</p>

            @if (session('success'))
                <div class="alert bg-success-subtle border-0 rounded mb-3" role="alert">
                    <div class="d-flex align-items-center gap-2 justify-content-center">
                        <i class="fas fa-circle-check text-success"></i>
                        <span class="text-success flex-grow-1">{{ session('success') }}</span>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                </div>
            @endif

            @if (session('error'))
                <div class="alert bg-danger-subtle border-0 rounded mb-3" role="alert">
                    <div class="d-flex align-items-center gap-2 justify-content-center">
                        <i class="fas fa-circle-exclamation text-danger"></i>
                        <span class="text-danger flex-grow-1">{{ session('error') }}</span>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                </div>
            @endif

            @php($mode = config('auth.verification.mode', 'public'))

            @if ($mode === 'admin')
                <div class="alert bg-info-subtle border-0 rounded mb-3" role="alert">
                    <div class="d-flex align-items-center gap-2 justify-content-center">
                        <i class="fas fa-circle-info text-info"></i>
                        <span class="text-info flex-grow-1">Contact your admin to resend the verification email.</span>
                    </div>
                </div>
            @else
                <form action="{{ route('verification.resend') }}" method="POST" class="mb-3">
                    @csrf
                    <div class="mb-3 text-start">
                        <label for="email" class="form-label small">Email address</label>
                        <input type="email" name="email" id="email" class="form form-control"
                               value="{{ old('email') }}" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100" id="resendBtn">
                        Resend Verification Email
                    </button>
                </form>
            @endif

            <a href="{{ route('login') }}" class="text-decoration-none small">Back to Login</a>
        </div>
    </div>
</div>
@endsection
