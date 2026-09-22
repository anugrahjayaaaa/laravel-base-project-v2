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

    <div class="callout callout-info mb-4">
        <div class="d-flex align-items-center gap-2 mb-1">
            <i class="bi bi-info-circle-fill text-info fs-5"></i>
            <h6 class="mb-0 fw-semibold">Temporary Password Policy</h6>
        </div>
        <p class="mb-0 text-secondary fs-7">
            System will automatically generate a secure 12-character Temporary Password and send it directly to the user's email address. The user will be required to change their password upon their first login.
        </p>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent border-bottom py-3">
            <h5 class="card-title mb-0 fw-semibold">Account Information</h5>
        </div>
        <form method="POST" action="{{ route('users.store') }}" id="createUserForm">
            @csrf
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="name" class="form-label">Name</label>
                        <input type="text" name="name" id="name"
                            class="form-control form-control-sm @error('name') is-invalid @enderror"
                            value="{{ old('name') }}" required maxlength="255" autofocus>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="username" class="form-label">Username</label>
                        <div class="input-group">
                            <span class="input-group-text">@</span>
                            <input type="text" name="username" id="username"
                                class="form-control form-control-sm @error('username') is-invalid @enderror"
                                value="{{ old('username') }}" required maxlength="50" autocomplete="username">
                        </div>
                        @error('username')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" name="email" id="email"
                            class="form-control form-control-sm @error('email') is-invalid @enderror"
                            value="{{ old('email') }}" required maxlength="255">
                        @error('email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="mt-3">
                    <label class="form-label">Roles</label>
                    <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                        @foreach (\Spatie\Permission\Models\Role::all() as $role)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="roles[]"
                                    value="{{ $role->name }}" id="role_{{ $loop->index }}"
                                    {{ old('roles', []) && in_array($role->name, old('roles')) ? 'checked' : '' }}>
                                <label class="form-check-label small" for="role_{{ $loop->index }}">
                                    {{ $role->name }}
                                </label>
                            </div>
                        @endforeach
                    </div>
                    @error('roles')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
            </div>
            <div class="card-footer bg-body-tertiary border-top py-3 d-flex justify-content-end align-items-center gap-2">
                <a href="{{ route('users.index') }}" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
                    <i class="bi bi-x-circle"></i> Cancel
                </a>
                <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-2">
                    <i class="bi bi-person-plus-fill"></i> Create User
                </button>
            </div>
        </form>
    </div>
@endsection
