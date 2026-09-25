@if (auth()->check() && \App\Services\PasswordExpiry::shouldWarn(auth()->user()))
    <div class="alert alert-warning alert-dismissible fade show mb-3" role="alert" id="password-expiry-warning">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <i class="fas fa-clock me-2"></i>
                <strong>Password Expiry Warning:</strong>
                @if (\App\Services\PasswordExpiry::daysUntilExpiry(auth()->user()) === 1)
                    Your password expires tomorrow.
                @else
                    Your password expires in {{ \App\Services\PasswordExpiry::daysUntilExpiry(auth()->user()) }} days.
                @endif
                <a href="{{ route('password.change') }}" class="alert-link ms-1">Change now</a>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
        </div>
    </div>
    @push('scripts')
    <script>
        // Auto-dismiss after 5 seconds if user doesn't interact
        setTimeout(function () {
            const el = document.getElementById('password-expiry-warning');
            if (el) {
                const alert = bootstrap.Alert.getOrCreateInstance(el);
                alert.close();
            }
        }, 5000);
    </script>
    @endpush
@endif
