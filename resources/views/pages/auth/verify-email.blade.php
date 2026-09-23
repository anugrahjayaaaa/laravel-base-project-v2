@extends('layouts.auth', ['title' => 'Verify Email'])

@section('content')
<div class="auth-card mx-auto" style="max-width: 420px;">
    <div class="card">
        <div class="card-body p-4 p-md-5 text-center">
            <div class="auth-icon mb-3">
                <i class="fas fa-envelope-open-text text-primary" style="font-size: 2rem;"></i>
            </div>
            <h1 class="page-title mb-1">Verify Your Email</h1>
            <p class="text-muted small mb-4">We've sent a verification link to your email address</p>

            @if (session('success'))
                <div class="alert alert-success alert-dismissible fade show mb-0 text-center" role="alert">
                    <i class="fas fa-circle-check me-1"></i>
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @if (session('error'))
                <div class="alert alert-danger alert-dismissible fade show mb-0 text-center" role="alert">
                    <i class="fas fa-circle-exclamation me-1"></i>
                    {{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @php($mode = config('auth.verification.mode', 'public'))

            @if ($mode === 'admin')
                <div class="alert bg-info-subtle mb-3" role="alert">
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
                    @error('email')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                    <button type="submit" class="btn btn-primary w-100" id="resendBtn"
                            onclick="this.disabled=true;this.form.submit()">
                        Resend Verification Email
                    </button>
                </form>
            @endif

            <a href="{{ route('login') }}" class="text-decoration-none small">Back to Login</a>
        </div>
    </div>
</div>
@endsection
