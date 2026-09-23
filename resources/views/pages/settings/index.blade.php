@extends('layouts.app', ['title' => 'System Settings'])

@section('content')
    <div class="content-header mb-3">
        <div class="d-flex justify-content-between align-items-start w-100">
            <div>
                <h1 class="page-title fw-bold">System Settings</h1>
                <p class="page-description text-muted fs-7 mb-0">
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

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent border-bottom py-3">
            <h5 class="card-title mb-0 fw-semibold">User Management</h5>
        </div>
        <form method="POST" action="{{ route('settings.update') }}">
            @csrf
            <div class="card-body p-4">
                <div class="mb-3 form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="allow_username_change" id="allow_username_change"
                        {{ $settings['allow_username_change'] ? 'checked' : '' }}>
                    <label class="form-check-label" for="allow_username_change">
                        <strong>Allow username change</strong>
                    </label>
                    <div class="form-text">When enabled, users can change their username within cooldown limits.</div>
                </div>

                <div class="mb-3 form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="allow_email_change" id="allow_email_change"
                        {{ $settings['allow_email_change'] ? 'checked' : '' }}>
                    <label class="form-check-label" for="allow_email_change">
                        <strong>Allow email change</strong>
                    </label>
                    <div class="form-text">When enabled, users can request an email change via verification flow.</div>
                </div>

                <hr class="my-4">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="username_change_cooldown_days" class="form-label">Username cooldown (days)</label>
                        <input type="number" name="username_change_cooldown_days" id="username_change_cooldown_days"
                            class="form-control form-control-sm @error('username_change_cooldown_days') is-invalid @enderror"
                            value="{{ $settings['username_change_cooldown_days'] }}" min="1" max="365">
                        @error('username_change_cooldown_days')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="email_change_cooldown_days" class="form-label">Email cooldown (days)</label>
                        <input type="number" name="email_change_cooldown_days" id="email_change_cooldown_days"
                            class="form-control form-control-sm @error('email_change_cooldown_days') is-invalid @enderror"
                            value="{{ $settings['email_change_cooldown_days'] }}" min="1" max="365">
                        @error('email_change_cooldown_days')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
            <div class="card-footer bg-body-tertiary border-top py-3 d-flex justify-content-end align-items-center gap-2">
                <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
                    <i class="bi bi-x-circle"></i> Cancel
                </a>
                <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-2">
                    <i class="bi bi-check-lg"></i> Save Settings
                </button>
            </div>
        </form>
    </div>
@endsection