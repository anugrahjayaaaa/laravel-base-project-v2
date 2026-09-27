@extends('layouts.auth', ['title' => 'Register'])

@section('content')
<div class="auth-card mx-auto" style="max-width: 420px;">
    <div class="card">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <div class="auth-icon mb-3">
                    <i class="fas fa-user-plus text-primary"></i>
                </div>
                <h1 class="page-title mb-1">Create your account</h1>
                <p class="text-muted small mb-0">Sign up to get started</p>
            </div>

            @if (session('rate_limit_seconds'))
                <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                    <i class="fas fa-circle-exclamation me-1"></i>
                    Too many attempts. Please try again in <span class="rate-limit-seconds" data-seconds="{{ session('rate_limit_seconds') }}">{{ session('rate_limit_seconds') }}</span> seconds.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            <form id="registerForm" action="{{ route('register.submit') }}" method="POST">
                @csrf
                <div class="mb-3">
                    <label for="name" class="form-label">Name</label>
                    <input type="text" class="form-control @error('name') is-invalid @enderror"
                           id="name" name="name" value="{{ old('name') }}"
                           required maxlength="255" autocomplete="name" autofocus>
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" class="form-control @error('username') is-invalid @enderror"
                           id="username" name="username" value="{{ old('username') }}"
                           required maxlength="50" autocomplete="username">
                    @error('username')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control @error('email') is-invalid @enderror"
                           id="email" name="email" value="{{ old('email') }}"
                           required maxlength="255" autocomplete="email">
                    @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="position-relative">
                        <input type="password" class="form-control pe-5 @error('password') is-invalid @enderror"
                               id="password" name="password" required autocomplete="new-password">
                        <button type="button"
                            class="btn btn-link text-muted text-decoration-none position-absolute top-50 translate-middle-y toggle-password p-0 border-0"
                            data-password-toggle="password" aria-label="Toggle password visibility"
                            tabindex="-1" style="right: 2.25rem; z-index: 5;">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    @error('password')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                    @include('layouts.partials.password-strength')
                </div>

                <div class="mb-4">
                    <label for="password_confirmation" class="form-label">Confirm Password</label>
                    <div class="position-relative">
                        <input type="password" class="form-control @error('password_confirmation') is-invalid @enderror"
                               id="password_confirmation" name="password_confirmation"
                               required autocomplete="new-password">
                        <button type="button"
                            class="btn btn-link text-muted text-decoration-none position-absolute top-50 translate-middle-y toggle-password p-0 border-0"
                            data-password-toggle="password_confirmation" aria-label="Toggle password visibility"
                            tabindex="-1" style="right: 2.25rem; z-index: 5;">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    @error('password_confirmation')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="d-grid mb-3">
                    <button type="submit" class="btn btn-primary" id="registerBtn"
                            onclick="this.disabled=true;this.form.submit()">Register</button>
                </div>
                <div class="text-center">
                    <a href="{{ route('login') }}" class="text-decoration-none small">Already have an account? Sign in</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
