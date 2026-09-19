@extends('layouts.auth', ['title' => 'Reset Password'])

@section('content')
<div class="auth-card mx-auto" style="max-width: 420px;">
    <div class="card shadow-sm">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <div class="auth-icon mb-3">
                    <i class="fas fa-shield-halved text-primary"></i>
                </div>
                <h1 class="page-title mb-1">Set New Password</h1>
                <p class="text-muted small mb-0">Choose a strong password for your account</p>
            </div>

            @if (session('error'))
                <div class="alert bg-danger-subtle border-0 rounded mb-3" role="alert">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-circle-exclamation text-danger"></i>
                        <span class="text-danger flex-grow-1">{{ session('error') }}</span>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                </div>
            @endif

            <form id="resetForm" action="{{ route('password.update') }}" method="POST">
                @csrf
                <input type="hidden" id="token" name="token" value="{{ request('token') }}">
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email"
                           value="{{ old('email', request('email')) }}" required>
                    <div class="invalid-feedback" id="emailError"></div>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">New Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                    <div class="invalid-feedback" id="passwordError"></div>
                    @error('password')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
                <div class="mb-4">
                    <label for="password_confirmation" class="form-label">Confirm Password</label>
                    <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required>
                </div>
                <div class="d-grid mb-3">
                    <button type="submit" class="btn btn-primary" id="resetBtn"
                                 onclick="this.disabled=true;this.form.submit()">Reset Password</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
