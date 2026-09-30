@extends('layouts.app', ['title' => $trashed ? 'Role Trash' : 'Roles'])

@php
    // Same shape as users/index's $tabUrl: a tab switch carries the active search
    // instead of dropping it, so filtering then changing tab does not silently
    // reset the filter. `trashed => false` is filtered out below so the live tab
    // is a clean ?search=… rather than ?trashed=0.
    $tabUrl = function (bool $trashed) use ($search, $currentSort, $currentDirection) {
        $params = array_filter(
            [
                'search' => $search,
                'trashed' => $trashed ? 1 : null,
                'sort' => $currentSort,
                'direction' => $currentDirection,
            ],
            fn ($v) => $v !== '' && $v !== null,
        );

        return route('roles.index') . '?' . http_build_query($params);
    };
@endphp

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

    @include('layouts.partials.alerts')

    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
            <i class="fas fa-circle-check me-1"></i>
            {{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card border-0 shadow-sm mb-4">
        {{-- Card header — the tab strip and the Create button are the same two
             children of the same justify-content-between row as pages/users/index,
             copied literally. Three things had drifted and each one is visible:
             the tabs were a `nav-tabs` list OUTSIDE the card-header, so they sat
             above the border rather than inside it; `text-secondary fw-medium`
             was missing from the inactive pill, so it rendered in AdminLTE's
             default colour instead of the theme's muted token; and the button
             used `ms-auto` instead of being the row's second child, which
             collapses the gap when the tab strip is short. The `index-card-header`
             class is the shared hook in public/vendor/theme.css — same one
             users/index uses, so the active pill colour cannot drift apart. --}}
        <div class="card-header index-card-header bg-transparent border-bottom py-2">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex flex-nowrap overflow-auto pe-2" style="scrollbar-width: none;">
                    <ul class="nav nav-pills flex-nowrap overflow-auto pb-2 gap-2"
                        style="-webkit-overflow-scrolling: touch; scrollbar-width: none;">
                        <li class="nav-item">
                            <a class="nav-link {{ $trashed ? 'text-secondary fw-medium' : 'active fw-semibold border-bottom border-primary border-2' }} px-3 py-2 d-flex align-items-center gap-2 border-0 bg-transparent"
                                href="{{ $tabUrl(false) }}">
                                All Roles <span
                                    class="badge rounded-pill {{ $trashed ? 'bg-secondary-subtle text-secondary' : 'bg-primary text-white' }}">{{ $liveCount }}</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ $trashed ? 'active fw-semibold border-bottom border-primary border-2' : 'text-secondary fw-medium' }} px-3 py-2 d-flex align-items-center gap-2 border-0 bg-transparent"
                                href="{{ $tabUrl(true) }}">
                                Trash <span
                                    class="badge rounded-pill {{ $trashed ? 'bg-primary text-white' : 'bg-secondary-subtle text-secondary' }}">{{ $trashedCount }}</span>
                            </a>
                        </li>
                    </ul>
                </div>
                {{-- Gated as of P6-D. The note that used to sit here said it could
                     not be: "the permission rows do not exist until P6-B1 seeds
                     them, and @can on a missing permission is always false". P6-B1
                     shipped and seeded them, so the button has been rendering
                     ungated ever since — offered to anyone who could load the page,
                     and refused by the endpoint. --}}
                @can('roles.create')
                @unless ($trashed)
                    <a href="{{ route('roles.create') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                        <i class="bi bi-plus-lg"></i> Create Role
                    </a>
                @endunless
                @endcan
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
                @if ($search !== '' || $trashed)
                    <a href="{{ route('roles.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-times"></i> Clear
                    </a>
                @endif
                {{-- Bulk bar — same element, same id set, same position in the
                     filter row (after Clear, pushed right by ms-auto) as
                     pages/users/index, so the two lists behave identically.
                     The data-* attributes configure the shared bulk-actions.js:
                     a role's id field is role_ids[], its noun is "role", and a
                     live role offers only delete while a trashed one offers
                     restore / force_delete. `keys` points the confirm modal at
                     the role copy in action-config.js instead of the user copy
                     ("They can be restored later" is wrong for a role). --}}
                <div id="bulkBar" class="d-none align-items-center gap-2 flex-wrap ms-auto"
                    data-bulk-route="{{ route('roles.bulk-action') }}" data-bulk-field="role_ids[]"
                    data-bulk-noun="role" data-bulk-mixed="delete"
                    data-bulk-states='@json($trashed ? ['trashed' => ['restore', 'force_delete']] : ['active' => ['delete']])'
                    data-bulk-keys='@json(['delete' => 'delete_role', 'restore' => 'restore_role', 'force_delete' => 'force_delete_role'])'>
                    <span class="text-muted fs-7">Selected: <strong id="bulkCount">0</strong></span>
                    <select id="bulkAction" class="form-select form-select-sm d-inline-block" style="width:auto">
                        <option value="">-- Action --</option>
                        @can('roles.delete')<option value="delete">Move to Trash</option>@endcan
                        @can('roles.force_delete')<option value="force_delete">Permanent Delete</option>@endcan
                        @can('roles.restore')<option value="restore">Restore</option>@endcan
                    </select>
                    <button type="button" class="btn btn-sm btn-primary" id="bulkApplyBtn">Apply</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="bulkClearBtn">Clear</button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="width: 36px" class="align-middle">
                                <input type="checkbox" id="bulkSelectAll" aria-label="Select all roles">
                            </th>
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
                            <tr @if ($role->trashed()) style="background-color: color-mix(in srgb, var(--lbp-danger, #ef4444) 8%, transparent); " @endif>
                                <td>
                                    {{-- A system role has no delete, restore or force-delete
                                         action, so it is not selectable: a checkbox the
                                         server would reject is worse than no checkbox.
                                         The handler filters them too — this is convenience,
                                         not the boundary. --}}
                                    @if (! $role->is_system)
                                        <input type="checkbox" class="bulk-check" value="{{ $role->id }}"
                                            data-status="{{ $role->trashed() ? 'trashed' : 'active' }}"
                                            data-bs-toggle="tooltip" title="Select for bulk action">
                                    @endif
                                </td>
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
                                        <span class="badge bg-danger text-white ms-1">TRASHED</span>
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
                                            @can('roles.restore')
                                                <x-ui.confirm-action :action="route('roles.restore', $role->id)"
                                                    method="POST" action-type="restore_role" :item-name="$role->name"
                                                    label="Restore" title="Restore" class="btn btn-sm btn-outline-success">
                                                    <i class="fas fa-rotate-left"></i>
                                                </x-ui.confirm-action>
                                            @endcan
                                            @can('roles.force_delete')
                                                <x-ui.confirm-action :action="route('roles.force-delete', $role->id)"
                                                    method="DELETE" action-type="force_delete_role"
                                                    :item-name="$role->name" label="Permanent Delete"
                                                    title="Permanent Delete" class="btn btn-sm btn-danger">
                                                    <i class="fas fa-trash"></i>
                                                </x-ui.confirm-action>
                                            @endcan
                                        @else
                                            <a href="{{ route('roles.edit', $role) }}"
                                                class="btn btn-sm btn-outline-primary" title="Edit">
                                                <i class="fas fa-pen"></i>
                                            </a>
                                            {{-- System roles can never be trashed, so they
                                                 get no trigger at all. Two independent
                                                 reasons to hide a control: the row is not
                                                 deletable, and the caller may not delete. --}}
                                            @unless ($role->is_system)
                                                @can('roles.delete')
                                                    <x-ui.confirm-action :action="route('roles.destroy', $role)"
                                                        method="DELETE" action-type="delete_role"
                                                        :item-name="$role->name" label="Move to Trash"
                                                        title="Move to Trash"
                                                        class="btn btn-sm btn-outline-danger">
                                                        <i class="fas fa-trash"></i>
                                                    </x-ui.confirm-action>
                                                @endcan
                                            @endunless
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
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
