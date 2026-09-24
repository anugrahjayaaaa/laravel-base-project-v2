@extends('layouts.auth', ['title' => 'Reset Password'])

@section('content')
<div class="auth-card mx-auto" style="max-width: 420px;">
    <div class="card">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <div class="auth-icon mb-3">
                    <i class="fas fa-shield-halved text-primary"></i>
                </div>
                <h1 class="page-title mb-1">Set New Password</h1>
                <p class="text-muted small mb-0">Choose a strong password for your account</p>
            </div>

            @if (session('status'))
                <div class="alert alert-success alert-dismissible fade show mb-0" role="alert">
                    <i class="fas fa-circle-check me-1"></i>
                    {{ session('status') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @if (session('rate_limit_seconds'))
                <div class="alert alert-danger alert-dismissible fade show mb-0" role="alert">
                    <i class="fas fa-circle-exclamation me-1"></i>
                    Too many attempts. Please try again in <span class="rate-limit-seconds" data-seconds="{{ session('rate_limit_seconds') }}">{{ session('rate_limit_seconds') }}</span> seconds.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @if (session('error'))
                <div class="alert alert-danger alert-dismissible fade show mb-0" role="alert">
                    <i class="fas fa-circle-exclamation me-1"></i>
                    {{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
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
                    <div class="position-relative">
                        <input type="password" class="form-control pe-5 @error('password') is-invalid @enderror" id="password" name="password" required>
                        <button type="button"
                            class="btn btn-link text-muted text-decoration-none position-absolute top-50 translate-middle-y toggle-password p-0 border-0"
                            data-password-toggle="password" aria-label="Toggle password visibility"
                            tabindex="-1" style="right: 2.25rem; z-index: 5;">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    @include('layouts.partials.password-strength')
                    @error('password')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
                <div class="mb-4">
                    <label for="password_confirmation" class="form-label">Confirm Password</label>
                    <div class="position-relative">
                        <input type="password" class="form-control pe-5 @error('password_confirmation') is-invalid @enderror" id="password_confirmation" name="password_confirmation" required>
                        <button type="button"
                            class="btn btn-link text-muted text-decoration-none position-absolute top-50 translate-middle-y toggle-password p-0 border-0"
                            data-password-toggle="password_confirmation" aria-label="Toggle password visibility"
                            tabindex="-1" style="right: 2.25rem; z-index: 5;">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    @error('password_confirmation')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
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

@push('scripts')
<script>
(function() {
    document.querySelectorAll('.rate-limit-seconds').forEach(function(el) {
        var seconds = parseInt(el.dataset.seconds);
        var interval = setInterval(function() {
            seconds--;
            if (seconds <= 0) {
                clearInterval(interval);
                var alert = el.closest('.alert');
                if (alert) {
                    alert.querySelector('.text-danger.flex-grow-1').textContent = 'You can try again now.';
                    el.remove();
                }
                var btn = alert ? alert.closest('.card')?.querySelector('button[type=submit]') : null;
                if (btn) btn.disabled = false;
            } else {
                el.textContent = seconds;
            }
        }, 1000);
    });
})();
</script>
@endpush
