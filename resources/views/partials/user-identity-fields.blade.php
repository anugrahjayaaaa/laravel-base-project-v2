{{--
    Shared identity fields for the create and edit user forms.

    Both pages render the same Name / Username / Email block, so it lives here
    once instead of drifting apart. The differences are the two switches:

      $user            null on create, the User model on edit
      $selectedRoles   roles to check; empty array on create

    Callers keep the <form> tag, the card, the submit button and the status
    field — those genuinely differ between the two pages.
--}}
@php
    $isEdit = isset($user);
    $usernameCooldown = $isEdit && ! $user->canChangeUsername()
        ? $user->username_changed_at?->copy()->addDays((int) $usernameCooldownDays)->format('Y-m-d')
        : null;
    $emailCooldown = $isEdit && ! $user->canChangeEmail()
        ? $user->email_changed_at?->copy()->addDays((int) $emailCooldownDays)->format('Y-m-d')
        : null;
@endphp

{{-- Name --}}
<div class="mb-3">
    <label for="name" class="form-label">Name</label>
    <input type="text" name="name" id="name"
        class="form-control form-control-sm @error('name') is-invalid @enderror"
        value="{{ old('name', $user->name ?? '') }}" required maxlength="255" @if(!$isEdit) autofocus @endif>
    @error('name')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

{{-- Username --}}
<div class="mb-3">
    <label for="username" class="form-label">Username</label>
    <input type="text" name="username" id="username"
        class="form-control form-control-sm @error('username') is-invalid @enderror"
        value="{{ old('username', $user->username ?? '') }}" @if(!$isEdit) required @endif maxlength="50"
        autocomplete="username"
        {{ !$allowUsernameChange || ($isEdit && ! $user->canChangeUsername()) ? 'disabled' : '' }}>
    @error('username')
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
    @if ($allowUsernameChange)
        @if ($isEdit && ! $user->canChangeUsername())
            <small class="text-muted"><i class="bi bi-clock-history me-1"></i>Username can be changed again on
                {{ $usernameCooldown }}.</small>
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
        value="{{ old('email', $user->email ?? '') }}" @if(!$isEdit) required @endif maxlength="255"
        {{ !$allowEmailChange || ($isEdit && ! $user->canChangeEmail()) ? 'disabled' : '' }}>
    @error('email')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
    @if ($isEdit)
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
    @endif
    @if ($allowEmailChange)
        @if ($isEdit && ! $user->canChangeEmail())
            <small class="text-muted"><i class="bi bi-clock-history me-1"></i>Email can be changed again on
                {{ $emailCooldown }}.</small>
        @else
            <small class="form-text text-muted">Email can be changed.</small>
        @endif
    @endif
</div>
