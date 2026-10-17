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

    {{-- The route is gated on settings.view, so someone holding only that gets a
         READ-ONLY page rather than a 403 — they can see what is configured but
         cannot change it. Wrapping the whole form (inputs AND the save button in
         one pair) is what makes the page read-only; gating the button alone would
         leave editable fields that silently discard what is typed into them. --}}
    @can('settings.manage')
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
                                    Max Failed Attempts
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Maximum number of failed login attempts allowed before an account is automatically locked."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="login_max_attempts" id="login_max_attempts"
                                           class="form-control form-control-sm @error('login_max_attempts') is-invalid @enderror" @error('login_max_attempts') aria-invalid="true" aria-describedby="login_max_attempts_error" @enderror
                                           value="{{ old('login_max_attempts', $settings['login_max_attempts'] ?? 5) }}" min="1" max="99">
                                    <span class="input-group-text bg-body-tertiary">attempts</span>
                                </div>
                                @error('login_max_attempts')
                                    <div class="invalid-feedback d-block" id="login_max_attempts_error">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-6 col-md-3">
                                <label for="lockout_base_minutes" class="form-label">
                                    Initial Lockout Duration
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="The initial lockout duration applied when the failed login threshold is reached."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="lockout_base_minutes" id="lockout_base_minutes"
                                           class="form-control form-control-sm @error('lockout_base_minutes') is-invalid @enderror" @error('lockout_base_minutes') aria-invalid="true" aria-describedby="lockout_base_minutes_error" @enderror
                                           value="{{ old('lockout_base_minutes', $settings['lockout_base_minutes'] ?? 5) }}" min="1" max="60">
                                    <span class="input-group-text bg-body-tertiary">min</span>
                                </div>
                                @error('lockout_base_minutes')
                                    <div class="invalid-feedback d-block" id="lockout_base_minutes_error">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-6 col-md-3">
                                <label for="lockout_increment_minutes" class="form-label">
                                    Lockout Increment
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Additional penalty duration added for each subsequent failed login attempt."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="lockout_increment_minutes" id="lockout_increment_minutes"
                                           class="form-control form-control-sm @error('lockout_increment_minutes') is-invalid @enderror" @error('lockout_increment_minutes') aria-invalid="true" aria-describedby="lockout_increment_minutes_error" @enderror
                                           value="{{ old('lockout_increment_minutes', $settings['lockout_increment_minutes'] ?? 10) }}" min="1" max="120">
                                    <span class="input-group-text bg-body-tertiary">min</span>
                                </div>
                                @error('lockout_increment_minutes')
                                    <div class="invalid-feedback d-block" id="lockout_increment_minutes_error">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-6 col-md-3">
                                <label for="login_rate_limit_per_minute" class="form-label">
                                    Login Rate Limit
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Maximum number of total login requests allowed per minute per IP address."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="login_rate_limit_per_minute" id="login_rate_limit_per_minute"
                                           class="form-control form-control-sm @error('login_rate_limit_per_minute') is-invalid @enderror" @error('login_rate_limit_per_minute') aria-invalid="true" aria-describedby="login_rate_limit_per_minute_error" @enderror
                                           value="{{ old('login_rate_limit_per_minute', $settings['login_rate_limit_per_minute'] ?? 5) }}" min="1" max="120">
                                    <span class="input-group-text bg-body-tertiary">/ min</span>
                                </div>
                                @error('login_rate_limit_per_minute')
                                    <div class="invalid-feedback d-block" id="login_rate_limit_per_minute_error">{{ $message }}</div>
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
                                    Minimum Length
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Minimum character length required when creating or updating passwords."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="password_min_length" id="password_min_length"
                                           class="form-control form-control-sm @error('password_min_length') is-invalid @enderror" @error('password_min_length') aria-invalid="true" aria-describedby="password_min_length_error" @enderror
                                           value="{{ old('password_min_length', $settings['password_min_length'] ?? 12) }}" min="4" max="128">
                                    <span class="input-group-text bg-body-tertiary">chars</span>
                                </div>
                                @error('password_min_length')
                                    <div class="invalid-feedback d-block" id="password_min_length_error">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        {{-- Password History --}}
                        <div class="row g-3 mt-0">
                            <div class="col-md-6">
                                <label for="password_history_count" class="form-label">
                                    Password History Limit
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Number of previous passwords remembered to prevent password reuse."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="password_history_count" id="password_history_count"
                                           class="form-control form-control-sm @error('password_history_count') is-invalid @enderror" @error('password_history_count') aria-invalid="true" aria-describedby="password_history_count_error" @enderror
                                           value="{{ old('password_history_count', $settings['password_history_count'] ?? 5) }}" min="0" max="24"
                                           {{ $settings['password_history_enabled'] ?? true ? '' : 'disabled' }}>
                                    <span class="input-group-text bg-body-tertiary">counts</span>
                                </div>
                                @error('password_history_count')
                                    <div class="invalid-feedback d-block" id="password_history_count_error">{{ $message }}</div>
                                @enderror
                            </div>
                            {{-- Password History toggle --}}
                            <div class="col-md-6">
                                <div class="form-check form-switch mt-4 pt-2">
                                    <input type="hidden" name="password_history_enabled" value="0">
                                    <input class="form-check-input" type="checkbox" name="password_history_enabled" id="password_history_enabled"
                                        @checked($settings['password_history_enabled'] ?? true)>
                                    <label for="password_history_enabled" class="form-check-label">
                                        Enforce History Check
                                        <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Enforces password history check on change/reset, prevents reuse of recent passwords."></i>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <hr class="my-3">
                        {{-- One switch per key PasswordPolicy actually reads. The form used to offer
                             "Mixed Case", "Numbers" and "Symbols" — keys nothing read — while the keys
                             the policy consulted (password_require_upper/lower/digit/symbol) had no UI
                             at all, so every one of those toggles was inert. PolicyKeyTest keeps this
                             list and PasswordPolicy's in step. --}}
                        <div class="row g-3">
                            @foreach ([
                                'password_require_upper' => ['Require Uppercase', 'Requires at least one uppercase letter (A-Z).'],
                                'password_require_lower' => ['Require Lowercase', 'Requires at least one lowercase letter (a-z).'],
                                'password_require_digit' => ['Require Numbers', 'Requires at least one numeric digit (0-9).'],
                                'password_require_symbol' => ['Require Symbols', 'Requires at least one special character (e.g., @, #, $).'],
                                'password_reject_username' => ['Reject Username Match', 'Refuses passwords that contain the account\'s username.'],
                                'password_uncompromised' => ['Check Leaked Passwords', 'Rejects passwords found in public data breaches using the HaveIBeenPwned k-anonymity API.'],
                            ] as $key => [$label, $hint])
                                <div class="col-md-6">
                                    <div class="form-check form-switch">
                                        {{-- Without this, an unticked box is simply absent from the
                                             payload, and SystemUpdateSettingsAction's
                                             `?? true` writes it back ON. Every switch here
                                             needs the companion or it can only ever be enabled. --}}
                                        <input type="hidden" name="{{ $key }}" value="0">
                                        <input class="form-check-input" type="checkbox" name="{{ $key }}" id="{{ $key }}"
                                            @checked($settings[$key] ?? true)>
                                        <label for="{{ $key }}" class="form-check-label">
                                            {{ $label }}
                                            <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="{{ $hint }}"></i>
                                        </label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Card 3: Password Expiration & Inactivity Lock --}}
                <div class="card border-0 shadow-sm mb-4" id="section-password-expiry">
                    <div class="card-header bg-transparent border-bottom py-3">
                        <h5 class="card-title mb-0 fw-semibold">Password Expiration &amp; Inactivity Lock</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="password_security_sweep_time" class="form-label">
                                    Daily Sweep Time
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Local time when automated password expiration and account inactivity sweep jobs run."></i>
                                </label>
                                <input type="time" name="password_security_sweep_time" id="password_security_sweep_time"
                                       class="form-control form-control-sm @error('password_security_sweep_time') is-invalid @enderror" @error('password_security_sweep_time') aria-invalid="true" aria-describedby="password_security_sweep_time_error" @enderror
                                       value="{{ old('password_security_sweep_time', $settings['password_security_sweep_time'] ?? '00:00') }}">
                                @error('password_security_sweep_time')
                                    <div class="invalid-feedback d-block" id="password_security_sweep_time_error">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="password_security_sweep_timezone" class="form-label">
                                    Sweep Timezone
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Timezone used for the sweep time. Leave on application timezone to use the server/application setting."></i>
                                </label>
                                <select name="password_security_sweep_timezone" id="password_security_sweep_timezone"
                                        class="form-select form-select-sm @error('password_security_sweep_timezone') is-invalid @enderror" @error('password_security_sweep_timezone') aria-invalid="true" aria-describedby="password_security_sweep_timezone_error" @enderror>
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
                                    <div class="invalid-feedback d-block" id="password_security_sweep_timezone_error">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="row g-3 mt-0">
                            <div class="col-md-6">
                                <label for="password_expiry_days" class="form-label">
                                    Password Expiration
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Number of days before a password expires (0 to disable)."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="password_expiry_days" id="password_expiry_days"
                                           class="form-control form-control-sm @error('password_expiry_days') is-invalid @enderror" @error('password_expiry_days') aria-invalid="true" aria-describedby="password_expiry_days_error" @enderror
                                           value="{{ old('password_expiry_days', $settings['password_expiry_days'] ?? 90) }}" min="0" max="365"
                                           {{ $settings['password_expiry_enabled'] ?? true ? '' : 'disabled' }}>
                                    <span class="input-group-text bg-body-tertiary">days</span>
                                </div>
                                @error('password_expiry_days')
                                    <div class="invalid-feedback d-block" id="password_expiry_days_error">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch mt-4 pt-2">
                                    <input type="hidden" name="password_expiry_enabled" value="0">
                                    <input class="form-check-input" type="checkbox" name="password_expiry_enabled" id="password_expiry_enabled"
                                        @checked($settings['password_expiry_enabled'] ?? true)>
                                    <label for="password_expiry_enabled" class="form-check-label">
                                        Enable Password Expiration
                                        <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Enforces password expiration after the configured number of days."></i>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3 mt-0">
                            <div class="col-md-6">
                                <label for="inactivity_lock_days" class="form-label">
                                    Inactivity Lock Threshold
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Number of days of inactivity before account is locked."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="inactivity_lock_days" id="inactivity_lock_days"
                                           class="form-control form-control-sm @error('inactivity_lock_days') is-invalid @enderror" @error('inactivity_lock_days') aria-invalid="true" aria-describedby="inactivity_lock_days_error" @enderror
                                           value="{{ old('inactivity_lock_days', $settings['inactivity_lock_days'] ?? 30) }}" min="1" max="365"
                                           {{ $settings['inactivity_lock_enabled'] ?? true ? '' : 'disabled' }}>
                                    <span class="input-group-text bg-body-tertiary">days</span>
                                </div>
                                @error('inactivity_lock_days')
                                    <div class="invalid-feedback d-block" id="inactivity_lock_days_error">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch mt-4 pt-2">
                                    <input type="hidden" name="inactivity_lock_enabled" value="0">
                                    <input class="form-check-input" type="checkbox" name="inactivity_lock_enabled" id="inactivity_lock_enabled"
                                        @checked($settings['inactivity_lock_enabled'] ?? true)>
                                    <label for="inactivity_lock_enabled" class="form-check-label">
                                        Enable Inactivity Lock
                                        <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Locks accounts after the configured number of days of inactivity."></i>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3 mt-0">
                            <div class="col-md-6">
                                <label for="inactivity_lock_grace_days" class="form-label">
                                    New Account Grace Period
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Additional days for never-logged-in accounts before inactivity lock."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="inactivity_lock_grace_days" id="inactivity_lock_grace_days"
                                           class="form-control form-control-sm @error('inactivity_lock_grace_days') is-invalid @enderror" @error('inactivity_lock_grace_days') aria-invalid="true" aria-describedby="inactivity_lock_grace_days_error" @enderror
                                           value="{{ old('inactivity_lock_grace_days', $settings['inactivity_lock_grace_days'] ?? 30) }}" min="0" max="365"
                                           {{ $settings['inactivity_lock_grace_enabled'] ?? true ? '' : 'disabled' }}>
                                    <span class="input-group-text bg-body-tertiary">days</span>
                                </div>
                                @error('inactivity_lock_grace_days')
                                    <div class="invalid-feedback d-block" id="inactivity_lock_grace_days_error">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch mt-4 pt-2">
                                    <input type="hidden" name="inactivity_lock_grace_enabled" value="0">
                                    <input class="form-check-input" type="checkbox" name="inactivity_lock_grace_enabled" id="inactivity_lock_grace_enabled"
                                        @checked($settings['inactivity_lock_grace_enabled'] ?? true) value="1">
                                    <label for="inactivity_lock_grace_enabled" class="form-check-label">
                                        Enable Grace Period for New Accounts
                                        <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Enables an extra grace period for accounts that have never logged in."></i>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3 mt-0">
                            <div class="col-md-6">
                                <label for="password_expiry_warn_days" class="form-label">
                                    Expiry Warning Period
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Number of days before expiry to show warning banner."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="password_expiry_warn_days" id="password_expiry_warn_days"
                                           class="form-control form-control-sm @error('password_expiry_warn_days') is-invalid @enderror" @error('password_expiry_warn_days') aria-invalid="true" aria-describedby="password_expiry_warn_days_error" @enderror
                                           value="{{ old('password_expiry_warn_days', $settings['password_expiry_warn_days'] ?? 14) }}" min="1" max="90"
                                           {{ $settings['password_expiry_enabled'] ?? true ? '' : 'disabled' }}>
                                    <span class="input-group-text bg-body-tertiary">days</span>
                                </div>
                                @error('password_expiry_warn_days')
                                    <div class="invalid-feedback d-block" id="password_expiry_warn_days_error">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card border-0 shadow-sm mb-4" id="section-rate-limits">
                    <div class="card-header bg-transparent border-bottom py-3">
                        <h5 class="card-title mb-0 fw-semibold">Rate Limits &amp; Expirations</h5>
                    </div>
                    <div class="card-body p-4">
                        {{-- Fields are grouped by the feature that governs them.
                             Eleven limiters in one flat run is a list nobody can
                             scan: an operator looking for the audit ceiling has to
                             read past seven auth fields to find it. Each group is a
                             full-width label followed by its own fields, on the
                             same two-column grid as everything else on this page. --}}
                        <div class="row g-3">
                            <div class="col-12">
                                <h6 class="text-body-secondary text-uppercase small fw-semibold mb-0 mt-1">Authentication</h6>
                            </div>
                            <div class="col-md-6">
                                <label for="password_forgot_rate_limit" class="form-label">
                                    Forgot Password Limit
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Maximum forgot-password requests allowed per minute for a single email and IP pair."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="password_forgot_rate_limit" id="password_forgot_rate_limit"
                                           class="form-control form-control-sm @error('password_forgot_rate_limit') is-invalid @enderror" @error('password_forgot_rate_limit') aria-invalid="true" aria-describedby="password_forgot_rate_limit_error" @enderror
                                           value="{{ old('password_forgot_rate_limit', $settings['password_forgot_rate_limit'] ?? 3) }}" min="1" max="30">
                                    <span class="input-group-text bg-body-tertiary">/ min</span>
                                </div>
                                @error('password_forgot_rate_limit')
                                    <div class="invalid-feedback d-block" id="password_forgot_rate_limit_error">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="password_reset_rate_limit" class="form-label">
                                    Reset Form Submit Limit
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Maximum password reset form submissions allowed per minute (brute-force guard)."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="password_reset_rate_limit" id="password_reset_rate_limit"
                                           class="form-control form-control-sm @error('password_reset_rate_limit') is-invalid @enderror" @error('password_reset_rate_limit') aria-invalid="true" aria-describedby="password_reset_rate_limit_error" @enderror
                                           value="{{ old('password_reset_rate_limit', $settings['password_reset_rate_limit'] ?? 3) }}" min="1" max="30">
                                    <span class="input-group-text bg-body-tertiary">/ min</span>
                                </div>
                                @error('password_reset_rate_limit')
                                    <div class="invalid-feedback d-block" id="password_reset_rate_limit_error">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="password_reset_token_expire_minutes" class="form-label">
                                    Reset Link Lifetime
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Duration in minutes a password reset link remains usable before expiring."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="password_reset_token_expire_minutes" id="password_reset_token_expire_minutes"
                                           class="form-control form-control-sm @error('password_reset_token_expire_minutes') is-invalid @enderror" @error('password_reset_token_expire_minutes') aria-invalid="true" aria-describedby="password_reset_token_expire_minutes_error" @enderror
                                           value="{{ old('password_reset_token_expire_minutes', $settings['password_reset_token_expire_minutes'] ?? 15) }}" min="1" max="1440">
                                    <span class="input-group-text bg-body-tertiary">min</span>
                                </div>
                                @error('password_reset_token_expire_minutes')
                                    <div class="invalid-feedback d-block" id="password_reset_token_expire_minutes_error">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="password_reset_expire_minutes" class="form-label">
                                    Reset Token Lifetime
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Duration in minutes the internal security token behind the reset link stays valid."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="password_reset_expire_minutes" id="password_reset_expire_minutes"
                                           class="form-control form-control-sm @error('password_reset_expire_minutes') is-invalid @enderror" @error('password_reset_expire_minutes') aria-invalid="true" aria-describedby="password_reset_expire_minutes_error" @enderror
                                           value="{{ old('password_reset_expire_minutes', $settings['password_reset_expire_minutes'] ?? 15) }}" min="1" max="1440">
                                    <span class="input-group-text bg-body-tertiary">min</span>
                                </div>
                                @error('password_reset_expire_minutes')
                                    <div class="invalid-feedback d-block" id="password_reset_expire_minutes_error">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="email_verification_rate_limit" class="form-label">
                                    Verification Email Limit
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Maximum verification emails allowed per hour for sign-ups and resend requests."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="email_verification_rate_limit" id="email_verification_rate_limit"
                                           class="form-control form-control-sm @error('email_verification_rate_limit') is-invalid @enderror" @error('email_verification_rate_limit') aria-invalid="true" aria-describedby="email_verification_rate_limit_error" @enderror
                                           value="{{ old('email_verification_rate_limit', $settings['email_verification_rate_limit'] ?? 5) }}" min="1" max="100">
                                    <span class="input-group-text bg-body-tertiary">/ hr</span>
                                </div>
                                @error('email_verification_rate_limit')
                                    <div class="invalid-feedback d-block" id="email_verification_rate_limit_error">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="email_verification_expire_minutes" class="form-label">
                                    Verification Link Lifetime
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Duration in minutes an email verification link remains usable before expiring."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="email_verification_expire_minutes" id="email_verification_expire_minutes"
                                           class="form-control form-control-sm @error('email_verification_expire_minutes') is-invalid @enderror" @error('email_verification_expire_minutes') aria-invalid="true" aria-describedby="email_verification_expire_minutes_error" @enderror
                                           value="{{ old('email_verification_expire_minutes', $settings['email_verification_expire_minutes'] ?? 60) }}" min="1" max="1440">
                                    <span class="input-group-text bg-body-tertiary">min</span>
                                </div>
                                @error('email_verification_expire_minutes')
                                    <div class="invalid-feedback d-block" id="email_verification_expire_minutes_error">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="email_verification_token_expire_minutes" class="form-label">
                                    Verification Token Lifetime
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Duration in minutes the internal token behind the verification link stays valid."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="email_verification_token_expire_minutes" id="email_verification_token_expire_minutes"
                                           class="form-control form-control-sm @error('email_verification_token_expire_minutes') is-invalid @enderror" @error('email_verification_token_expire_minutes') aria-invalid="true" aria-describedby="email_verification_token_expire_minutes_error" @enderror
                                           value="{{ old('email_verification_token_expire_minutes', $settings['email_verification_token_expire_minutes'] ?? 60) }}" min="1" max="1440">
                                    <span class="input-group-text bg-body-tertiary">min</span>
                                </div>
                                @error('email_verification_token_expire_minutes')
                                    <div class="invalid-feedback d-block" id="email_verification_token_expire_minutes_error">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12">
                                <h6 class="text-body-secondary text-uppercase small fw-semibold mb-0 mt-1">Audit Trail</h6>
                            </div>

                            {{-- Per HOUR, and it is the only field in this section that is:
                                 an audit export streams the whole filtered table into a
                                 file on a worker, so the cost per call is orders of
                                 magnitude above every per-minute limiter above it. The
                                 audit page quotes this same number in the Export
                                 button's tooltip — both read this row, so the figure
                                 shown and the figure enforced cannot drift apart. --}}
                            <div class="col-md-6">
                                <label for="audit_export_rate_limit_per_hour" class="form-label">
                                    Audit Export Limit
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Maximum audit trail CSV exports allowed per hour per operator and IP pair. Each one is generated on a queue worker."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="audit_export_rate_limit_per_hour" id="audit_export_rate_limit_per_hour"
                                           class="form-control form-control-sm @error('audit_export_rate_limit_per_hour') is-invalid @enderror" @error('audit_export_rate_limit_per_hour') aria-invalid="true" aria-describedby="audit_export_rate_limit_per_hour_error" @enderror
                                           value="{{ old('audit_export_rate_limit_per_hour', $settings['audit_export_rate_limit_per_hour'] ?? 5) }}" min="1" max="500">
                                    <span class="input-group-text bg-body-tertiary">/ hour</span>
                                </div>
                                @error('audit_export_rate_limit_per_hour')
                                    <div class="invalid-feedback d-block" id="audit_export_rate_limit_per_hour_error">{{ $message }}</div>
                                @enderror
                            </div>

                            {{-- Per ROW, not per hour: the counter lives on the export
                                 itself, so this is how many times one failed export may
                                 be re-run by hand before it says "Contact admin". Read
                                 by `AuditExport::maxRetries()`, which the retry Action
                                 and the API resource also call.

                                 Zero is allowed on purpose — it removes the button
                                 entirely, which is the honest setting for an install
                                 whose failures are environmental and better handled by
                                 an operator than by an operator's patience. --}}
                            <div class="col-md-6">
                                <label for="audit_export_max_manual_retries" class="form-label">
                                    Export Retries
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="How many times a single failed export may be re-run by hand before the row asks the operator to contact an administrator. Each retry is three further attempts at building the file. Set to 0 to remove the retry button."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="audit_export_max_manual_retries" id="audit_export_max_manual_retries"
                                           class="form-control form-control-sm @error('audit_export_max_manual_retries') is-invalid @enderror" @error('audit_export_max_manual_retries') aria-invalid="true" aria-describedby="audit_export_max_manual_retries_error" @enderror
                                           value="{{ old('audit_export_max_manual_retries', $settings['audit_export_max_manual_retries'] ?? 3) }}" min="0" max="10">
                                    <span class="input-group-text bg-body-tertiary">/ export</span>
                                </div>
                                @error('audit_export_max_manual_retries')
                                    <div class="invalid-feedback d-block" id="audit_export_max_manual_retries_error">{{ $message }}</div>
                                @enderror
                            </div>

                            {{-- Phase 12 P12-2: these three were literals in
                                 AppServiceProvider until then. Not wrong —
                                 unreachable, which for the mail one means an
                                 admin cannot raise a relay ceiling during an
                                 incident without a deploy. --}}
                            <div class="col-12">
                                <h6 class="text-body-secondary text-uppercase small fw-semibold mb-0 mt-1">User Management</h6>
                            </div>

                            <div class="col-md-6">
                                <label for="user_state_actions_rate_limit_per_minute" class="form-label">
                                    State Actions
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Lock, unlock, activate and deactivate actions per minute, per operator."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="user_state_actions_rate_limit_per_minute" id="user_state_actions_rate_limit_per_minute"
                                           class="form-control form-control-sm @error('user_state_actions_rate_limit_per_minute') is-invalid @enderror" @error('user_state_actions_rate_limit_per_minute') aria-invalid="true" aria-describedby="user_state_actions_rate_limit_per_minute_error" @enderror
                                           value="{{ old('user_state_actions_rate_limit_per_minute', $settings['user_state_actions_rate_limit_per_minute'] ?? 15) }}" min="1" max="120">
                                    <span class="input-group-text bg-body-tertiary">/ min</span>
                                </div>
                                @error('user_state_actions_rate_limit_per_minute')
                                    <div class="invalid-feedback d-block" id="user_state_actions_rate_limit_per_minute_error">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label for="bulk_action_rate_limit_per_minute" class="form-label">
                                    Bulk Actions
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Bulk activate, deactivate, lock and delete requests per minute, per operator."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="bulk_action_rate_limit_per_minute" id="bulk_action_rate_limit_per_minute"
                                           class="form-control form-control-sm @error('bulk_action_rate_limit_per_minute') is-invalid @enderror" @error('bulk_action_rate_limit_per_minute') aria-invalid="true" aria-describedby="bulk_action_rate_limit_per_minute_error" @enderror
                                           value="{{ old('bulk_action_rate_limit_per_minute', $settings['bulk_action_rate_limit_per_minute'] ?? 5) }}" min="1" max="120">
                                    <span class="input-group-text bg-body-tertiary">/ min</span>
                                </div>
                                @error('bulk_action_rate_limit_per_minute')
                                    <div class="invalid-feedback d-block" id="bulk_action_rate_limit_per_minute_error">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12">
                                <h6 class="text-body-secondary text-uppercase small fw-semibold mb-0 mt-1">Notifications</h6>
                            </div>

                            <div class="col-md-6">
                                <label for="send_test_mail_rate_limit_per_minute" class="form-label">
                                    Test Mail
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Test messages per minute, counted per operator, IP and recipient address — so one account cannot spray many addresses."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="send_test_mail_rate_limit_per_minute" id="send_test_mail_rate_limit_per_minute"
                                           class="form-control form-control-sm @error('send_test_mail_rate_limit_per_minute') is-invalid @enderror" @error('send_test_mail_rate_limit_per_minute') aria-invalid="true" aria-describedby="send_test_mail_rate_limit_per_minute_error" @enderror
                                           value="{{ old('send_test_mail_rate_limit_per_minute', $settings['send_test_mail_rate_limit_per_minute'] ?? 5) }}" min="1" max="120">
                                    <span class="input-group-text bg-body-tertiary">/ min</span>
                                </div>
                                @error('send_test_mail_rate_limit_per_minute')
                                    <div class="invalid-feedback d-block" id="send_test_mail_rate_limit_per_minute_error">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12">
                                <h6 class="text-body-secondary text-uppercase small fw-semibold mb-0 mt-1">System Health</h6>
                            </div>

                            <div class="col-md-6">
                                <label for="health_rate_limit_per_minute" class="form-label">
                                    Health Endpoint
                                    <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Requests per minute to GET /api/v1/health, counted per IP address. That endpoint has no login, so an address is the only unit there is — raise this if monitors or app clients share one."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="health_rate_limit_per_minute" id="health_rate_limit_per_minute"
                                           class="form-control form-control-sm @error('health_rate_limit_per_minute') is-invalid @enderror" @error('health_rate_limit_per_minute') aria-invalid="true" aria-describedby="health_rate_limit_per_minute_error" @enderror
                                           value="{{ old('health_rate_limit_per_minute', $settings['health_rate_limit_per_minute'] ?? 120) }}" min="1" max="600">
                                    <span class="input-group-text bg-body-tertiary">/ min</span>
                                </div>
                                @error('health_rate_limit_per_minute')
                                    <div class="invalid-feedback d-block" id="health_rate_limit_per_minute_error">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            {{-- Sidebar Area --}}
            <div class="col-12 col-lg-4">

                {{-- Card 5: Identity & Account Policies --}}
                <div class="card border-0 shadow-sm mb-4" id="section-identity">
                    <div class="card-header bg-transparent border-bottom py-3">
                        <h5 class="card-title mb-0 fw-semibold">Identity &amp; Account Rules</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="allow_username_change" value="0">
                            <input class="form-check-input" type="checkbox" name="allow_username_change" id="allow_username_change"
                                @checked($settings['allow_username_change'] ?? true)>
                            <label class="form-check-label" for="allow_username_change">
                                Allow Username Change
                                <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Permits users to update their usernames from their profile page."></i>
                            </label>
                        </div>
                        <div class="mb-3">
                            <label for="username_change_cooldown_days" class="form-label">
                                Username Change Cooldown
                                <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Cooldown period required before a user can change their username again."></i>
                            </label>
                            <div class="input-group">
                                <input type="number" name="username_change_cooldown_days" id="username_change_cooldown_days"
                                       class="form-control form-control-sm @error('username_change_cooldown_days') is-invalid @enderror" @error('username_change_cooldown_days') aria-invalid="true" aria-describedby="username_change_cooldown_days_error" @enderror
                                       value="{{ old('username_change_cooldown_days', $settings['username_change_cooldown_days'] ?? 30) }}" min="0" max="365">
                                <span class="input-group-text bg-body-tertiary">days</span>
                            </div>
                            @error('username_change_cooldown_days')
                                <div class="invalid-feedback d-block" id="username_change_cooldown_days_error">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="allow_email_change" value="0">
                            <input class="form-check-input" type="checkbox" name="allow_email_change" id="allow_email_change"
                                @checked($settings['allow_email_change'] ?? true)>
                            <label class="form-check-label" for="allow_email_change">
                                Allow Email Change
                                <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Permits users to update their email addresses from their profile page."></i>
                            </label>
                        </div>
                        <div class="mb-3">
                            <label for="email_change_cooldown_days" class="form-label">
                                Email Change Cooldown
                                <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Cooldown period required before a user can change their email address again."></i>
                            </label>
                            <div class="input-group">
                                <input type="number" name="email_change_cooldown_days" id="email_change_cooldown_days"
                                       class="form-control form-control-sm @error('email_change_cooldown_days') is-invalid @enderror" @error('email_change_cooldown_days') aria-invalid="true" aria-describedby="email_change_cooldown_days_error" @enderror
                                       value="{{ old('email_change_cooldown_days', $settings['email_change_cooldown_days'] ?? 30) }}" min="0" max="365">
                                <span class="input-group-text bg-body-tertiary">days</span>
                            </div>
                            @error('email_change_cooldown_days')
                                <div class="invalid-feedback d-block" id="email_change_cooldown_days_error">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="mb-3">
                            <label for="email_verification_mode" class="form-label">
                                Email Verification Mode
                                <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Determines email verification strategy: Public (user self-verify), Admin-only, or Disabled."></i>
                            </label>
                            <select name="email_verification_mode" id="email_verification_mode"
                                    class="form-select form-select-sm @error('email_verification_mode') is-invalid @enderror" @error('email_verification_mode') aria-invalid="true" aria-describedby="email_verification_mode_error" @enderror>
                                <option value="public" {{ ($settings['email_verification_mode'] ?? 'public') === 'public' ? 'selected' : '' }}>Public</option>
                                <option value="admin" {{ ($settings['email_verification_mode'] ?? 'public') === 'admin' ? 'selected' : '' }}>Admin-only</option>
                                <option value="disabled" {{ ($settings['email_verification_mode'] ?? 'public') === 'disabled' ? 'selected' : '' }}>Disabled</option>
                            </select>
                            @error('email_verification_mode')
                                <div class="invalid-feedback d-block" id="email_verification_mode_error">{{ $message }}</div>
                            @enderror
                        </div>

                        <hr class="my-4">

                        <h6 class="text-uppercase text-muted small fw-semibold mb-3">Self-Registration</h6>
                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="registration_enabled" value="0">
                            <input class="form-check-input" type="checkbox" name="registration_enabled" id="registration_enabled"
                                @checked($settings['registration_enabled'] ?? false)>
                            <label class="form-check-label" for="registration_enabled">
                                Allow Self-Registration
                                <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Publishes a public sign-up form. New accounts still have to verify their email before they can log in."></i>
                            </label>
                        </div>
                        <div class="mb-3">
                            <label for="registration_default_role" class="form-label">
                                Default Registration Role
                                <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Role automatically assigned to new self-registered accounts."></i>
                            </label>
                            <select name="registration_default_role" id="registration_default_role"
                                    class="form-select form-select-sm @error('registration_default_role') is-invalid @enderror" @error('registration_default_role') aria-invalid="true" aria-describedby="registration_default_role_error" @enderror>
                                <option value="">No role</option>
                                @foreach ($roles as $roleName)
                                    <option value="{{ $roleName }}" {{ ($settings['registration_default_role'] ?? 'user') === $roleName ? 'selected' : '' }}>{{ $roleName }}</option>
                                @endforeach
                            </select>
                            @error('registration_default_role')
                                <div class="invalid-feedback d-block" id="registration_default_role_error">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="mb-3">
                            <label for="registration_rate_limit_per_minute" class="form-label">
                                Registration Rate Limit
                                <i class="bi bi-info-circle text-muted fs-7 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Sign-up attempts allowed per minute per IP. Keeps the form from being used to probe which email addresses are registered."></i>
                            </label>
                            <div class="input-group">
                                <input type="number" name="registration_rate_limit_per_minute" id="registration_rate_limit_per_minute"
                                       class="form-control form-control-sm @error('registration_rate_limit_per_minute') is-invalid @enderror" @error('registration_rate_limit_per_minute') aria-invalid="true" aria-describedby="registration_rate_limit_per_minute_error" @enderror
                                       value="{{ old('registration_rate_limit_per_minute', $settings['registration_rate_limit_per_minute'] ?? 3) }}" min="1" max="30">
                                <span class="input-group-text bg-body-tertiary">/ min</span>
                            </div>
                            @error('registration_rate_limit_per_minute')
                                <div class="invalid-feedback d-block" id="registration_rate_limit_per_minute_error">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                {{-- Card 6: Sticky Save Actions Card --}}
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
    @else
        {{-- settings.view without settings.manage. The editable form is NOT
             rendered: leaving inputs on screen and hiding only Save would let
             someone fill them in and discover on submit that nothing happened.
             Read-only means the values are visible and there is nothing to fill.
             The server re-checks regardless — see SystemSettingRequest. --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent border-bottom py-3 d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0 fw-semibold">System Settings</h5>
                <span class="badge bg-secondary-subtle text-secondary">Read-only</span>
            </div>
            <div class="card-body p-4">
                <p class="text-muted fs-6">
                    You can view these settings but not change them.
                </p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col" style="width: 40%">Setting</th>
                                <th scope="col">Current value</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (($settings ?? []) as $key => $value)
                                <tr>
                                    <td class="text-muted"><code>{{ $key }}</code></td>
                                    <td>{{ is_bool($value) ? ($value ? 'true' : 'false') : $value }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endcan

    <script @nonce>
        document.addEventListener('DOMContentLoaded', function () {
            const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

            // Toggle controls keep dependent numeric fields disabled.
            const dependentFields = [
                ['password_history_enabled', 'password_history_count'],
                ['password_expiry_enabled', 'password_expiry_days'],
                ['password_expiry_enabled', 'password_expiry_warn_days'],
                ['inactivity_lock_enabled', 'inactivity_lock_days'],
                ['inactivity_lock_grace_enabled', 'inactivity_lock_grace_days'],
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
