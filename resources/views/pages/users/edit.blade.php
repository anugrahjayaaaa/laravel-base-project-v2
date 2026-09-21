@extends('layouts.app', ['title' => 'Edit User'])

@php
    $status = $user->getStatus();
    $badgeClass = match ($status->value) {
        \App\Enums\UserStatusEnum::ACTIVE->value => 'bg-success-subtle text-success border border-success-subtle',
        \App\Enums\UserStatusEnum::INACTIVE->value => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
        \App\Enums\UserStatusEnum::LOCKED->value => 'bg-warning-subtle text-warning border border-warning-subtle',
        \App\Enums\UserStatusEnum::PENDING_VERIFICATION->value => 'bg-warning-subtle text-dark border border-warning-subtle',
    };

    $initials = str($user->name)->explode(' ')->take(2)->map(fn($w) => strtoupper($w[0]))->implode('');

    $softDeleteModal =
        'data-bs-toggle="modal" data-bs-target="#confirmModal" ' .
        'data-action="' .
        route('users.destroy', $user) .
        '" data-method="DELETE" ' .
        'data-title="Delete User?" ' .
        'data-message="Move ' .
        e($user->name) .
        ' to trash? They can be restored later." ' .
        'data-variant="danger" data-label="Delete"';

    $restoreModal =
        'data-bs-toggle="modal" data-bs-target="#confirmModal" ' .
        'data-action="' .
        route('users.restore', $user) .
        '" data-method="POST" ' .
        'data-title="Restore User?" ' .
        'data-message="Restore ' .
        e($user->name) .
        '? They will be reactivated." ' .
        'data-variant="info" data-label="Restore"';

    $forceDeleteModal =
        'data-bs-toggle="modal" data-bs-target="#confirmModal" ' .
        'data-action="' .
        route('users.force-delete', $user) .
        '" data-method="DELETE" ' .
        'data-title="Permanently Delete?" ' .
        'data-message="This cannot be undone. ' .
        e($user->name) .
        ' will be permanently removed." ' .
                'data-variant="danger" data-icon="bi-trash3" data-label="Permanent Delete"';

    $activateModal =
        'data-bs-toggle="modal" data-bs-target="#confirmModal" ' .
        'data-action="' .
        route('users.activate', $user) .
        '" data-method="POST" ' .
        'data-title="Activate User Account?" ' .
        'data-message="Are you sure you want to activate ' .
        e($user->name) .
        '? This will restore the user login access to the system." ' .
        'data-variant="success" data-label="Activate"';

    $deactivateModal =
        'data-bs-toggle="modal" data-bs-target="#confirmModal" ' .
        'data-action="' .
        route('users.deactivate', $user) .
        '" data-method="POST" ' .
        'data-title="Deactivate User Account?" ' .
        'data-message="Are you sure you want to deactivate ' .
        e($user->name) .
        '? This user will be immediately logged out and unable to access the system until reactivated." ' .
        'data-variant="warning" data-label="Deactivate"';

    $lockModal =
        'data-bs-toggle="modal" data-bs-target="#confirmModal" ' .
        'data-action="' .
        route('users.lock', $user) .
        '" data-method="POST" ' .
        'data-title="Lock User Account?" ' .
        'data-message="Are you sure you want to lock ' .
        e($user->name) .
        '? The account will be forcefully locked and all active sessions will be revoked." ' .
        'data-variant="danger" data-label="Lock"';

    $unlockModal =
        'data-bs-toggle="modal" data-bs-target="#confirmModal" ' .
        'data-action="' .
        route('users.unlock', $user) .
        '" data-method="POST" ' .
        'data-title="Unlock User Account?" ' .
        'data-message="Are you sure you want to unlock ' .
        e($user->name) .
        '? The administrative lock will be removed, allowing normal access." ' .
        'data-variant="success" data-icon="bi-shield-check" data-label="Unlock"';
@endphp

