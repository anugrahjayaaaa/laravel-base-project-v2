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
                                <label for="auth_login_max_attempts" class="form-label">
                                    Max Attempts
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Maximum number of failed login attempts allowed before an account is automatically locked."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="auth_login_max_attempts" id="auth_login_max_attempts"
                                           class="form-control form-control-sm @error('auth_login_max_attempts') is-invalid @enderror"
                                           value="{{ old('auth_login_max_attempts', $settings['auth_login_max_attempts'] ?? 5) }}" min="1" max="99">
                                    <span class="input-group-text bg-body-tertiary">attempts</span>
                                </div>
                                @error('auth_login_max_attempts')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-6 col-md-3">
                                <label for="auth_lockout_base_minutes" class="form-label">
                                    Lockout Duration
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="The initial lockout duration applied when the failed login threshold is reached."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="auth_lockout_base_minutes" id="auth_lockout_base_minutes"
                                           class="form-control form-control-sm @error('auth_lockout_base_minutes') is-invalid @enderror"
                                           value="{{ old('auth_lockout_base_minutes', $settings['auth_lockout_base_minutes'] ?? 5) }}" min="1" max="60">
                                    <span class="input-group-text bg-body-tertiary">min</span>
                                </div>
                                @error('auth_lockout_base_minutes')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-6 col-md-3">
                                <label for="auth_lockout_increment_minutes" class="form-label">
                                    Lockout Penalty
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Additional penalty duration added for each subsequent failed login attempt."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="auth_lockout_increment_minutes" id="auth_lockout_increment_minutes"
                                           class="form-control form-control-sm @error('auth_lockout_increment_minutes') is-invalid @enderror"
                                           value="{{ old('auth_lockout_increment_minutes', $settings['auth_lockout_increment_minutes'] ?? 10) }}" min="1" max="120">
                                    <span class="input-group-text bg-body-tertiary">min</span>
                                </div>
                                @error('auth_lockout_increment_minutes')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-6 col-md-3">
                                <label for="auth_login_rate_limit_per_minute" class="form-label">
                                    Login Rate Limit
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Maximum number of total login requests allowed per minute per IP address."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="auth_login_rate_limit_per_minute" id="auth_login_rate_limit_per_minute"
                                           class="form-control form-control-sm @error('auth_login_rate_limit_per_minute') is-invalid @enderror"
                                           value="{{ old('auth_login_rate_limit_per_minute', $settings['auth_login_rate_limit_per_minute'] ?? 5) }}" min="1" max="120">
                                    <span class="input-group-text bg-body-tertiary">/min</span>
                                </div>
                                @error('auth_login_rate_limit_per_minute')
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
                            <div class="col-md-4">
                                <label for="auth_password_min_length" class="form-label">
                                    Min Password Length
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Minimum character length required when creating or updating passwords."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="auth_password_min_length" id="auth_password_min_length"
                                           class="form-control form-control-sm @error('auth_password_min_length') is-invalid @enderror"
                                           value="{{ old('auth_password_min_length', $settings['auth_password_min_length'] ?? 8) }}" min="4" max="128">
                                    <span class="input-group-text bg-body-tertiary">chars</span>
                                </div>
                                @error('auth_password_min_length')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4">
                                <label for="auth_password_history_count" class="form-label">
                                    Password History
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Number of previous passwords remembered to prevent password reuse."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="auth_password_history_count" id="auth_password_history_count"
                                           class="form-control form-control-sm @error('auth_password_history_count') is-invalid @enderror"
                                           value="{{ old('auth_password_history_count', $settings['auth_password_history_count'] ?? 5) }}" min="0" max="24">
                                    <span class="input-group-text bg-body-tertiary">passwords</span>
                                </div>
                                @error('auth_password_history_count')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4">
                                <label for="auth_password_expiration_days" class="form-label">
                                    Password Expiration
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Number of days before passwords expire and require renewal (0 to disable)."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="auth_password_expiration_days" id="auth_password_expiration_days"
                                           class="form-control form-control-sm @error('auth_password_expiration_days') is-invalid @enderror"
                                           value="{{ old('auth_password_expiration_days', $settings['auth_password_expiration_days'] ?? 90) }}" min="1" max="365">
                                    <span class="input-group-text bg-body-tertiary">days</span>
                                </div>
                                @error('auth_password_expiration_days')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <hr class="my-3">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="auth_password_mixed_case" id="auth_password_mixed_case"
                                        {{ ($settings['auth_password_mixed_case'] ?? true) ? 'checked' : '' }}>
                                    <label for="auth_password_mixed_case" class="form-check-label">
                                        Mixed Case
                                        <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Enforces a combination of uppercase (A-Z) and lowercase (a-z) letters."></i>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="auth_password_numbers" id="auth_password_numbers"
                                        {{ ($settings['auth_password_numbers'] ?? true) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="auth_password_numbers">
                                        Numbers
                                        <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Enforces inclusion of at least one numeric digit (0-9)."></i>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="auth_password_symbols" id="auth_password_symbols"
                                        {{ ($settings['auth_password_symbols'] ?? true) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="auth_password_symbols">
                                        Symbols
                                        <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Enforces inclusion of at least one special character (e.g., @, #, $)."></i>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="auth_password_uncompromised" id="auth_password_uncompromised"
                                        {{ ($settings['auth_password_uncompromised'] ?? false) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="auth_password_uncompromised">
                                        Pwned Check
                                        <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Checks whether candidate passwords have been compromised in public data breaches via HaveIBeenPwned API."></i>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Card 3: Rate Limits & Expirations --}}
                <div class="card border-0 shadow-sm mb-4" id="section-password-reset">
                    <div class="card-header bg-transparent border-bottom py-3">
                        <h5 class="card-title mb-0 fw-semibold">Rate Limits &amp; Expirations</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="auth_password_forgot_rate_limit" class="form-label">
                                    Forgot Password Limit
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Maximum forgot password request submissions allowed per minute."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="auth_password_forgot_rate_limit" id="auth_password_forgot_rate_limit"
                                           class="form-control form-control-sm @error('auth_password_forgot_rate_limit') is-invalid @enderror"
                                           value="{{ old('auth_password_forgot_rate_limit', $settings['auth_password_forgot_rate_limit'] ?? 3) }}" min="1" max="30">
                                    <span class="input-group-text bg-body-tertiary">/min</span>
                                </div>
                                @error('auth_password_forgot_rate_limit')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="auth_password_reset_rate_limit" class="form-label">
                                    Reset Submit Limit
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Maximum password reset attempt executions allowed per minute."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="auth_password_reset_rate_limit" id="auth_password_reset_rate_limit"
                                           class="form-control form-control-sm @error('auth_password_reset_rate_limit') is-invalid @enderror"
                                           value="{{ old('auth_password_reset_rate_limit', $settings['auth_password_reset_rate_limit'] ?? 3) }}" min="1" max="30">
                                    <span class="input-group-text bg-body-tertiary">/min</span>
                                </div>
                                @error('auth_password_reset_rate_limit')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="auth_password_reset_token_expire_minutes" class="form-label">
                                    Reset Token Expiry
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Lifetime duration of a password reset link before it expires."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="auth_password_reset_token_expire_minutes" id="auth_password_reset_token_expire_minutes"
                                           class="form-control form-control-sm @error('auth_password_reset_token_expire_minutes') is-invalid @enderror"
                                           value="{{ old('auth_password_reset_token_expire_minutes', $settings['auth_password_reset_token_expire_minutes'] ?? 15) }}" min="1" max="1440">
                                    <span class="input-group-text bg-body-tertiary">min</span>
                                </div>
                                @error('auth_password_reset_token_expire_minutes')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="auth_email_verification_rate_limit" class="form-label">
                                    Verify Request Limit
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Maximum email verification resend requests allowed per hour."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="auth_email_verification_rate_limit" id="auth_email_verification_rate_limit"
                                           class="form-control form-control-sm @error('auth_email_verification_rate_limit') is-invalid @enderror"
                                           value="{{ old('auth_email_verification_rate_limit', $settings['auth_email_verification_rate_limit'] ?? 5) }}" min="1" max="100">
                                    <span class="input-group-text bg-body-tertiary">/hr</span>
                                </div>
                                @error('auth_email_verification_rate_limit')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="auth_verification_expire_minutes" class="form-label">
                                    Verify Link Expiry
                                    <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Lifetime duration of an email verification link before it expires."></i>
                                </label>
                                <div class="input-group">
                                    <input type="number" name="auth_verification_expire_minutes" id="auth_verification_expire_minutes"
                                           class="form-control form-control-sm @error('auth_verification_expire_minutes') is-invalid @enderror"
                                           value="{{ old('auth_verification_expire_minutes', $settings['auth_verification_expire_minutes'] ?? 60) }}" min="1" max="1440">
                                    <span class="input-group-text bg-body-tertiary">min</span>
                                </div>
                                @error('auth_verification_expire_minutes')
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
                                {{ ($settings['allow_username_change'] ?? true) ? 'checked' : '' }}>
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
                                {{ ($settings['allow_email_change'] ?? true) ? 'checked' : '' }}>
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
                            <label for="auth_verification_mode" class="form-label">
                                Verification Mode
                                <i class="bi bi-info-circle text-muted fs-6 ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Determines the email verification strategy (Public, Admin, or Disabled)."></i>
                            </label>
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
        });
    </script>
@endsection