@extends('layouts.app', ['title' => 'Create Role'])

@section('content')
    <div class="content-header mb-3">
        <div class="d-flex justify-content-between align-items-start w-100">
            <div>
                <h1 class="page-title fw-bold">Create Role</h1>
                <p class="page-description text-muted fs-7 mb-0">
                    <a href="{{ route('roles.index') }}" class="text-muted text-decoration-none"><i
                            class="fas fa-arrow-left me-1"></i>Back to Role List</a>
                </p>
            </div>
            <ol class="breadcrumb float-sm-end mb-0">
                <li class="breadcrumb-item"><a href="{{ route('roles.index') }}">Roles</a></li>
                <li class="breadcrumb-item active">Create Role</li>
            </ol>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
            <i class="fas fa-circle-exclamation me-1"></i>
            Please fix the errors below.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-8 col-12">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h5 class="card-title mb-0 fw-semibold">Role Details</h5>
                </div>
                <form method="POST" action="{{ route('roles.index') }}">
                    @csrf
                    <div class="card-body p-4">
                        <div class="mb-3">
                            <label for="name" class="form-label">Role Name</label>
                            <input type="text" name="name" id="name"
                                class="form-control form-control-sm @error('name') is-invalid @enderror"
                                value="{{ old('name') }}">
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        @include('partials.role-permission-matrix', [
                            'permissions' => $permissions,
                            'permissionGroups' => $permissionGroups,
                            'selectedPermissions' => [],
                        ])
                    </div>
                    <div class="card-footer bg-body-tertiary border-top py-3 d-flex justify-content-end align-items-center gap-2">
                        <a href="{{ route('roles.index') }}"
                            class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
                            <i class="bi bi-x-circle"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-2">
                            <i class="bi bi-check-lg"></i> Save
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-lg-4 col-12">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-2">
                    <h5 class="card-title mb-0 fw-semibold">How roles work</h5>
                </div>
                <div class="card-body p-4">
                    <div class="d-flex align-items-start gap-2 mb-3">
                        <i class="fas fa-shield-alt text-primary mt-1"></i>
                        <small class="text-muted">A user gets permissions through the roles assigned to them. Changing a
                            role changes access for every user holding it, immediately.</small>
                    </div>
                    <div class="d-flex align-items-start gap-2 mb-3">
                        <i class="fas fa-lock text-primary mt-1"></i>
                        <small class="text-muted">System roles: <strong>superadmin</strong>, <strong>admin</strong>,
                            <strong>user</strong>, cannot be renamed, deleted, or have their seeded permissions edited
                            from this page.</small>
                    </div>
                    <div class="d-flex align-items-start gap-2">
                        <i class="fas fa-user-check text-primary mt-1"></i>
                        <small class="text-muted">Permissions are not copied onto users. They stay derived from the
                            role, so one change here covers everyone.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
