@extends('layouts.auth', ['title' => 'Forgot Password'])

@section('content')
<div class="auth-card mx-auto" style="max-width: 420px;">
    <div class="card">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <div class="auth-icon mb-3">
                    <i class="fas fa-key text-primary"></i>
                </div>
                <h1 class="page-title mb-1">Reset Password</h1>
                <p class="text-muted small mb-0">Enter your email to receive a reset link</p>
            </div>

            @if (session('success'))
                <div class="alert bg-success-subtle mb-3" role="alert">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-circle-check text-success"></i>
                        <span class="text-success flex-grow-1">{{ session('success') }}</span>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                </div>
            @endif

            @if (session('rate_limit_seconds'))
                <div class="alert bg-danger-subtle mb-3" role="alert">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-circle-exclamation text-danger"></i>
                        <span class="text-danger flex-grow-1">
                            Too many attempts. Please try again in <span class="rate-limit-seconds" data-seconds="{{ session('rate_limit_seconds') }}">{{ session('rate_limit_seconds') }}</span> seconds.
                        </span>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                </div>
            @endif

            <form id="forgotForm" action="{{ route('password.email') }}" method="POST">
                @csrf
                <div class="mb-4">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email"
                           value="{{ old('email', request('email')) }}" required>
                    <div class="invalid-feedback" id="emailError"></div>
                    @error('email')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
                <div class="d-grid mb-3">
                    <button type="submit" class="btn btn-primary" id="forgotBtn"
                                 onclick="this.disabled=true;this.form.submit()">Send Reset Link</button>
                </div>
                <div class="text-center">
                    <a href="{{ url('/login') }}" class="text-decoration-none small">Back to Login</a>
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