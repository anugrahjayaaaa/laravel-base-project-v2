@extends('layouts.app', ['title' => 'Users'])

@php
    $currentSort = $filters['sort'] ?? 'created_at';
    $currentDir = $filters['direction'] ?? 'desc';
    $currentSearch = $filters['search'] ?? '';
    $currentStatus = $filters['status'] ?? 'active';
    $counts = $counts ?? ['active' => 0, 'inactive' => 0, 'locked' => 0, 'trashed' => 0];

    $sortUrl = function (string $col) use ($currentSort, $currentDir, $currentSearch, $currentStatus) {
        $dir = $currentSort === $col && $currentDir === 'asc' ? 'desc' : 'asc';
        $params = array_filter(
            [
                'search' => $currentSearch,
                'status' => $currentStatus,
                'sort' => $col,
                'direction' => $dir,
            ],
            fn($v) => $v !== '' && $v !== null,
        );

        return route('users.index') . '?' . http_build_query($params);
    };

    $sortIcon = function (string $col) use ($currentSort, $currentDir) {
        if ($currentSort !== $col) {
            return '<i class="fas fa-sort text-muted opacity-50 ml-1"></i>';
        }
        return $currentDir === 'asc'
            ? '<i class="fas fa-sort-up text-primary ml-1"></i>'
            : '<i class="fas fa-sort-down text-primary ml-1"></i>';
    };

    $tabUrl = function (string $tab) use ($currentSearch, $currentSort, $currentDir) {
        $params = array_filter(
            [
                'search' => $currentSearch,
                'status' => $tab,
                'sort' => $currentSort,
                'direction' => $currentDir,
            ],
            fn($v) => $v !== '' && $v !== null,
        );

        return route('users.index') . '?' . http_build_query($params);
    };

    $editBtn = function ($user) {
        return '<a href="' .
            route('users.show', $user) .
            '" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fas fa-pen"></i></a>';
    };

    $stateBtn = function ($user) use ($currentStatus) {
        if ($user->trashed()) {
            return '';
        }
        $status = $user->getStatus();
        $btns = '';
        if ($currentStatus === 'active' && $status->value === 'active') {
            $btns .=
                '<button type="button" class="btn btn-sm btn-outline-warning" title="Deactivate" data-bs-toggle="modal" data-bs-target="#confirmModal" data-action="' .
                route('users.deactivate', $user) .
                '" data-method="POST" data-title="Deactivate User Account?" data-message="Are you sure you want to deactivate ' .
                e($user->name) .
                '? This user will be immediately logged out and unable to access the system until reactivated." data-variant="warning" data-label="Deactivate"><i class="fas fa-user-slash"></i></button>';
            $btns .=
                '<button type="button" class="btn btn-sm btn-outline-danger" title="Lock Account" data-bs-toggle="modal" data-bs-target="#confirmModal" data-action="' .
                route('users.lock', $user) .
                '" data-method="POST" data-title="Lock User Account?" data-message="Are you sure you want to lock ' .
                e($user->name) .
                '? The account will be forcefully locked and all active sessions will be revoked." data-variant="danger" data-label="Lock"><i class="fas fa-lock"></i></button>';
        } elseif ($currentStatus === 'inactive' && $status->value === 'inactive') {
            $btns .=
                '<button type="button" class="btn btn-sm btn-outline-success" title="Activate" data-bs-toggle="modal" data-bs-target="#confirmModal" data-action="' .
                route('users.activate', $user) .
                '" data-method="POST" data-title="Activate User Account?" data-message="Are you sure you want to activate ' .
                e($user->name) .
                '? This will restore the user login access to the system." data-variant="success" data-label="Activate"><i class="fas fa-user-check"></i></button>';
        } elseif ($currentStatus === 'locked' && $status->value === 'locked') {
            $btns .=
                '<button type="button" class="btn btn-sm btn-outline-success" title="Unlock Account" data-bs-toggle="modal" data-bs-target="#confirmModal" data-action="' .
                route('users.unlock', $user) .
                '" data-method="POST" data-title="Unlock User Account?" data-message="Are you sure you want to unlock ' .
                e($user->name) .
                '? The administrative lock will be removed, allowing normal access." data-variant="success" data-icon="bi-shield-check" data-label="Unlock"><i class="fas fa-lock-open"></i></button>';
        }
        return $btns;
    };

    $deleteBtn = function ($user) use ($currentStatus) {
        if ($user->trashed()) {
            return '<button type="button" class="btn btn-sm btn-outline-success" title="Restore" data-bs-toggle="modal" data-bs-target="#confirmModal" data-action="' .
                route('users.restore', $user) .
                '" data-method="POST" data-title="Restore User?" data-message="Restore ' .
                e($user->name) .
                '? They will be reactivated." data-variant="info" data-label="Restore"><i class="fas fa-rotate-left"></i></button>' .
                '<button type="button" class="btn btn-sm btn-danger" title="Permanent Delete" data-bs-toggle="modal" data-bs-target="#confirmModal" data-action="' .
                route('users.force-delete', $user) .
                '" data-method="DELETE" data-title="Permanently Delete?" data-message="This cannot be undone. ' .
                e($user->name) .
                ' will be permanently removed." data-variant="danger" data-icon="bi-trash3" data-label="Permanent Delete"><i class="fas fa-trash"></i></button>';
        }
        if ($currentStatus !== 'trashed') {
            return '<button type="button" class="btn btn-sm btn-outline-danger" title="Delete" data-bs-toggle="modal" data-bs-target="#confirmModal" data-action="' .
                route('users.destroy', $user) .
                '" data-method="DELETE" data-title="Delete User?" data-message="Move ' .
                e($user->name) .
                ' to trash? They can be restored later." data-variant="danger" data-label="Delete"><i class="fas fa-trash"></i></button>';
        }
        return '';
    };
