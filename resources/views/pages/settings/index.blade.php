@extends('layouts.app', ['title' => 'System Settings'])

@section('content')
    <div class="content-header mb-3">
        <div class="d-flex justify-content-between align-items-start w-100">
            <div>
                <h1 class="page-title fw-bold">System Settings</h1>
                <p class="page-description text-muted fs-7 mb-0">
                    <a href="{{ route('dashboard') }}" class="text-muted text-decoration-none"><i
                                class="fas fa-arrow-left me-1"></i>Back to Dashboard</a>
                </p>
            </div>
            <ol class="breadcrumb float-sm-end mb-0">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active">System Settings</li>
            </ol>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
            <i class="fas fa-circle-check me-1"></i>
            {{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <form method="POST" action="{{ route('settings.update') }}">
        @csrf

        {{-- 1. Login rate limiting & progressive lockout --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent border-bottom py-3">
                <h5 class="card-title mb-0 fw-semibold">Login Rate Limiting & Progressive Lockout</h5>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="auth_login_max_attempts" class="form-label">Max login attempts</label>
                        <input type="number" name="auth_login_max_attempts" id="auth_login_max_attempts"
                               class="form-control form-control-sm @error('auth_login_max_attempts') is-invalid @enderror"
                               value="{{ old('auth_login_max_attempts', $settings['auth_login_max_attempts'] ?? 5) }}" min="1" max="99">
                        @error('auth_login_max_attempts')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-3">
                        <label for="auth_lockout_base_minutes" class="form-label">Lockout base (min)</label>
                        <input type="number" name="auth_lockout_base_minutes" id="auth_lockout_base_minutes"
                               class="form-control form-control-sm @error('auth_lockout_base_minutes') is-invalid @enderror"
                               value="{{ old('auth_lockout_base_minutes', $settings['auth_lockout_base_minutes'] ?? 5) }}" min="1" max="60">
                        @error('auth_lockout_base_minutes')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-3">
                        <label for="auth_lockout_increment_minutes" class="form-label">Lockout increment (min)</label>
                        <input type="number" name="auth_lockout_increment_minutes" id="auth_lockout_increment_minutes"
                               class="form-control form-control-sm @error('auth_lockout_increment_minutes') is-invalid @enderror"
                               value="{{ old('auth_lockout_increment_minutes', $settings['auth_lockout_increment_minutes'] ?? 10) }}" min="1" max="120">
                        @error('auth_lockout_increment_minutes')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-3">
                        <label for="auth_login_rate_limit_per_minute" class="form-label">Rate limit/min</label>
                        <input type="number" name="auth_login_rate_limit_per_minute" id="auth_login_rate_limit_per_minute"
                               class="form-control form-control-sm @error('auth_login_rate_limit_per_minute') is-invalid @enderror"
                               value="{{ old('auth_login_rate_limit_per_minute', $settings['auth_login_rate_limit_per_minute'] ?? 5) }}" min="1" max="120">
                        @error('auth_login_rate_limit_per_minute')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- 2. Password reset / verification rate limits --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent border-bottom py-3">
                <h5 class="card-title mb-0 fw-semibold">Password Reset & Verification Rate Limits</h5>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="auth_password_forgot_rate_limit" class="form-label">Forgot rate limit/min</label>
                        <input type="number" name="auth_password_forgot_rate_limit" id="auth_password_forgot_rate_limit"
                               class="form-control form-control-sm @error('auth_password_forgot_rate_limit') is-invalid @enderror"
                               value="{{ old('auth_password_forgot_rate_limit', $settings['auth_password_forgot_rate_limit'] ?? 3) }}" min="1" max="30">
                        @error('auth_password_forgot_rate_limit')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-3">
                        <label for="auth_password_reset_rate_limit" class="form-label">Reset rate limit/min</label>
                        <input type="number" name="auth_password_reset_rate_limit" id="auth_password_reset_rate_limit"
                               class="form-control form-control-sm @error('auth_password_reset_rate_limit') is-invalid @enderror"
                               value="{{ old('auth_password_reset_rate_limit', $settings['auth_password_reset_rate_limit'] ?? 3) }}" min="1" max="30">
                        @error('auth_password_reset_rate_limit')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-3">
                        <label for="auth_password_reset_token_expire_minutes" class="form-label">Reset token expire (min)</label>
                        <input type="number" name="auth_password_reset_token_expire_minutes" id="auth_password_reset_token_expire_minutes"
                               class="form-control form-control-sm @error('auth_password_reset_token_expire_minutes') is-invalid @enderror"
                               value="{{ old('auth_password_reset_token_expire_minutes', $settings['auth_password_reset_token_expire_minutes'] ?? 15) }}" min="1" max="1440">
                        @error('auth_password_reset_token_expire_minutes')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-3">
                        <label for="auth_email_verification_rate_limit" class="form-label">Verify rate limit/hr</label>
                        <input type="number" name="auth_email_verification_rate_limit" id="auth_email_verification_rate_limit"
                               class="form-control form-control-sm @error('auth_email_verification_rate_limit') is-invalid @enderror"
                               value="{{ old('auth_email_verification_rate_limit', $settings['auth_email_verification_rate_limit'] ?? 5) }}" min="1" max="100">
                        @error('auth_email_verification_rate_limit')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-3">
                        <label for="auth_email_verification_token_expire_minutes" class="form-label">Verify token expire (min)</label>
                        <input type="number" name="auth_email_verification_token_expire_minutes" id="auth_email_verification_token_expire_minutes"
                               class="form-control form-control-sm @error('auth_email_verification_token_expire_minutes') is-invalid @enderror"
                               value="{{ old('auth_email_verification_token_expire_minutes', $settings['auth_email_verification_token_expire_minutes'] ?? 60) }}" min="1" max="1440">
                        @error('auth_email_verification_token_expire_minutes')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- 3. Password policy & lifecycle --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent border-bottom py-3">
                <h5 class="card-title mb-0 fw-semibold">Password Policy & Lifecycle</h5>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="auth_password_min_length" class="form-label">Min length</label>
                        <input type="number" name="auth_password_min_length" id="auth_password_min_length"
                               class="form-control form-control-sm @error('auth_password_min_length') is-invalid @enderror"
                               value="{{ old('auth_password_min_length', $settings['auth_password_min_length'] ?? 8) }}" min="4" max="128">
                        @error('auth_password_min_length')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-3">
                        <label for="auth_password_history_count" class="form-label">History count</label>
                        <input type="number" name="auth_password_history_count" id="auth_password_history_count"
                               class="form-control form-control-sm @error('auth_password_history_count') is-invalid @enderror"
                               value="{{ old('auth_password_history_count', $settings['auth_password_history_count'] ?? 5) }}" min="0" max="24">
                        @error('auth_password_history_count')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-3">
                        <label for="auth_password_expiration_days" class="form-label">Expiration (days)</label>
                        <input type="number" name="auth_password_expiration_days" id="auth_password_expiration_days"
                               class="form-control form-control-sm @error('auth_password_expiration_days') is-invalid @enderror"
                               value="{{ old('auth_password_expiration_days', $settings['auth_password_expiration_days'] ?? 90) }}" min="1" max="365">
                        @error('auth_password_expiration_days')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <hr class="my-3">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="auth_password_mixed_case" id="auth_password_mixed_case"
                        {{ ($settings['auth_password_mixed_case'] ?? true) ? 'checked' : '' }}>
                    <label class="form-check-label" for="auth_password_mixed_case">Require mixed case</label>
                </div>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="auth_password_numbers" id="auth_password_numbers"
                        {{ ($settings['auth_password_numbers'] ?? true) ? 'checked' : '' }}>
                    <label class="form-check-label" for="auth_password_numbers">Require numbers</label>
                </div>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="auth_password_symbols" id="auth_password_symbols"
                        {{ ($settings['auth_password_symbols'] ?? true) ? 'checked' : '' }}>
                    <label class="form-check-label" for="auth_password_symbols">Require symbols</label>
                </div>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="auth_password_uncompromised" id="auth_password_uncompromised"
                        {{ ($settings['auth_password_uncompromised'] ?? false) ? 'checked' : '' }}>
                    <label class="form-check-label" for="auth_password_uncompromised">Check uncompromised (Have I Been Pwned)</label>
                </div>
            </div>
        </div>

        {{-- 4. Email verification --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent border-bottom py-3">
                <h5 class="card-title mb-0 fw-semibold">Email Verification</h5>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="auth_verification_expire_minutes" class="form-label">Link expire (min)</label>
                        <input type="number" name="auth_verification_expire_minutes" id="auth_verification_expire_minutes"
                               class="form-control form-control-sm @error('auth_verification_expire_minutes') is-invalid @enderror"
                               value="{{ old('auth_verification_expire_minutes', $settings['auth_verification_expire_minutes'] ?? 60) }}" min="1" max="1440">
                        @error('auth_verification_expire_minutes')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-3">
                        <label for="auth_verification_mode" class="form-label">Mode</label>
                        <select name="auth_verification_mode" id="auth_verification_mode"
                                class="form-select form-select-sm @error('auth_verification_mode') is-invalid @enderror">
                            <option value="public" {{ ($settings['auth_verification_mode'] ?? 'public') === 'public' ? 'selected' : '' }}>Public</option>
                            <option value="admin" {{ ($settings['auth_verification_mode'] ?? 'public') === 'admin' ? 'selected' : '' }}>Admin-only</option>
                            <option value="disabled" {{ ($settings['auth_verification_mode'] ?? 'public') === 'disabled' ? 'selected' : '' }}>Disabled</option>
                        </select>
                        @error('auth_verification_mode')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- 5. Password reset token --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent border-bottom py-3">
                <h5 class="card-title mb-0 fw-semibold">Password Reset Token</h5>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="auth_password_reset_expire_minutes" class="form-label">Expire (min)</label>
                        <input type="number" name="auth_password_reset_expire_minutes" id="auth_password_reset_expire_minutes"
                               class="form-control form-control-sm @error('auth_password_reset_expire_minutes') is-invalid @enderror"
                               value="{{ old('auth_password_reset_expire_minutes', $settings['auth_password_reset_expire_minutes'] ?? 15) }}" min="1" max="1440">
                        @error('auth_password_reset_expire_minutes')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- 6. Username / email change settings --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent border-bottom py-3">
                <h5 class="card-title mb-0 fw-semibold">Username & Email Change</h5>
            </div>
            <div class="card-body p-4">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="allow_username_change" id="allow_username_change"
                        {{ ($settings['allow_username_change'] ?? true) ? 'checked' : '' }}>
                    <label class="form-check-label" for="allow_username_change">Allow username change</label>
                </div>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="allow_email_change" id="allow_email_change"
                        {{ ($settings['allow_email_change'] ?? true) ? 'checked' : '' }}>
                    <label class="form-check-label" for="allow_email_change">Allow email change</label>
                </div>
                <hr class="my-3">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="username_change_cooldown_days" class="form-label">Username cooldown (days)</label>
                        <input type="number" name="username_change_cooldown_days" id="username_change_cooldown_days"
                               class="form-control form-control-sm @error('username_change_cooldown_days') is-invalid @enderror"
                               value="{{ old('username_change_cooldown_days', $settings['username_change_cooldown_days'] ?? 30) }}" min="0" max="365">
                        @error('username_change_cooldown_days')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-3">
                        <label for="email_change_cooldown_days" class="form-label">Email cooldown (days)</label>
                        <input type="number" name="email_change_cooldown_days" id="email_change_cooldown_days"
                               class="form-control form-control-sm @error('email_change_cooldown_days') is-invalid @enderror"
                               value="{{ old('email_change_cooldown_days', $settings['email_change_cooldown_days'] ?? 30) }}" min="0" max="365">
                        @error('email_change_cooldown_days')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
            <div class="card-footer bg-body-tertiary border-top py-3 d-flex justify-content-end align-items-center gap-2">
                <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
                    <i class="bi bi-x-circle"></i> Cancel
                </a>
                <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-2">
                    <i class="bi bi-check-lg"></i> Save Settings
                </button>
            </div>
        </div>
    </form>
@endsection