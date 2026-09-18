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

            <form id="resendForm" action="#" onsubmit="return false;" class="mb-3">
                @csrf
                <button type="submit" class="btn btn-outline-primary">Resend Verification Email</button>
            </form>

            <div class="text-center">
                <a href="{{ url('/login') }}" class="text-decoration-none small">Back to Login</a>
            </div>
        </div>
    </div>
</div>
@endsection