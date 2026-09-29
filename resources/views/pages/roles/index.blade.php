@extends('layouts.app', ['title' => $trashed ? 'Role Trash' : 'Roles'])

@section('content')
    <div class="content-header mb-3">
        <div class="d-flex justify-content-between align-items-start w-100">
            <div>
                <h1 class="page-title fw-bold">{{ $trashed ? 'Role Trash' : 'Roles' }}</h1>
                <p class="page-description text-muted fs-7 mb-0">
                    {{ $trashed
                        ? 'Trashed roles grant nothing. Restoring brings the permission set back but re-assigns nobody.'
                        : 'Manage roles and the permissions each one grants.' }}
                </p>
            </div>
            <ol class="breadcrumb float-sm-end mb-0">
                <li class="breadcrumb-item"><a href="{{ route('roles.index') }}">Roles</a></li>
                <li class="breadcrumb-item active">{{ $trashed ? 'Trash' : 'All Roles' }}</li>
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
        <ul class="nav nav-tabs card-header-tabs px-3 pt-3 mb-0" role="tablist">
            <li class="nav-item">
                <a class="nav-link {{ $trashed ? '' : 'active fw-semibold border-bottom border-primary border-2' }} px-3 py-2 d-flex align-items-center gap-2 border-0 bg-transparent"
                    href="{{ route('roles.index') }}">
                    All Roles
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ $trashed ? 'active fw-semibold border-bottom border-primary border-2' : '' }} px-3 py-2 d-flex align-items-center gap-2 border-0 bg-transparent"
                    href="{{ route('roles.index', ['trashed' => 1]) }}">
                    Trash <span
                        class="badge rounded-pill {{ $trashed ? 'bg-primary text-white' : 'bg-secondary-subtle text-secondary' }}">{{ $trashedCount }}</span>
                </a>
            </li>
        </ul>
        <div class="card-header bg-transparent border-bottom py-2">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                {{-- P6-D5 wraps this button in @can. It is not gated here because the
                     permission rows do not exist until P6-B1 seeds them, and @can on
                     a missing permission is always false — the button simply never
                     rendered. `ms-auto` pushes it right inside the justify-content-between
                     row, so it lands where users/index puts Create User. Hidden on the
                     trash tab: there is nothing to create into a trash listing. --}}
                @unless ($trashed)
                    <a href="{{ route('roles.create') }}"
                        class="btn btn-primary d-inline-flex align-items-center gap-2 ms-auto">
                        <i class="bi bi-plus-lg"></i> Create Role
                    </a>
                @endunless
            </div>
        </div>
        <div class="card-body p-4">
            {{-- Filters — same markup and placement as pages/users/index: the form
                 sits in the card body above the table, not in the card header.
                 `trashed` rides along as a hidden field so filtering or sorting the
                 trash does not bounce the reader back to the live list. --}}
            <form method="GET" class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                @if ($trashed)
                    <input type="hidden" name="trashed" value="1">
                @endif
                <input type="text" name="search" class="form-control form-control-sm" style="max-width: 280px"
                    placeholder="Search role name..." value="{{ $search }}">
                @error('search')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fas fa-search"></i> Filter
                </button>
                @if ($search !== '')
                    <a href="{{ route('roles.index', $trashed ? ['trashed' => 1] : []) }}"
                        class="btn btn-outline-secondary btn-sm">
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
                            <tr @if ($role->trashed()) class="table-secondary" @endif>
                                <td>{{ ($roles->currentPage() - 1) * $roles->perPage() + $loop->iteration }}</td>
                                <td>
                                    {{ $role->name }}
                                    {{-- A system role is not deletable, so the badge replaces the
                                         delete trigger rather than hiding a button the server
                                         would refuse anyway. --}}
                                    @if ($role->is_system)
                                        <x-ui.badge variant="info" text="System" />
                                    @endif
                                    @if ($role->trashed())
                                        <x-ui.badge variant="danger" text="Trashed" />
                                    @endif
                                </td>
                                <td>{{ $role->users_count ?? 0 }}</td>
                                <td>{{ $role->permissions_count ?? 0 }}</td>
                                <td>
                                    <div
                                        class="d-flex align-items-center justify-content-end gap-1 flex-wrap flex-md-nowrap">
                                        @if ($role->trashed())
                                            {{-- Trashed rows offer no Edit: the role grants nothing,
                                                 so there is nothing meaningful to change, and the
                                                 update endpoint resolves {role} through the global
                                                 scope and would 404 anyway. --}}
                                            <x-ui.confirm-action :action="route('roles.restore', $role->id)"
                                                method="POST" action-type="restore_role" :item-name="$role->name"
                                                label="Restore" title="Restore" class="btn btn-sm btn-outline-success">
                                                <i class="fas fa-rotate-left"></i>
                                            </x-ui.confirm-action>
                                            <x-ui.confirm-action :action="route('roles.force-delete', $role->id)"
                                                method="DELETE" action-type="force_delete_role"
                                                :item-name="$role->name" label="Permanent Delete"
                                                title="Permanent Delete" class="btn btn-sm btn-danger">
                                                <i class="fas fa-trash"></i>
                                            </x-ui.confirm-action>
                                        @else
                                            <a href="{{ route('roles.edit', $role) }}"
                                                class="btn btn-sm btn-outline-primary" title="Edit">
                                                <i class="fas fa-pen"></i>
                                            </a>
                                            @unless ($role->is_system)
                                                <x-ui.confirm-action :action="route('roles.destroy', $role)"
                                                    method="DELETE" action-type="delete_role"
                                                    :item-name="$role->name" label="Move to Trash"
                                                    title="Move to Trash"
                                                    class="btn btn-sm btn-outline-danger">
                                                    <i class="fas fa-trash"></i>
                                                </x-ui.confirm-action>
                                            @endunless
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    <i class="fas fa-inbox fa-2x text-muted mb-2 d-block"></i>
                                    {{ $trashed ? 'Trash is empty.' : 'No roles found.' }}
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