@section('content')
    <div class="content-header mb-3">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <h1 class="page-title mb-1">Edit User</h1>
                <p class="page-description mb-0">
                    <a href="{{ route('users.index') }}" class="text-muted text-decoration-none"><i
                            class="fas fa-arrow-left me-1"></i>Back to Users</a>
                </p>
            </div>
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
        {{-- Main Content --}}
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <div class="avatar-sm rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
                            style="background: var(--lbp-primary, #6366f1); width: 36px; height: 36px; font-size: 0.85rem;">
                            {{ $initials }}
                        </div>
                        <div>
                            <strong>{{ $user->name }}</strong>
                            <small class="text-muted d-block">Registered {{ $user->created_at->format('Y-m-d') }}</small>
                            <small class="text-muted d-block">Updated {{ $user->updated_at->diffForHumans() }}</small>
                        </div>
                    </div>
                    @if ($user->trashed())
                        <span class="badge bg-danger text-white">TRASHED</span>
                    @endif
                </div>
                <form method="POST" action="{{ route('users.update', $user) }}">
                    @csrf @method('PUT')
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="name" class="form-label">Name</label>
                                <input type="text" name="name" id="name"
                                    class="form-control form-control-sm @error('name') is-invalid @enderror"
                                    value="{{ old('name', $user->name) }}" required maxlength="255">
                                @error('name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" name="email" id="email"
                                    class="form-control form-control-sm @error('email') is-invalid @enderror"
                                    value="{{ old('email', $user->email) }}" required maxlength="255">
                                @if ($user->email_verified_at)
                                    <span class="badge bg-success-subtle text-success mt-1"><i
                                            class="fas fa-circle-check me-1"></i>Verified</span>
                                @else
                                    <span class="badge bg-warning-subtle text-warning mt-1"><i
                                            class="fas fa-circle-exclamation me-1"></i>Unverified</span>
                                @endif
                                @error('email')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="mb-0 mt-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" id="username" class="form-control form-control-sm"
                                value="{{ $user->username }}" disabled>
                            <small class="form-text text-muted">Username cannot be changed.</small>
                        </div>

                        <div class="mb-0 mt-3">
                            <label for="status" class="form-label">Status</label>
                            <select name="status" id="status"
                                class="form-select form-select-sm @error('status') is-invalid @enderror">
                                @foreach (\App\Enums\UserStatusEnum::cases() as $s)
                                    <option value="{{ $s->value }}"
                                        {{ old('status', $user->getStatus()->value) === $s->value ? 'selected' : '' }}>
                                        {{ $s->label() }}
                                    </option>
                                @endforeach
                            </select>
                            @error('status')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                <div class="card-body d-flex justify-content-end">
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i class="fas fa-save me-1"></i> Save Changes
                                    </button>
                                </div>
                                </form>
            </div>
        </div>

        {{-- Sidebar --}}
        <div class="col-lg-4">
            {{-- Quick Actions & Security --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header"><strong>Quick Actions</strong></div>
                <div class="card-body d-grid gap-2">
                    @if ($user->email_verified_at === null && !$user->trashed())
                        <div class="alert alert-warning d-flex align-items-center justify-content-between py-2 px-3 mb-2"
                            role="alert">
                            <div class="d-flex align-items-center me-2">
                                <i class="fas fa-circle-exclamation me-1"></i>
                                <span class="small">Email not verified.</span>
                            </div>
                            <button type="button" class="btn-close ms-2" data-bs-dismiss="alert"
                                aria-label="Close"></button>
                        </div>
                        <form method="POST" action="{{ route('users.resend-verification', $user) }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-outline-warning btn-sm w-100">
                                <i class="fas fa-envelope me-1"></i> Resend Verification
                            </button>
                        </form>
                    @endif

                    {{-- State Toggles --}}
                    @if (!$user->trashed())
                        @php $s = $user->getStatus(); @endphp
                        @if ($s->value === \App\Enums\UserStatusEnum::ACTIVE->value)
                            <button type="button" class="btn btn-outline-warning btn-sm w-100" {!! $deactivateModal !!}>
                                <i class="fas fa-user-slash me-1"></i> Deactivate
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm w-100" {!! $lockModal !!}>
                                <i class="fas fa-lock me-1"></i> Lock
                            </button>
                        @elseif ($s->value === \App\Enums\UserStatusEnum::INACTIVE->value)
                            <button type="button" class="btn btn-outline-success btn-sm w-100" {!! $activateModal !!}>
                                <i class="fas fa-user-check me-1"></i> Activate
                            </button>
                        @elseif ($s->value === \App\Enums\UserStatusEnum::LOCKED->value)
                            <button type="button" class="btn btn-outline-success btn-sm w-100" {!! $unlockModal !!}>
                                <i class="fas fa-lock-open me-1"></i> Unlock
                            </button>
                        @endif
                    @endif

                    <div class="d-flex align-items-center gap-2 py-1">
                        <i class="fas fa-lock text-muted"></i>
                        <div>
                            <small class="text-muted d-block">Failed Login Attempts</small>
                            <span class="fw-bold">{{ \App\Models\FailedLoginAttempt::where('user_id', $user->id)->sum('attempts') }}</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Danger Zone --}}
            <div class="card border-0 shadow-sm card-outline-danger bg-danger-subtle bg-opacity-10">
                <div class="card-header bg-white">
                    <strong class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i> Danger Zone</strong>
                </div>
                <div class="card-body d-grid gap-2">
                    @if ($user->trashed())
                        <button type="button" class="btn btn-outline-success btn-sm w-100" {!! $restoreModal !!}>
                            <i class="fas fa-rotate-left me-1"></i> Restore User
                        </button>
                        <button type="button" class="btn btn-danger btn-sm w-100" {!! $forceDeleteModal !!}>
                            <i class="fas fa-trash me-1"></i> Permanent Delete
                        </button>
                        <p class="text-muted small mt-1 mb-0">User is in trash. Restore or permanently delete.</p>
                    @else
                        <button type="button" class="btn btn-outline-danger btn-sm w-100" {!! $softDeleteModal !!}>
                            <i class="fas fa-trash me-1"></i> Delete
                        </button>
                        <p class="text-muted small mt-1 mb-0">Temporary delete. Can be restored.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
