@extends('layouts.app', ['title' => 'Permissions'])

@section('content')
    <div class="content-header mb-3">
        <div class="d-flex justify-content-between align-items-start w-100">
            <div>
                <h1 class="page-title fw-bold">Permissions</h1>
                <p class="page-description text-muted fs-7 mb-0">Read-only catalogue of every permission the
                    application checks.</p>
            </div>
            <ol class="breadcrumb float-sm-end mb-0">
                <li class="breadcrumb-item"><a href="{{ route('roles.index') }}">Roles</a></li>
                <li class="breadcrumb-item active">Permissions</li>
            </ol>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Permissions</div>
                    <div class="fs-3 fw-bold lh-1">{{ $totalPermissions }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Resources</div>
                    <div class="fs-3 fw-bold lh-1">{{ $totalResources }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Roles</div>
                    <div class="fs-3 fw-bold lh-1">{{ $totalRoles }}</div>
                </div>
            </div>
        </div>
        {{-- The one metric worth acting on: a permission no role holds is either a
             capability nobody has been given yet, or a role that was never built. --}}
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Unused</div>
                    <div class="fs-3 fw-bold lh-1 {{ $unusedPermissions > 0 ? 'text-warning-emphasis' : '' }}">
                        {{ $unusedPermissions }}
                    </div>
                    <div class="text-muted small">No role holds these</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent border-bottom py-3">
            <h5 class="card-title mb-0 fw-semibold">All System Permissions</h5>
        </div>
        <div class="card-body p-4">
            {{-- Filter, laid out as in pages/users/index --}}
            <form method="GET" class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                <input type="text" name="search" class="form-control form-control-sm" style="max-width: 280px"
                    placeholder="Search permission or resource..." value="{{ $search }}">
                <input type="hidden" name="sort" value="{{ $currentSort }}">
                <input type="hidden" name="direction" value="{{ $currentDirection }}">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fas fa-search"></i> Filter
                </button>
                @if ($search)
                    <a href="{{ route('permissions.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-times"></i> Clear
                    </a>
                @endif
            </form>

            @if ($permissions->isEmpty())
                <x-ui.empty-state
                    :icon="$search ? 'fas fa-search' : 'fas fa-key'"
                    :message="$search ? 'No permission matches \''.$search.'\'.' : 'No permissions are defined.'" />
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <x-ui.sortable-th field="#" label="#" :sortable="false" />
                                <x-ui.sortable-th field="name" label="Permission" :current-sort="$currentSort"
                                    :current-direction="$currentDirection" />
                                <th class="align-middle">Resource</th>
                                <x-ui.sortable-th field="roles_count" label="Assigned Roles"
                                :sortable="false" />
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($permissions as $permission)
                                @php
                                    // Derived from the name, so the badge can never
                                    // disagree with the catalogue the seeder wrote.
                                    $resource = str($permission->name)->before('.')->value();
                                @endphp
                                <tr>
                                    <td>{{ ($permissions->currentPage() - 1) * $permissions->perPage() + $loop->iteration }}</td>
                                    <td><code>{{ $permission->name }}</code></td>
                                    <td><x-ui.badge variant="neutral" :text="$resource" /></td>
                                    <td>
                                        @forelse ($permission->roles as $role)
                                            <x-ui.badge variant="primary" :text="$role->name" class="me-1" />
                                        @empty
                                            <span class="text-muted">Unassigned</span>
                                        @endforelse
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Pagination: shared convention, see design-system.md §Pagination --}}
                <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                    @if ($permissions->total() > 0)
                        <small class="text-muted">
                            Showing {{ $permissions->firstItem() }} to {{ $permissions->lastItem() }} of {{ $permissions->total() }} entries
                        </small>
                    @endif
                    <div class="d-flex">
                        {{ $permissions->links() }}
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
