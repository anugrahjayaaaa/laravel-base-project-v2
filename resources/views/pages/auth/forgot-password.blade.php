@extends('layouts.auth', ['title' => 'Forgot Password'])

@section('content')
<div class="auth-card mx-auto" style="max-width: 420px;">
    <div class="card shadow-sm">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <div class="auth-icon mb-3">
                    <i class="fas fa-key text-primary"></i>
                </div>
                <h1 class="page-title mb-1">Reset Password</h1>
                <p class="text-muted small mb-0">Enter your email to receive a reset link</p>
            </div>

            @if (session('success'))
                <div class="alert bg-success-subtle border-0 rounded mb-3" role="alert">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-circle-check text-success"></i>
                        <span class="text-success flex-grow-1">{{ session('success') }}</span>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                </div>
            @endif

            <form id="forgotForm" action="{{ route('password.email') }}" method="POST">
                @csrf
                <div class="mb-4">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email" required>
                    <div class="invalid-feedback" id="emailError"></div>
                </div>
                <div class="d-grid mb-3">
                    <button type="submit" class="btn btn-primary" id="forgotBtn">Send Reset Link</button>
                </div>
                <div class="text-center">
                    <a href="{{ url('/login') }}" class="text-decoration-none small">Back to Login</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection