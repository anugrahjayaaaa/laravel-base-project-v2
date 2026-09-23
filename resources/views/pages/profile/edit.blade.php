@extends('layouts.app', ['title' => 'Edit Profile'])

@php
    $initials = str($user->name)->explode(' ')->take(2)->map(fn($w) => strtoupper($w[0]))->implode('');
@endphp

@section('content')
    <div class="content-header mb-3">
        <div class="d-flex justify-content-between align-items-start w-100">
            <div>
                <h1 class="page-title fw-bold">Edit Profile</h1>
                <p class="page-description text-muted fs-7 mb-0">
                    <a href="{{ route('dashboard') }}" class="text-muted text-decoration-none"><i
                            class="fas fa-arrow-left me-1"></i>Back to Dashboard</a>
                </p>
            </div>
            <ol class="breadcrumb float-sm-end mb-0">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active">Edit Profile</li>
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

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
            <i class="fas fa-circle-exclamation me-1"></i>
            Please fix the errors below.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="row g-4">
        {{-- Left column : Profile Information --}}
        <div class="col-lg-8 col-12">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="avatar-sm rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
                            style="background: var(--lbp-primary, #6366f1); width: 40px; height: 40px; font-size: 0.9rem;">
                            {{ $initials }}
                        </div>
                        <div>
                            <strong>{{ $user->name }}</strong>
                            <div>
                                <small class="text-muted d-block">Registered
                                    {{ $user->created_at->format('Y-m-d') }}</small>
                                <small class="text-muted d-block">Updated
                                    {{ $user->updated_at->diffForHumans() }}</small>
                            </div>
                        </div>
                    </div>
                </div>
                <form method="POST" action="{{ route('profile.update') }}">
                    @csrf @method('PUT')
                    <div class="card-body p-4">
                        {{-- Pending Email Callout --}}
                        @if ($user->pending_email)
                            <div class="callout callout-warning mb-3 d-flex align-items-center justify-content-between p-3">
                                <div>
                                    <i class="bi bi-envelope-arrow-up me-2"></i>
                                    <strong>Pending email change:</strong> {{ $user->pending_email }}
                                </div>
                                <form method="POST" action="{{ route('users.cancel-email-change', $user) }}"
                                    class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-secondary btn-sm">Cancel
                                        Request</button>
                                </form>
                            </div>
                        @endif

                        {{-- Name --}}
                        <div class="mb-3">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" name="name" id="name"
                                class="form-control form-control-sm @error('name') is-invalid @enderror"
                                value="{{ old('name', $user->name) }}" required maxlength="255">
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Username --}}
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" name="username" id="username"
                                class="form-control form-control-sm @error('username') is-invalid @enderror"
                                value="{{ old('username', $user->username) }}" maxlength="50"
                                {{ !$allowUsernameChange || !$user->canChangeUsername() ? 'disabled' : '' }}>
                            @error('username')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                            @if ($allowUsernameChange)
                                @if (!$user->canChangeUsername())
                                    <small class="text-muted"><i class="bi bi-clock-history me-1"></i>Username can be
                                        changed again on
                                        {{ $user->username_changed_at?->copy()->addDays((int) $usernameCooldownDays)->format('Y-m-d') }}.</small>
                                @else
                                    <small class="form-text text-muted">Username can be changed.</small>
                                @endif
                            @endif
                        </div>

                        {{-- Email --}}
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" name="email" id="email"
                                class="form-control form-control-sm @error('email') is-invalid @enderror"
                                value="{{ old('email', $user->email) }}" required maxlength="255"
                                {{ !$allowEmailChange || !$user->canChangeEmail() ? 'disabled' : '' }}>
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                                @if ($user->email_verified_at)
                                    <span class="badge bg-success-subtle text-success"><i
                                            class="fas fa-circle-check me-1"></i>Verified</span>
                                @else
                                    <span class="badge bg-warning-subtle text-warning"><i
                                            class="fas fa-circle-exclamation me-1"></i>Unverified</span>
                                @endif
                                @if ($user->pending_email)
                                    <span class="badge bg-info-subtle text-info"><i
                                            class="fas fa-envelope-circle-check me-1"></i>Pending:
                                        {{ $user->pending_email }}</span>
                                @endif
                            </div>
                            @if ($allowEmailChange)
                                @if (!$user->canChangeEmail())
                                    <small class="text-muted"><i class="bi bi-clock-history me-1"></i>Email can be
                                        changed again on
                                        {{ $user->email_changed_at?->copy()->addDays((int) $emailCooldownDays)->format('Y-m-d') }}.</small>
                                @else
                                    <small class="form-text text-muted">Email can be changed.</small>
                                @endif
                            @endif
                        </div>
                    </div>
                    <div
                        class="card-footer bg-body-tertiary border-top py-3 d-flex justify-content-end align-items-center gap-2">
                        <a href="{{ route('dashboard') }}"
                            class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
                            <i class="bi bi-x-circle"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-2">
                            <i class="bi bi-check-circle-fill"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Right Column --}}
        <div class="col-lg-4 col-12">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h5 class="card-title mb-0 fw-semibold"><i class="bi bi-shield-lock me-2"></i>Change Password</h5>
                </div>
                <form method="POST" action="{{ route('profile.update') }}">
                    @csrf @method('PUT')
                    <div class="card-body p-4">
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current Password</label>
                            <div class="position-relative">
                                <input type="password" name="current_password" id="current_password"
                                    class="form-control form-control-sm pe-5 @error('current_password') is-invalid @enderror"
                                    autocomplete="current-password">
                                <button type="button"
                                    class="btn btn-sm position-absolute top-50 end-0 translate-middle-y me-2 text-muted"
                                    data-password-toggle="current_password" aria-label="Toggle password visibility"
                                    tabindex="-1">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            @error('current_password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">New Password</label>
                            <div class="position-relative">
                                <input type="password" name="password" id="password"
                                    class="form-control form-control-sm pe-5 @error('password') is-invalid @enderror"
                                    autocomplete="new-password" minlength="8">
                                <button type="button"
                                    class="btn btn-sm position-absolute top-50 end-0 translate-middle-y me-2 text-muted"
                                    data-password-toggle="password" aria-label="Toggle password visibility"
                                    tabindex="-1">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="mb-3">
                            <label for="password_confirmation" class="form-label">Confirm New Password</label>
                            <div class="position-relative">
                                <input type="password" name="password_confirmation" id="password_confirmation"
                                    class="form-control form-control-sm pe-5" autocomplete="new-password">
                                <button type="button"
                                    class="btn btn-sm position-absolute top-50 end-0 translate-middle-y me-2 text-muted"
                                    data-password-toggle="password_confirmation" aria-label="Toggle password visibility"
                                    tabindex="-1">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer bg-body-tertiary border-top py-3 d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-2">
                            <i class="bi bi-key-fill"></i> Update Password
                        </button>
                    </div>
                </form>
            </div>

            {{-- Security Info Widget --}}
            <div class="callout callout-info mb-0">
                <div class="d-flex align-items-start gap-2">
                    <i class="fas fa-circle-info text-primary mt-1"></i>
                    <div>
                        <small class="text-muted">Use at least 8 characters with a mix of letters, numbers, and
                            symbols. Avoid reusing recent passwords.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
