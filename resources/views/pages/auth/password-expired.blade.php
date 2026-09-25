@extends('layouts.auth', ['title' => 'Password Expired'])

@section('content')
<div class="auth-card mx-auto" style="max-width: 420px;">
    <div class="card">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <div class="auth-icon mb-3">
                    <i class="fas fa-shield-halved text-warning"></i>
                </div>
                <h1 class="page-title mb-1">Password Expired</h1>
                <p class="text-muted small mb-0">Your password has expired. Please set a new password to continue.</p>
            </div>

            @if (session('status'))
                <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                    <i class="fas fa-circle-check me-1"></i>
                    {{ session('status') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                    <i class="fas fa-circle-exclamation me-1"></i>
                    Please fix the errors below.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            <form method="POST" action="{{ route('password.change.update') }}">
                @csrf @method('PUT')
                <div class="mb-3">
                    <label for="current_password" class="form-label">Current Password</label>
                    <div class="position-relative">
                        <input type="password" name="current_password" id="current_password"
                               class="form-control pe-5 @error('current_password') is-invalid @enderror"
                               autocomplete="current-password">
                        <button type="button"
                            class="btn btn-link text-muted text-decoration-none position-absolute top-50 translate-middle-y toggle-password p-0 border-0"
                            data-password-toggle="current_password" aria-label="Toggle password visibility"
                            tabindex="-1" style="right: 2rem; z-index: 5;">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    @error('current_password')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">New Password</label>
                    <div class="position-relative">
                        <input type="password" name="password" id="password"
                               class="form-control pe-5 @error('password') is-invalid @enderror"
                               autocomplete="new-password">
                        <button type="button"
                            class="btn btn-link text-muted text-decoration-none position-absolute top-50 translate-middle-y toggle-password p-0 border-0"
                            data-password-toggle="password" aria-label="Toggle password visibility"
                            tabindex="-1" style="right: 2rem; z-index: 5;">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <small class="text-muted d-block mt-1">Your new password cannot be one of your recent passwords.</small>
                    @include('layouts.partials.password-strength')
                    @error('password')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
                <div class="mb-4">
                    <label for="password_confirmation" class="form-label">Confirm New Password</label>
                    <div class="position-relative">
                        <input type="password" name="password_confirmation" id="password_confirmation"
                               class="form-control pe-5 @error('password_confirmation') is-invalid @enderror"
                               autocomplete="new-password">
                        <button type="button"
                            class="btn btn-link text-muted text-decoration-none position-absolute top-50 translate-middle-y toggle-password p-0 border-0"
                            data-password-toggle="password_confirmation" aria-label="Toggle password visibility"
                            tabindex="-1" style="right: 2rem; z-index: 5;">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    @error('password_confirmation')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
                <div class="d-grid mb-3">
                    <button type="submit" class="btn btn-primary">Update Password</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
