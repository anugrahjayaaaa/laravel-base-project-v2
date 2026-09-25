<form method="POST" action="{{ route('password.change.update') }}">
    @csrf
    @method('PUT')

    <div class="{{ $passwordChangeFieldsClass ?? '' }}">
        <div class="mb-3">
            <label for="current_password" class="form-label">Current Password</label>
            <div class="position-relative">
                <input type="password" name="current_password" id="current_password"
                    class="form-control pe-5 @error('current_password') is-invalid @enderror"
                    autocomplete="current-password" required>
                <button type="button"
                    class="btn btn-link text-muted text-decoration-none position-absolute top-50 translate-middle-y toggle-password p-0 border-0"
                    data-password-toggle="current_password" aria-label="Toggle password visibility"
                    tabindex="-1" style="right: 2rem; z-index: 5;">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
            @error('current_password')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">New Password</label>
            <div class="position-relative">
                <input type="password" name="password" id="password"
                    class="form-control pe-5 @error('password') is-invalid @enderror"
                    autocomplete="new-password" required>
                <button type="button"
                    class="btn btn-link text-muted text-decoration-none position-absolute top-50 translate-middle-y toggle-password p-0 border-0"
                    data-password-toggle="password" aria-label="Toggle password visibility"
                    tabindex="-1" style="right: 2rem; z-index: 5;">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
            @error('password')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
            <small class="text-muted d-block mt-1">Your new password cannot be one of your recent passwords.</small>
            @include('layouts.partials.password-strength')
        </div>

        <div class="{{ $passwordChangeConfirmationClass ?? 'mb-4' }}">
            <label for="password_confirmation" class="form-label">Confirm New Password</label>
            <div class="position-relative">
                <input type="password" name="password_confirmation" id="password_confirmation"
                    class="form-control pe-5 @error('password_confirmation') is-invalid @enderror"
                    autocomplete="new-password" required>
                <button type="button"
                    class="btn btn-link text-muted text-decoration-none position-absolute top-50 translate-middle-y toggle-password p-0 border-0"
                    data-password-toggle="password_confirmation" aria-label="Toggle password visibility"
                    tabindex="-1" style="right: 2rem; z-index: 5;">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
            @error('password_confirmation')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>
    </div>

    <div class="{{ $passwordChangeActionsClass ?? 'card-footer bg-body-tertiary border-top py-3 d-flex justify-content-end' }}">
        <button type="submit" class="btn btn-primary d-inline-flex align-items-center justify-content-center gap-2">
            <i class="bi bi-shield-lock"></i> Update Password
        </button>
    </div>
</form>
