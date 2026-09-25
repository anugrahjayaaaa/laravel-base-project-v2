@extends('layouts.auth', ['title' => 'Password Expired'])

@section('content')
<div class="auth-card mx-auto" style="max-width: 420px;">
    <div class="card">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <div class="auth-icon mb-3">
                    <i class="fas fa-shield-halved text-warning"></i>
                </div>
                <h1 class="page-title mb-1">Password Expired</h1>
                <p class="text-muted small mb-0">Your password has expired. Please set a new password to continue.</p>
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

            @include('partials.password-change-form', [
                'passwordChangeFieldsClass' => 'mb-0',
                'passwordChangeActionsClass' => 'd-grid mb-3',
            ])
        </div>
    </div>
</div>
@endsection
