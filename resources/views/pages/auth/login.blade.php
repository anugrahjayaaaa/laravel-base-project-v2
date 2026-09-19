@extends('layouts.auth', ['title' => 'Login'])

@section('content')
<div class="auth-card mx-auto" style="max-width: 420px;">
    <div class="card">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <div class="auth-icon mb-3">
                    <i class="fas fa-lock text-primary"></i>
                </div>
                <h1 class="page-title mb-1">{{ config('app.name', 'Laravel') }}</h1>
                <p class="text-muted small mb-0">Sign in to your account</p>
            </div>

            @if (session('status'))
                <div class="alert alert-success alert-dismissible mb-0" role="alert">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-circle-check"></i>
                        <span class="flex-grow-1">{{ session('status') }}</span>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                </div>
            @endif

            @if (session('rate_limit_seconds'))
                <div class="alert alert-danger alert-dismissible mb-0" role="alert">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-circle-exclamation"></i>
                        <span class="flex-grow-1">
                            Too many attempts. Please try again in <span class="rate-limit-seconds" data-seconds="{{ session('rate_limit_seconds') }}">{{ session('rate_limit_seconds') }}</span> seconds.
                        </span>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                </div>
            @endif

            @if (session('error'))
                <div class="alert alert-danger alert-dismissible mb-0" role="alert">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-circle-exclamation"></i>
                        <span class="flex-grow-1">{{ session('error') }}</span>
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
                        <button type="button" class="btn btn-sm position-absolute top-50 end-0 translate-middle-y me-2 text-muted"
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
                    <button type="submit" class="btn btn-primary" id="loginBtn"
                                 onclick="this.disabled=true;this.form.submit()">Login</button>
                </div>
                <div class="text-center">
                    <a href="{{ url('/forgot-password') }}" class="text-decoration-none small">Forgot password?</a>
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
