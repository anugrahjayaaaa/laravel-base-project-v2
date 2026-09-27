@extends('layouts.app', ['title' => 'Create User'])

@section('content')
    <div class="content-header mb-3">
        <div class="d-flex justify-content-between align-items-start w-100">
            <div>
                <h1 class="page-title fw-bold">Create User</h1>
                <p class="page-description text-muted fs-7 mb-0">
                    <a href="{{ route('users.index') }}" class="text-muted text-decoration-none"><i
                            class="fas fa-arrow-left me-1"></i>Back to User List</a>
                </p>
            </div>
            <ol class="breadcrumb float-sm-end mb-0">
                <li class="breadcrumb-item"><a href="{{ route('users.index') }}">Users</a></li>
                <li class="breadcrumb-item active">Create User</li>
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

    <div class="alert alert-info mb-4">
        <div class="d-flex align-items-center gap-2 mb-1">
            <i class="bi bi-info-circle-fill text-info fs-5"></i>
            <h6 class="mb-0 fw-semibold">Temporary Password Policy</h6>
        </div>
        <p class="mb-0 text-secondary fs-7">
            System will automatically generate a secure 12-character Temporary Password and send it directly to the user's email address. The user will be required to change their password upon their first login.
        </p>
    </div>

    <div class="row g-4">
        {{-- Main Content --}}
        <div class="col-lg-8 col-12">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h5 class="card-title mb-0 fw-semibold">Account Information</h5>
                </div>
                <form method="POST" action="{{ route('users.store') }}" id="createUserForm">
                    @csrf
                    <div class="card-body p-4">
                        @include('partials.user-identity-fields', [
                            'user' => null,
                            'allowUsernameChange' => true,
                            'allowEmailChange' => true,
                        ])

                        @include('partials.user-role-picker', [
                            'roles' => $roles,
                            'selectedRoles' => [],
                        ])
                    </div>
                    <div class="card-footer bg-body-tertiary border-top py-3 d-flex justify-content-end align-items-center gap-2">
                        <a href="{{ route('users.index') }}"
                            class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
                            <i class="bi bi-x-circle"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-2">
                            <i class="bi bi-person-plus-fill"></i> Create User
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Right column --}}
        <div class="col-lg-4 col-12">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-2">
                    <h5 class="card-title mb-0 fw-semibold">What happens next</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-start gap-2 mb-3">
                        <i class="fas fa-key text-primary mt-1"></i>
                        <small class="text-muted">A 12-character temporary password is generated and emailed to the
                            address above. It is never shown on this page.</small>
                    </div>
                    <div class="d-flex align-items-start gap-2 mb-3">
                        <i class="fas fa-envelope text-primary mt-1"></i>
                        <small class="text-muted">A verification link is sent in the same message. The account stays
                            pending until the address is verified.</small>
                    </div>
                    <div class="d-flex align-items-start gap-2">
                        <i class="fas fa-user-lock text-primary mt-1"></i>
                        <small class="text-muted">The user must change the temporary password on their first login
                            before anything else is reachable.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
