@extends('layouts.app', ['title' => 'Roles'])

@section('content')
    <div class="content-header mb-3">
        <div class="d-flex justify-content-between align-items-start w-100">
            <div>
                <h1 class="page-title fw-bold">Roles</h1>
                <p class="page-description text-muted fs-7 mb-0">Manage roles and the permissions each one grants.</p>
            </div>
            <ol class="breadcrumb float-sm-end mb-0">
                <li class="breadcrumb-item"><a href="{{ route('roles.index') }}">Roles</a></li>
                <li class="breadcrumb-item active">All Roles</li>
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
        <div class="card-header bg-transparent border-bottom py-2">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                {{-- P6-D5 wraps this button in @can. It is not gated here because the
                     permission rows do not exist until P6-B1 seeds them, and @can on
                     a missing permission is always false — the button simply never
                     rendered. `ms-auto` pushes it right inside the justify-content-between
                     row, so it lands where users/index puts Create User. --}}
                <a href="{{ route('roles.create') }}"
                    class="btn btn-primary d-inline-flex align-items-center gap-2 ms-auto">
                    <i class="bi bi-plus-lg"></i> Create Role
                </a>
            </div>
        </div>
        <div class="card-body p-4">
            {{-- Filters — same markup and placement as pages/users/index: the form
                 sits in the card body above the table, not in the card header. --}}
            <form method="GET" class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                <input type="text" name="search" class="form-control form-control-sm" style="max-width: 280px"
                    placeholder="Search role name..." value="{{ $search }}">
                @error('search')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fas fa-search"></i> Filter
                </button>
                @if ($search !== '')
                    <a href="{{ route('roles.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-times"></i> Clear
                    </a>
                @endif
            </form>

            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <x-ui.sortable-th field="#" label="#" :sortable="false" />
                            <x-ui.sortable-th field="name" label="Name" :current-sort="$currentSort"
                                :current-direction="$currentDirection" />
                            <x-ui.sortable-th field="users_count" label="Users" :sortable="false" />
                            <x-ui.sortable-th field="permissions_count" label="Permissions"
                                :sortable="false" />
                            <th class="align-middle text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($roles as $role)
                            <tr>
                                <td>{{ ($roles->currentPage() - 1) * $roles->perPage() + $loop->iteration }}</td>
                                <td>
                                    {{ $role->name }}
                                    {{-- A system role is not deletable, so the badge replaces the
                                         delete trigger rather than hiding a button the server
                                         would refuse anyway. --}}
                                    @if ($role->is_system)
                                        <x-ui.badge variant="info" text="System" />
                                    @endif
                                </td>
                                <td>{{ $role->users_count ?? 0 }}</td>
                                <td>{{ $role->permissions_count ?? 0 }}</td>
                                <td>
                                    <div
                                        class="d-flex align-items-center justify-content-end gap-1 flex-wrap flex-md-nowrap">
                                        <a href="{{ route('roles.edit', $role) }}"
                                            class="btn btn-sm btn-outline-primary" title="Edit">
                                            <i class="fas fa-pen"></i>
                                        </a>
                                        @unless ($role->is_system)
                                            <x-ui.confirm-action :action="route('roles.index')"
                                                method="DELETE" action-type="delete_role"
                                                :item-name="$role->name" label="Delete"
                                                title="Delete"
                                                class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash"></i>
                                            </x-ui.confirm-action>
                                        @endunless
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    <i class="fas fa-inbox fa-2x text-muted mb-2 d-block"></i>
                                    No roles found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                @if ($roles->total() > 0)
                    <small class="text-muted">
                        Showing {{ $roles->firstItem() }} to {{ $roles->lastItem() }} of {{ $roles->total() }} entries
                    </small>
                @endif
                <div class="d-flex">
                    {{ $roles->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
