@extends('layouts.app', ['title' => 'Edit User'])

@section('content')
    <div class="content-header mb-3">
        <div class="d-flex justify-content-between align-items-start w-100">
            <div>
                <h1 class="page-title fw-bold">Edit User</h1>
                <p class="page-description text-muted fs-7 mb-0">
                    <a href="{{ route('users.index') }}" class="text-muted text-decoration-none"><i
                            class="fas fa-arrow-left me-1"></i>Back to User List</a>
                </p>
            </div>
            <ol class="breadcrumb float-sm-end mb-0">
                <li class="breadcrumb-item"><a href="{{ route('users.index') }}">Users</a></li>
                <li class="breadcrumb-item active">Edit User</li>
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
        {{-- Main Content --}}
        <div class="col-lg-8 col-12">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="avatar-sm rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
                            style="background: var(--lbp-primary, #6366f1); width: 40px; height: 40px; font-size: 0.9rem;">
                            {{ $user->initials() }}
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
                        @if ($user->trashed())
                            <span class="badge bg-danger text-white ms-auto">TRASHED</span>
                        @endif
                    </div>
                </div>
                {{-- Pending Email Callout — outside the user form: nested <form> tags are discarded by browsers, making the Cancel button submit the user form. Class matches profile/edit; .callout draws a 4px left border on gray, not this yellow. --}}
                @if ($user->pending_email)
                    <div class="alert alert-warning d-flex align-items-center justify-content-between mb-3">
                        <div>
                            <i class="bi bi-envelope-arrow-up me-2"></i>
                            <strong>Pending email change:</strong> {{ $user->pending_email }}
                        </div>
                        <form method="POST" action="{{ route('users.cancel-email-change', $user) }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-outline-secondary btn-sm">Cancel
                                Request</button>
                        </form>
                    </div>
                @endif
                <form method="POST" action="{{ route('users.update', $user) }}">
                    @csrf @method('PUT')
                    <div class="card-body p-4">
                        {{-- Shared with pages/users/create — see partials/user-identity-fields --}}
                        @include('partials.user-identity-fields', [
                            'user' => $user,
                            'allowUsernameChange' => $allowUsernameChange,
                            'allowEmailChange' => $allowEmailChange,
                        ])

                        {{-- Status --}}
                        <label for="status" class="form-label">Status</label>
                        <select name="status" id="status"
                            class="form-select form-select-sm @error('status') is-invalid @enderror">
                            @foreach ($statuses as $s)
                                <option value="{{ $s->value }}"
                                    {{ old('status', $user->getStatus()->value) === $s->value ? 'selected' : '' }}>
                                    {{ $s->label() }}
                                </option>
                            @endforeach
                        </select>
                        @error('status')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror

                        @include('partials.user-role-picker', [
                            'roles' => $roles,
                            'selectedRoles' => $user->getRoleNames()->toArray(),
                        ])
                    </div>
                    <div
                        class="card-footer bg-body-tertiary border-top py-3 d-flex justify-content-end align-items-center gap-2">
                        <a href="{{ route('users.index') }}"
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

        {{-- Right column --}}
        <div class="col-lg-4">
            {{-- Quick Actions & Security --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-transparent border-bottom py-2">
                    <h5 class="card-title mb-0 fw-semibold">Quick Actions</h5>
                </div>
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
                        {{-- Hidden in `disabled`: nobody may send, and offering a
                             button that is guaranteed to be refused is worse than
                             not offering it. The warning above stays, the
                             account is still unverified, which is a fact about
                             the account, not an action. --}}
                        @if ($canResendVerification)
                            <form method="POST" action="{{ route('users.resend-verification', $user) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-outline-warning btn-sm w-100">
                                    <i class="fas fa-envelope me-1"></i> Resend Verification
                                </button>
                            </form>
                        @endif
                    @endif

                    {{-- State Toggles --}}
                    @if (!$user->trashed())
                        @php $s = $user->getStatus(); @endphp
                        @if ($s->value === 'active')
                            <x-ui.confirm-action :action="route('users.deactivate', $user)" method="POST"
                                action-type="deactivate" :item-name="$user->name" label="Deactivate"
                                class="btn btn-outline-warning btn-sm w-100">
                                <i class="fas fa-user-slash me-1"></i> Deactivate
                            </x-ui.confirm-action>
                            <x-ui.confirm-action :action="route('users.lock', $user)" method="POST"
                                action-type="lock" :item-name="$user->name" label="Lock"
                                class="btn btn-outline-danger btn-sm w-100">
                                <i class="fas fa-lock me-1"></i> Lock
                            </x-ui.confirm-action>
                        @elseif ($s->value === 'inactive')
                            <x-ui.confirm-action :action="route('users.activate', $user)" method="POST"
                                action-type="activate" :item-name="$user->name" label="Activate"
                                class="btn btn-outline-success btn-sm w-100">
                                <i class="fas fa-user-check me-1"></i> Activate
                            </x-ui.confirm-action>
                        @elseif ($s->value === 'locked')
                            {{-- No action-type: this one keeps its own copy, the way it always has. --}}
                            <x-ui.confirm-action :action="route('users.unlock', $user)" method="POST"
                                :item-name="$user->name" label="Unlock" title="Unlock User Account?"
                                message="Are you sure you want to unlock {{ $user->name }}? The administrative lock will be removed, allowing normal access."
                                variant="success" icon="bi-shield-check"
                                class="btn btn-outline-success btn-sm w-100">
                                <i class="fas fa-lock-open me-1"></i> Unlock
                            </x-ui.confirm-action>
                        @endif
                    @endif

                    <div class="d-flex align-items-center gap-2 py-1">
                        <i class="fas fa-lock text-muted"></i>
                        <div>
                            <small class="text-muted d-block">Failed Login Attempts</small>
                            <span class="fw-bold">{{ $failedLoginCount }}</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Danger Zone --}}
            <div class="card border-0 shadow-sm card-outline-danger bg-danger-subtle bg-opacity-10">
                <div class="card-header bg-transparent border-bottom">
                    <h5 class="card-title mb-0 fw-semibold text-danger"><i class="fas fa-exclamation-triangle me-1"></i>
                        Danger Zone</h5>
                </div>
                <div class="card-body d-grid gap-2">
                    @if ($user->trashed())
                        <x-ui.confirm-action :action="route('users.restore', $user)" method="POST"
                            action-type="restore" :item-name="$user->name" label="Restore"
                            class="btn btn-outline-success btn-sm w-100">
                            <i class="fas fa-rotate-left me-1"></i> Restore User
                        </x-ui.confirm-action>
                        <x-ui.confirm-action :action="route('users.force-delete', $user)" method="DELETE"
                            action-type="force_delete" :item-name="$user->name" label="Permanent Delete"
                            class="btn btn-danger btn-sm w-100">
                            <i class="fas fa-trash me-1"></i> Permanent Delete
                        </x-ui.confirm-action>
                        <p class="text-muted small mt-1 mb-0">User is in trash. Restore or permanently delete.</p>
                    @else
                        <x-ui.confirm-action :action="route('users.destroy', $user)" method="DELETE"
                            action-type="delete" :item-name="$user->name" label="Delete"
                            class="btn btn-outline-danger btn-sm w-100">
                            <i class="fas fa-trash me-1"></i> Delete
                        </x-ui.confirm-action>
                        <p class="text-muted small mt-1 mb-0">Temporary delete. Can be restored.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
