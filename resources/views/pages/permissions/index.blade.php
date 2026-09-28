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

    <div class="alert alert-info mb-4">
        <div class="d-flex align-items-center gap-2 mb-1">
            <i class="bi bi-info-circle-fill text-info fs-5"></i>
            <h6 class="mb-0 fw-semibold">Permissions are defined in code</h6>
        </div>
        <p class="mb-0 text-secondary fs-7">
            A permission only matters if something checks it. They are seeded, not typed in here — a row nobody's
            permission check reads is a row that grants nothing.
        </p>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            @if (count($permissions) === 0)
                <x-ui.empty-state icon="fas fa-key" message="No permissions are defined." />
            @else
                @foreach ($permissionGroups as $resource => $groupPermissions)
                    <div class="mb-4">
                        <div class="text-uppercase small fw-semibold text-muted mb-2">{{ $resource }}</div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 50px" class="align-middle">#</th>
                                        <th class="align-middle">Permission</th>
                                        <th style="width: 140px" class="align-middle">Roles</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($groupPermissions as $permission)
                                        <tr>
                                            <td>{{ $loop->iteration }}</td>
                                            <td><code>{{ $permission->name }}</code></td>
                                            <td>
                                                <x-ui.badge :variant="$permission->roles_count > 0 ? 'success' : 'neutral'"
                                                    :text="(string) $permission->roles_count" />
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            @endif
        </div>
    </div>
@endsection