@endphp

@section('content')
    <div class="content-header mb-3">
        <div class="d-flex justify-content-between align-items-start w-100">
            <div>
                <h1 class="page-title fw-bold">Users</h1>
                <p class="page-description text-muted fs-7 mb-0">Manage user accounts and status.</p>
            </div>
            <ol class="breadcrumb float-sm-end mb-0">
                <li class="breadcrumb-item"><a href="{{ route('users.index') }}">Users</a></li>
                <li class="breadcrumb-item active">All Users</li>
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
                <div class="d-flex flex-nowrap overflow-auto pe-2" style="scrollbar-width: none;">
                    <ul class="nav nav-pills flex-nowrap overflow-auto pb-2 gap-2"
                        style="-webkit-overflow-scrolling: touch; scrollbar-width: none;">
                        <li class="nav-item">
                            <a class="nav-link {{ $currentStatus === 'active' ? 'active fw-semibold border-bottom border-primary border-2' : 'text-secondary fw-medium' }} px-3 py-2 d-flex align-items-center gap-2 border-0 bg-transparent"
                                href="{{ $tabUrl('active') }}">
                                Active Users <span
                                    class="badge rounded-pill {{ $currentStatus === 'active' ? 'bg-primary text-white' : 'bg-secondary-subtle text-secondary' }}">{{ $counts['active'] }}</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ $currentStatus === 'inactive' ? 'active fw-semibold border-bottom border-primary border-2' : 'text-secondary fw-medium' }} px-3 py-2 d-flex align-items-center gap-2 border-0 bg-transparent"
                                href="{{ $tabUrl('inactive') }}">
                                Inactive <span
                                    class="badge rounded-pill {{ $currentStatus === 'inactive' ? 'bg-primary text-white' : 'bg-secondary-subtle text-secondary' }}">{{ $counts['inactive'] }}</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ $currentStatus === 'locked' ? 'active fw-semibold border-bottom border-primary border-2' : 'text-secondary fw-medium' }} px-3 py-2 d-flex align-items-center gap-2 border-0 bg-transparent"
                                href="{{ $tabUrl('locked') }}">
                                Locked <span
                                    class="badge rounded-pill {{ $currentStatus === 'locked' ? 'bg-primary text-white' : 'bg-secondary-subtle text-secondary' }}">{{ $counts['locked'] }}</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ $currentStatus === 'trashed' ? 'active fw-semibold border-bottom border-primary border-2' : 'text-secondary fw-medium' }} px-3 py-2 d-flex align-items-center gap-2 border-0 bg-transparent"
                                href="{{ $tabUrl('trashed') }}">
                                Trash <span
                                    class="badge rounded-pill {{ $currentStatus === 'trashed' ? 'bg-primary text-white' : 'bg-secondary-subtle text-secondary' }}">{{ $counts['trashed'] }}</span>
                            </a>
                        </li>
                    </ul>
                </div>
                <a href="{{ route('users.create') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                    <i class="bi bi-person-plus-fill"></i> Create User
                </a>
            </div>
        </div>
        <div class="card-body p-4">
            {{-- Filters --}}
            <form method="GET" class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                <input type="text" name="search" class="form-control form-control-sm" style="max-width: 280px"
                    placeholder="Search name or email..." value="{{ $currentSearch }}">
                @error('search')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fas fa-search"></i> Filter
                </button>
                @if ($currentSearch || $currentStatus !== 'active')
                    <a href="{{ route('users.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-times"></i> Clear
                    </a>
                @endif
                <div id="bulkBar" class="d-none align-items-center gap-2 flex-wrap ms-auto"
                    data-bulk-route="{{ route('users.bulk-action') }}">
                    <span class="text-muted fs-7">Selected: <strong id="bulkCount">0</strong></span>
                    <select id="bulkAction" class="form-select form-select-sm d-inline-block" style="width:auto">
                        <option value="">-- Action --</option>
                        <option value="delete">Move to Trash</option>
                        <option value="force_delete">Permanent Delete</option>
                        <option value="restore">Restore</option>
                        <option value="lock">Lock</option>
                        <option value="unlock">Unlock</option>
                        <option value="activate">Activate</option>
                        <option value="deactivate">Deactivate</option>
                    </select>
                    <button type="button" class="btn btn-sm btn-primary" id="bulkApplyBtn">Apply</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="bulkClearBtn">Clear</button>
                </div>
            </form>

            {{-- Table --}}
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="width: 36px" class="align-middle">
                                <input type="checkbox" id="bulkSelectAll" aria-label="Select all users">
                            </th>
                            <th style="width: 50px" class="align-middle">#</th>
                            <th style="cursor:pointer" class="align-middle"
                                onclick="window.location.href='{{ $sortUrl('name') }}'">
                                Name{!! $sortIcon('name') !!}
                            </th>
                            <th style="cursor:pointer" class="align-middle"
                                onclick="window.location.href='{{ $sortUrl('email') }}'">
                                Email{!! $sortIcon('email') !!}
                            </th>
                            <th class="align-middle">Status</th>
                            <th style="cursor:pointer" class="align-middle"
                                onclick="window.location.href='{{ $sortUrl('created_at') }}'">
                                Created At{!! $sortIcon('created_at') !!}
                            </th>
                            <th class="align-middle text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($users as $user)
                            <tr
                                @if ($user->trashed()) style="background-color: color-mix(in srgb, var(--lbp-danger, #ef4444) 8%, transparent); " @endif>
                                <td>
                                    <input type="checkbox" class="bulk-check" value="{{ $user->id }}"
                                        data-status="{{ $user->trashed() ? 'trashed' : $user->getStatus()->value }}"
                                        data-bs-toggle="tooltip" title="Select for bulk action">
                                </td>
                                <td>{{ ($users->currentPage() - 1) * $users->perPage() + $loop->iteration }}</td>
                                <td>
                                    {{ $user->name }}
                                    @if ($user->trashed())
                                        <span class="badge bg-danger text-white ms-1">DELETED</span>
                                    @endif
                                </td>
                                <td>{{ $user->email }}</td>
                                <td>
                                    @php $status = $user->getStatus(); @endphp
                                    <span
                                        class="badge {{ $badgeClass($status, $user->trashed()) }}">{{ $status->label() }}</span>
                                </td>
                                <td>{{ $user->created_at->format('Y-m-d') }}</td>
                                <td>
                                    <div
                                        class="d-flex align-items-center justify-content-end gap-1 flex-wrap flex-md-nowrap">
                                        {!! $editBtn($user) !!}
                                        {!! $stateBtn($user) !!}
                                        {!! $deleteBtn($user) !!}
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    <i class="fas fa-inbox fa-2x text-muted mb-2 d-block"></i>
                                    No users found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                @if ($users->total() > 0)
                    <small class="text-muted">
                        Showing {{ $users->firstItem() }} to {{ $users->lastItem() }} of {{ $users->total() }} entries
                    </small>
                @endif
                <div class="d-flex">
                    {{ $users->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
