@extends('layouts.app', ['title' => 'System Settings'])

@section('content')
    <div class="content-header mb-3">
        <div class="d-flex justify-content-between align-items-start w-100">
            <div>
                <h1 class="page-title fw-bold">System Settings</h1>
                <p class="page-description text-muted fs-6 mb-0">
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

        <div class="row g-4">
            {{-- Main Content Area --}}
            <div class="col-lg-8 col-12">

                {{-- Card 1: Login Security & Progressive Lockout --}}
                <div class="card border-0 shadow-sm mb-4" id="section-login">
                    <div class="card-header bg-transparent border-bottom py-3">
                        <h5 class="card-title mb-0 fw-semibold">Login Security &amp; Progressive Lockout</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-6 col-md-3">
                                <label for="login_max_attempts" class="form-label">
                                    Max Attempts
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Maximum number of failed login attempts allowed before an account is automatically locked."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="login_max_attempts" id="login_max_attempts"
                                           class="form-control form-control-sm @error('login_max_attempts') is-invalid @enderror"
                                           value="{{ old('login_max_attempts', $settings['login_max_attempts'] ?? 5) }}" min="1" max="99">
                                    <span class="input-group-text bg-body-tertiary">attempts</span>
                                </div>
                                @error('login_max_attempts')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-6 col-md-3">
                                <label for="lockout_base_minutes" class="form-label">
                                    Lockout Duration
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="The initial lockout duration applied when the failed login threshold is reached."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="lockout_base_minutes" id="lockout_base_minutes"
                                           class="form-control form-control-sm @error('lockout_base_minutes') is-invalid @enderror"
                                           value="{{ old('lockout_base_minutes', $settings['lockout_base_minutes'] ?? 5) }}" min="1" max="60">
                                    <span class="input-group-text bg-body-tertiary">min</span>
                                </div>
                                @error('lockout_base_minutes')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-6 col-md-3">
                                <label for="lockout_increment_minutes" class="form-label">
                                    Lockout Penalty
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Additional penalty duration added for each subsequent failed login attempt."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="lockout_increment_minutes" id="lockout_increment_minutes"
                                           class="form-control form-control-sm @error('lockout_increment_minutes') is-invalid @enderror"
                                           value="{{ old('lockout_increment_minutes', $settings['lockout_increment_minutes'] ?? 10) }}" min="1" max="120">
                                    <span class="input-group-text bg-body-tertiary">min</span>
                                </div>
                                @error('lockout_increment_minutes')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-6 col-md-3">
                                <label for="login_rate_limit_per_minute" class="form-label">
                                    Login Rate Limit
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Maximum number of total login requests allowed per minute per IP address."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="login_rate_limit_per_minute" id="login_rate_limit_per_minute"
                                           class="form-control form-control-sm @error('login_rate_limit_per_minute') is-invalid @enderror"
                                           value="{{ old('login_rate_limit_per_minute', $settings['login_rate_limit_per_minute'] ?? 5) }}" min="1" max="120">
                                    <span class="input-group-text bg-body-tertiary">/min</span>
                                </div>
                                @error('login_rate_limit_per_minute')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Card 2: Password Policy & Lifecycle --}}
                <div class="card border-0 shadow-sm mb-4" id="section-password-policy">
                    <div class="card-header bg-transparent border-bottom py-3">
                        <h5 class="card-title mb-0 fw-semibold">Password Policy &amp; Lifecycle</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="password_min_length" class="form-label">
                                    Min Password Length
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Minimum character length required when creating or updating passwords."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="password_min_length" id="password_min_length"
                                           class="form-control form-control-sm @error('password_min_length') is-invalid @enderror"
                                           value="{{ old('password_min_length', $settings['password_min_length'] ?? 8) }}" min="4" max="128">
                                    <span class="input-group-text bg-body-tertiary">chars</span>
                                </div>
                                @error('password_min_length')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="password_expiration_days" class="form-label">
                                    Password Expiration
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Number of days before passwords expire and require renewal (0 to disable)."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="password_expiration_days" id="password_expiration_days"
                                           class="form-control form-control-sm @error('password_expiration_days') is-invalid @enderror"
                                           value="{{ old('password_expiration_days', $settings['password_expiration_days'] ?? 90) }}" min="1" max="365">
                                    <span class="input-group-text bg-body-tertiary">days</span>
                                </div>
                                @error('password_expiration_days')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        {{-- Password History --}}
                        <div class="row g-3 mt-0">
                            <div class="col-md-6">
                                <label for="password_history_count" class="form-label">
                                    Password History
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Number of previous passwords remembered to prevent password reuse."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="password_history_count" id="password_history_count"
                                           class="form-control form-control-sm @error('password_history_count') is-invalid @enderror"
                                           value="{{ old('password_history_count', $settings['password_history_count'] ?? 5) }}" min="0" max="24"
                                           {{ filter_var($settings['password_history_enabled'] ?? 'true', FILTER_VALIDATE_BOOLEAN) ? '' : 'disabled' }}>
                                    <span class="input-group-text bg-body-tertiary">counts</span>
                                </div>
                                @error('password_history_count')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            {{-- Password History toggle --}}
                            <div class="col-md-6">
                                <div class="form-check form-switch mt-4 pt-2">
                                    <input class="form-check-input" type="checkbox" name="password_history_enabled" id="password_history_enabled"
                                        {{ filter_var($settings['password_history_enabled'] ?? 'true', FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' }}>
                                    <label for="password_history_enabled" class="form-check-label">
                                        History Enforcement
                                        <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Enforces password history check on change/reset, prevents reuse of recent passwords."></i>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <hr class="my-3">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="password_mixed_case" id="password_mixed_case"
                                        {{ filter_var($settings['password_mixed_case'] ?? 'true', FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' }}>
                                    <label for="password_mixed_case" class="form-check-label">
                                        Mixed Case
                                        <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Enforces a combination of uppercase (A-Z) and lowercase (a-z) letters."></i>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="password_numbers" id="password_numbers"
                                        {{ filter_var($settings['password_numbers'] ?? 'true', FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="password_numbers">
                                        Numbers
                                        <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Enforces inclusion of at least one numeric digit (0-9)."></i>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="password_symbols" id="password_symbols"
                                        {{ filter_var($settings['password_symbols'] ?? 'true', FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="password_symbols">
                                        Symbols
                                        <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Enforces inclusion of at least one special character (e.g., @, #, $)."></i>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="password_uncompromised" id="password_uncompromised"
                                        {{ filter_var($settings['password_uncompromised'] ?? 'false', FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="password_uncompromised">
                                        Pwned Check
                                        <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Checks whether candidate passwords have been compromised in public data breaches via HaveIBeenPwned API."></i>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Card 4: Password Expiration & Inactivity Lock --}}
                <div class="card border-0 shadow-sm mb-4" id="section-password-expiry">
                    <div class="card-header bg-transparent border-bottom py-3">
                        <h5 class="card-title mb-0 fw-semibold">Password Expiration &amp; Inactivity Lock</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="password_security_sweep_time" class="form-label">
                                    Sweep Time
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Local time when both password security sweep jobs run."></i>
                                </label>
                                <input type="time" name="password_security_sweep_time" id="password_security_sweep_time"
                                       class="form-control form-control-sm @error('password_security_sweep_time') is-invalid @enderror"
                                       value="{{ old('password_security_sweep_time', $settings['password_security_sweep_time'] ?? '00:00') }}">
                                @error('password_security_sweep_time')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="password_security_sweep_timezone" class="form-label">
                                    Sweep Timezone
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Timezone used for the sweep time. Leave on application timezone to use the server/application setting."></i>
                                </label>
                                <select name="password_security_sweep_timezone" id="password_security_sweep_timezone"
                                        class="form-select form-select-sm @error('password_security_sweep_timezone') is-invalid @enderror">
                                    <option value="" {{ old('password_security_sweep_timezone', $settings['password_security_sweep_timezone'] ?? '') === '' ? 'selected' : '' }}>
                                        Application timezone ({{ config('app.timezone') }})
                                    </option>
                                    @foreach ($timezones as $timezone)
                                        <option value="{{ $timezone->name }}" {{ old('password_security_sweep_timezone', $settings['password_security_sweep_timezone'] ?? '') === $timezone->name ? 'selected' : '' }}>
                                            {{ $timezone->label }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('password_security_sweep_timezone')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="row g-3 mt-0">
                            <div class="col-md-6">
                                <label for="password_expiry_days" class="form-label">
                                    Expiry Days
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Number of days before a password expires (0 to disable)."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="password_expiry_days" id="password_expiry_days"
                                           class="form-control form-control-sm @error('password_expiry_days') is-invalid @enderror"
                                           value="{{ old('password_expiry_days', $settings['password_expiry_days'] ?? 90) }}" min="0" max="365"
                                           {{ filter_var($settings['password_expiry_enabled'] ?? 'true', FILTER_VALIDATE_BOOLEAN) ? '' : 'disabled' }}>
                                    <span class="input-group-text bg-body-tertiary">days</span>
                                </div>
                                @error('password_expiry_days')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch mt-4 pt-2">
                                    <input class="form-check-input" type="checkbox" name="password_expiry_enabled" id="password_expiry_enabled"
                                        {{ filter_var($settings['password_expiry_enabled'] ?? 'true', FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' }}>
                                    <label for="password_expiry_enabled" class="form-check-label">
                                        Password Expiry
                                        <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Enforces password expiration after the configured number of days."></i>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3 mt-0">
                            <div class="col-md-6">
                                <label for="inactivity_lock_days" class="form-label">
                                    Inactivity Days
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Number of days of inactivity before account is locked."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="inactivity_lock_days" id="inactivity_lock_days"
                                           class="form-control form-control-sm @error('inactivity_lock_days') is-invalid @enderror"
                                           value="{{ old('inactivity_lock_days', $settings['inactivity_lock_days'] ?? 30) }}" min="1" max="365"
                                           {{ filter_var($settings['inactivity_lock_enabled'] ?? 'true', FILTER_VALIDATE_BOOLEAN) ? '' : 'disabled' }}>
                                    <span class="input-group-text bg-body-tertiary">days</span>
                                </div>
                                @error('inactivity_lock_days')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch mt-4 pt-2">
                                    <input class="form-check-input" type="checkbox" name="inactivity_lock_enabled" id="inactivity_lock_enabled"
                                        {{ filter_var($settings['inactivity_lock_enabled'] ?? 'true', FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' }}>
                                    <label for="inactivity_lock_enabled" class="form-check-label">
                                        Inactivity Lock
                                        <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Locks accounts after the configured number of days of inactivity."></i>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3 mt-0">
                            <div class="col-md-6">
                                <label for="password_expiry_warn_days" class="form-label">
                                    Warning Days
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Number of days before expiry to show warning banner."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="password_expiry_warn_days" id="password_expiry_warn_days"
                                           class="form-control form-control-sm @error('password_expiry_warn_days') is-invalid @enderror"
                                           value="{{ old('password_expiry_warn_days', $settings['password_expiry_warn_days'] ?? 14) }}" min="1" max="90">
                                    <span class="input-group-text bg-body-tertiary">days</span>
                                </div>
                                @error('password_expiry_warn_days')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card border-0 shadow-sm mb-4" id="section-password-reset">
                    <div class="card-header bg-transparent border-bottom py-3">
                        <h5 class="card-title mb-0 fw-semibold">Rate Limits &amp; Expirations</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="password_forgot_rate_limit" class="form-label">
                                    Forgot Password Limit
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Maximum forgot password request submissions allowed per minute."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="password_forgot_rate_limit" id="password_forgot_rate_limit"
                                           class="form-control form-control-sm @error('password_forgot_rate_limit') is-invalid @enderror"
                                           value="{{ old('password_forgot_rate_limit', $settings['password_forgot_rate_limit'] ?? 3) }}" min="1" max="30">
                                    <span class="input-group-text bg-body-tertiary">/min</span>
                                </div>
                                @error('password_forgot_rate_limit')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="password_reset_rate_limit" class="form-label">
                                    Reset Submit Limit
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Maximum password reset attempt executions allowed per minute."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="password_reset_rate_limit" id="password_reset_rate_limit"
                                           class="form-control form-control-sm @error('password_reset_rate_limit') is-invalid @enderror"
                                           value="{{ old('password_reset_rate_limit', $settings['password_reset_rate_limit'] ?? 3) }}" min="1" max="30">
                                    <span class="input-group-text bg-body-tertiary">/min</span>
                                </div>
                                @error('password_reset_rate_limit')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="password_reset_token_expire_minutes" class="form-label">
                                    Reset Token Expiry
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Lifetime duration of a password reset link before it expires."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="password_reset_token_expire_minutes" id="password_reset_token_expire_minutes"
                                           class="form-control form-control-sm @error('password_reset_token_expire_minutes') is-invalid @enderror"
                                           value="{{ old('password_reset_token_expire_minutes', $settings['password_reset_token_expire_minutes'] ?? 15) }}" min="1" max="1440">
                                    <span class="input-group-text bg-body-tertiary">min</span>
                                </div>
                                @error('password_reset_token_expire_minutes')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="email_verification_rate_limit" class="form-label">
                                    Verify Request Limit
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Maximum email verification resend requests allowed per hour."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="email_verification_rate_limit" id="email_verification_rate_limit"
                                           class="form-control form-control-sm @error('email_verification_rate_limit') is-invalid @enderror"
                                           value="{{ old('email_verification_rate_limit', $settings['email_verification_rate_limit'] ?? 5) }}" min="1" max="100">
                                    <span class="input-group-text bg-body-tertiary">/hr</span>
                                </div>
                                @error('email_verification_rate_limit')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="email_verification_expire_minutes" class="form-label">
                                    Verify Link Expiry
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Lifetime duration of an email verification link before it expires."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="email_verification_expire_minutes" id="email_verification_expire_minutes"
                                           class="form-control form-control-sm @error('email_verification_expire_minutes') is-invalid @enderror"
                                           value="{{ old('email_verification_expire_minutes', $settings['email_verification_expire_minutes'] ?? 60) }}" min="1" max="1440">
                                    <span class="input-group-text bg-body-tertiary">min</span>
                                </div>
                                @error('email_verification_expire_minutes')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            {{-- Sidebar Area --}}
            <div class="col-12 col-lg-4">

                {{-- Card 4: Identity & Account Policies --}}
                <div class="card border-0 shadow-sm mb-4" id="section-change">
                    <div class="card-header bg-transparent border-bottom py-3">
                        <h5 class="card-title mb-0 fw-semibold">Identity &amp; Account Rules</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="allow_username_change" id="allow_username_change"
                                {{ filter_var($settings['allow_username_change'] ?? 'true', FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' }}>
                            <label class="form-check-label" for="allow_username_change">
                                Username Change
                                <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Permits users to update their usernames from their profile page."></i>
                            </label>
                        </div>
                        <div class="mb-3">
                            <label for="username_change_cooldown_days" class="form-label">
                                Username Cooldown
                                <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Cooldown period required before a user can change their username again."></i>
                            </label>
                            <div class="input-group">
                                <input type="number" name="username_change_cooldown_days" id="username_change_cooldown_days"
                                       class="form-control form-control-sm @error('username_change_cooldown_days') is-invalid @enderror"
                                       value="{{ old('username_change_cooldown_days', $settings['username_change_cooldown_days'] ?? 30) }}" min="0" max="365">
                                <span class="input-group-text bg-body-tertiary">days</span>
                            </div>
                            @error('username_change_cooldown_days')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="allow_email_change" id="allow_email_change"
                                {{ filter_var($settings['allow_email_change'] ?? 'true', FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' }}>
                            <label class="form-check-label" for="allow_email_change">
                                Email Change
                                <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Permits users to update their email addresses from their profile page."></i>
                            </label>
                        </div>
                        <div class="mb-3">
                            <label for="email_change_cooldown_days" class="form-label">
                                Email Cooldown
                                <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Cooldown period required before a user can change their email address again."></i>
                            </label>
                            <div class="input-group">
                                <input type="number" name="email_change_cooldown_days" id="email_change_cooldown_days"
                                       class="form-control form-control-sm @error('email_change_cooldown_days') is-invalid @enderror"
                                       value="{{ old('email_change_cooldown_days', $settings['email_change_cooldown_days'] ?? 30) }}" min="0" max="365">
                                <span class="input-group-text bg-body-tertiary">days</span>
                            </div>
                            @error('email_change_cooldown_days')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="mb-3">
                            <label for="email_verification_mode" class="form-label">
                                Verification Mode
                                <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Determines the email verification strategy (Public, Admin, or Disabled)."></i>
                            </label>
                            <select name="email_verification_mode" id="email_verification_mode"
                                    class="form-select form-select-sm @error('email_verification_mode') is-invalid @enderror">
                                <option value="public" {{ ($settings['email_verification_mode'] ?? 'public') === 'public' ? 'selected' : '' }}>Public</option>
                                <option value="admin" {{ ($settings['email_verification_mode'] ?? 'public') === 'admin' ? 'selected' : '' }}>Admin-only</option>
                                <option value="disabled" {{ ($settings['email_verification_mode'] ?? 'public') === 'disabled' ? 'selected' : '' }}>Disabled</option>
                            </select>
                            @error('email_verification_mode')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                {{-- Card 5: Sticky Save Actions Card --}}
                <div class="card border-0 shadow-sm mb-4 sticky-top" style="top: 80px;">
                    <div class="card-header bg-transparent border-bottom py-3">
                        <h5 class="card-title mb-0 fw-semibold">Save Settings</h5>
                    </div>
                    <div class="card-body p-4">
                        <p class="text-muted fs-6 mb-0">Review your changes before saving. All settings are applied immediately upon saving.</p>
                    </div>
                    <div class="card-footer bg-body-tertiary border-top py-3 d-flex justify-content-end align-items-center gap-2">
                        <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
                            <i class="bi bi-x-circle"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-2">
                            <i class="bi bi-check-circle-fill"></i> Save Settings
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

            // Toggle controls keep dependent numeric fields disabled.
            const dependentFields = [
                ['password_history_enabled', 'password_history_count'],
                ['password_expiry_enabled', 'password_expiry_days'],
                ['inactivity_lock_enabled', 'inactivity_lock_days'],
            ];

            dependentFields.forEach(([toggleId, inputId]) => {
                const toggle = document.getElementById(toggleId);
                const input = document.getElementById(inputId);
                if (!toggle || !input) return;

                const syncField = () => {
                    input.disabled = !toggle.checked;
                };
                toggle.addEventListener('change', syncField);
                syncField();
            });
        });
    </script>
@endsection