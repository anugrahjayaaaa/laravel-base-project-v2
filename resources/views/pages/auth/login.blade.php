@extends('layouts.auth', ['title' => 'Login'])

@section('content')
<div class="auth-card mx-auto" style="max-width: 420px;">
    <div class="card shadow-sm">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <div class="auth-icon mb-3">
                    <i class="fas fa-lock text-primary"></i>
                </div>
                <h1 class="page-title mb-1">{{ config('app.name', 'Laravel') }}</h1>
                <p class="text-muted small mb-0">Sign in to your account</p>
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

            <form id="loginForm" action="{{ url('/login') }}" method="POST">
                @csrf
                <div class="mb-3">
                    <label for="identifier" class="form-label">Email / Username</label>
                    <input type="text" class="form-control @error('identifier') is-invalid @enderror"
                           id="identifier" name="identifier" value="{{ old('identifier') }}"
                           required autocomplete="username" autofocus>
                    @error('identifier')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <div class="position-relative">
                        <input type="password" class="form-control pe-5" id="password" name="password"
                               required autocomplete="current-password">
                        <button type="button" class="btn btn-sm btn-sm position-absolute top-50 end-0 translate-middle-y me-2 text-muted"
                                onclick="var e=document.getElementById('password');e.type=e.type==='password'?'text':'password';this.querySelector('i').classList.toggle('fa-eye');this.querySelector('i').classList.toggle('fa-eye-slash')"
                                aria-label="Toggle password visibility" tabindex="-1">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    @error('password')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
                <div class="d-grid mb-3">
                    <button type="submit" class="btn btn-primary" id="loginBtn">Login</button>
                </div>
                <div class="text-center">
                    <a href="{{ url('/forgot-password') }}" class="text-decoration-none small">Forgot password?</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection