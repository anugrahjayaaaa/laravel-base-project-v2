@extends('layouts.app', ['title' => 'Users'])

@php
    $currentSort = $filters['sort'] ?? 'created_at';
    $currentDir = $filters['direction'] ?? 'desc';
    $currentSearch = $filters['search'] ?? '';
    $currentStatus = $filters['status'] ?? '';

    $sortUrl = function (string $col) use ($currentSort, $currentDir, $currentSearch, $currentStatus) {
        $dir = ($currentSort === $col && $currentDir === 'asc') ? 'desc' : 'asc';
        $params = array_filter([
            'search' => $currentSearch,
            'status' => $currentStatus,
            'sort' => $col,
            'direction' => $dir,
        ], fn ($v) => $v !== '' && $v !== null);

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

    $badgeClass = function (\App\Enums\UserStatusEnum $s, bool $trashed) {
        if ($trashed) {
            return 'bg-danger text-white';
        }
        return match ($s->value) {
            \App\Enums\UserStatusEnum::ACTIVE->value => 'bg-success-subtle text-success border border-success-subtle',
            \App\Enums\UserStatusEnum::INACTIVE->value => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
            \App\Enums\UserStatusEnum::LOCKED->value => 'bg-warning-subtle text-warning border border-warning-subtle',
            \App\Enums\UserStatusEnum::PENDING_VERIFICATION->value => 'bg-warning-subtle text-dark border border-warning-subtle',
        };
    };

    $editBtn = function ($user) {
        return '<a href="' . route('users.show', $user) . '" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fas fa-pen"></i></a>';
    };

    $stateBtn = function ($user) {
        if ($user->trashed()) return '';
        $status = $user->getStatus();
        $btns = '';
        if ($status->value === \App\Enums\UserStatusEnum::ACTIVE->value) {
            $btns .= '<button type="button" class="btn btn-sm btn-outline-warning" title="Deactivate" data-bs-toggle="modal" data-bs-target="#confirmModal" data-action="' . route('users.deactivate', $user) . '" data-method="POST" data-title="Deactivate User Account?" data-message="Are you sure you want to deactivate ' . e($user->name) . '? This user will be immediately logged out and unable to access the system until reactivated." data-variant="warning" data-label="Deactivate"><i class="fas fa-user-slash"></i></button>';
            $btns .= '<button type="button" class="btn btn-sm btn-outline-danger" title="Lock Account" data-bs-toggle="modal" data-bs-target="#confirmModal" data-action="' . route('users.lock', $user) . '" data-method="POST" data-title="Lock User Account?" data-message="Are you sure you want to lock ' . e($user->name) . '? The account will be forcefully locked and all active sessions will be revoked." data-variant="danger" data-label="Lock"><i class="fas fa-lock"></i></button>';
        } elseif ($status->value === \App\Enums\UserStatusEnum::INACTIVE->value) {
            $btns .= '<button type="button" class="btn btn-sm btn-outline-success" title="Activate" data-bs-toggle="modal" data-bs-target="#confirmModal" data-action="' . route('users.activate', $user) . '" data-method="POST" data-title="Activate User Account?" data-message="Are you sure you want to activate ' . e($user->name) . '? This will restore the user login access to the system." data-variant="success" data-label="Activate"><i class="fas fa-user-check"></i></button>';
        } elseif ($status->value === \App\Enums\UserStatusEnum::LOCKED->value) {
            $btns .= '<button type="button" class="btn btn-sm btn-outline-success" title="Unlock Account" data-bs-toggle="modal" data-bs-target="#confirmModal" data-action="' . route('users.unlock', $user) . '" data-method="POST" data-title="Unlock User Account?" data-message="Are you sure you want to unlock ' . e($user->name) . '? The administrative lock will be removed, allowing normal access." data-variant="success" data-label="Unlock"><i class="fas fa-lock-open"></i></button>';
        }
        return $btns;
    };

    $deleteBtn = function ($user) {
        if ($user->trashed()) {
            return '<button type="button" class="btn btn-sm btn-outline-success" title="Restore" data-bs-toggle="modal" data-bs-target="#confirmModal" data-action="' . route('users.restore', $user) . '" data-method="POST" data-title="Restore User?" data-message="Restore ' . e($user->name) . '? They will be reactivated." data-variant="info" data-label="Restore"><i class="fas fa-rotate-left"></i></button>'
                . '<button type="button" class="btn btn-sm btn-outline-danger" title="Permanent Delete" data-bs-toggle="modal" data-bs-target="#confirmModal" data-action="' . route('users.force-delete', $user) . '" data-method="DELETE" data-title="Permanently Delete?" data-message="This cannot be undone. ' . e($user->name) . ' will be permanently removed." data-variant="danger" data-label="Permanent Delete"><i class="fas fa-trash"></i></button>';
        }
        return '<button type="button" class="btn btn-sm btn-outline-danger" title="Delete" data-bs-toggle="modal" data-bs-target="#confirmModal" data-action="' . route('users.destroy', $user) . '" data-method="DELETE" data-title="Delete User?" data-message="Move ' . e($user->name) . ' to trash? They can be restored later." data-variant="danger" data-label="Delete"><i class="fas fa-trash"></i></button>';
    };
@endphp

@section('content')
    <div class="content-header mb-3">
        <h1 class="page-title">Users</h1>
        <p class="page-description mb-0">Manage user accounts and status.</p>
    </div>

    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show mb-0" role="alert">
            <i class="fas fa-circle-check me-1"></i>
            {{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            {{-- Filters --}}
            <form method="GET" class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                <input type="text" name="search" class="form-control form-control-sm"
                       style="max-width: 250px"
                       placeholder="Search name or email..." value="{{ $currentSearch }}">
                <select name="status" class="form-select form-select-sm" style="width: auto;">
                    <option value="">All Status</option>
                    @foreach (\App\Enums\UserStatusEnum::cases() as $s)
                        <option value="{{ $s->value }}" {{ $currentStatus === $s->value ? 'selected' : '' }}>
                            {{ $s->label() }}
                        </option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fas fa-search"></i> Filter
                </button>
                @if ($currentSearch || $currentStatus)
                    <a href="{{ route('users.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-times"></i> Clear
                    </a>
                @endif
            </form>

            {{-- Table --}}
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width: 50px" class="align-middle">#</th>
                        <th style="cursor:pointer" class="align-middle" onclick="window.location.href='{{ $sortUrl('name') }}'">
                            Name{!! $sortIcon('name') !!}
                        </th>
                        <th style="cursor:pointer" class="align-middle" onclick="window.location.href='{{ $sortUrl('email') }}'">
                            Email{!! $sortIcon('email') !!}
                        </th>
                        <th class="align-middle">Status</th>
                        <th style="cursor:pointer" class="align-middle" onclick="window.location.href='{{ $sortUrl('created_at') }}'">
                            Created At{!! $sortIcon('created_at') !!}
                        </th>
                        <th style="width: 100px" class="align-middle">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr @if ($user->trashed()) style="background-color: color-mix(in srgb, var(--lbp-danger, #ef4444) 8%, transparent);" @endif>
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
                                <span class="badge {{ $badgeClass($status, $user->trashed()) }}">{{ $status->label() }}</span>
                            </td>
                            <td>{{ $user->created_at->format('Y-m-d') }}</td>
                            <td>
                                <div class="d-flex gap-1">
                                    {!! $editBtn($user) !!}
                                    {!! $stateBtn($user) !!}
                                    {!! $deleteBtn($user) !!}
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                <i class="fas fa-inbox fa-2x text-muted mb-2 d-block"></i>
                                No users found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

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