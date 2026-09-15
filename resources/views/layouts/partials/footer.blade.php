<footer class="app-footer border-top py-3 mt-auto">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center">
            <small class="text-muted">
                &copy; {{ date('Y') }} {{ config('app.name', 'Laravel Base Project') }}. All rights reserved.
            </small>
            @if (app('router')->has('profile.show'))
                <a href="{{ route('profile.show') }}" class="small text-muted text-decoration-none">
                    <i class="fas fa-shield-alt me-1"></i> Settings
                </a>
            @endif
        </div>
    </div>
</footer>
