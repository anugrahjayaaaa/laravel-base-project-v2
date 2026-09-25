@if ($showPasswordExpiryWarning ?? false)
    <div class="alert alert-warning alert-dismissible fade show mb-3" role="alert" id="password-expiry-warning">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <i class="fas fa-clock me-2"></i>
                <strong>Password Expiry Warning:</strong>
                @if (($passwordExpiryDaysRemaining ?? 0) === 1)
                    Your password expires tomorrow.
                @else
                    Your password expires in {{ $passwordExpiryDaysRemaining ?? 0 }} days.
                @endif
                <a href="{{ route('profile.show') }}#change-password" class="alert-link ms-1">Change now</a>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
        </div>
    </div>
@endif
