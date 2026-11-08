{{-- Phase 18 Group A (D-4): every URL and value arrives from the view model.
     The row is read-only here — editing a tier is a page of its own, the same
     shape the users list uses. --}}
@extends('layouts.app', ['title' => 'Member Tiers'])

@section('content')
    @feature('member_loyalty')
        <div class="content-header mb-3">
            <div class="d-flex justify-content-between align-items-start w-100">
                <div>
                    <h1 class="page-title fw-bold">Member Tiers</h1>
                    <p class="page-description text-muted fs-7 mb-0">Price, duration, point multiplier and the
                        role applied when a subscription starts.</p>
                </div>
                <ol class="breadcrumb float-sm-end mb-0">
                    <li class="breadcrumb-item"><a href="{{ $indexUrl }}">Members</a></li>
                    <li class="breadcrumb-item active">Tiers</li>
                </ol>
            </div>
        </div>

        @include('layouts.partials.alerts')

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header index-card-header bg-transparent border-bottom py-2">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    {{-- Trash needs its own tab: this list used to read the default
                         scope, so a tier moved to the trash vanished with no way back. --}}
                    <div class="d-flex flex-nowrap overflow-auto pe-2" style="scrollbar-width: none;">
                        <ul class="nav nav-pills flex-nowrap overflow-auto pb-2 gap-2"
                            style="-webkit-overflow-scrolling: touch; scrollbar-width: none;">
                            @foreach ($tabs as $tab)
                                <li class="nav-item">
                                    <a class="nav-link {{ $tab->active ? 'active fw-semibold border-bottom border-primary border-2' : 'text-secondary fw-medium' }} px-3 py-2 d-flex align-items-center gap-2 border-0 bg-transparent"
                                        href="{{ $tab->url }}">
                                        {{ $tab->label }} <span
                                            class="badge rounded-pill {{ $tab->active ? 'bg-primary text-white' : 'bg-secondary-subtle text-secondary' }}">{{ $tab->count }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    @can('member_tiers.create')
                        <a href="{{ $createUrl }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                            <i class="bi bi-plus-lg"></i> Create Tier
                        </a>
                    @endcan
                </div>
            </div>
            <div class="card-body p-4">
                {{-- Filters — same shape as the members list: the current tab
                     rides along as a hidden input so searching stays inside the
                     tab, and the bulk bar sits at the right end of this row. --}}
                <form method="GET" action="{{ $tiersUrl }}" class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                    <input type="hidden" name="status" value="{{ $filters['status'] ?? '' }}">
                    <input type="text" name="search" class="form-control form-control-sm"
                        style="max-width: 280px" placeholder="Search tier name or slug..."
                        value="{{ $filters['search'] ?? '' }}" aria-label="Search tiers">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filter</button>
                    @if (! empty($filters['search']))
                        <a href="{{ $tiersUrl }}" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    @endif
                    @if ($bulkUrl)
                        <div id="bulkBar" class="d-none align-items-center gap-2 flex-wrap ms-auto"
                            data-bulk-route="{{ $bulkUrl }}" data-bulk-field="tier_ids[]" data-bulk-noun="tier"
                            data-bulk-mixed="delete" data-bulk-states='@json($bulkStates)'
                            data-bulk-keys='@json($bulkKeys)'>
                            <span class="text-muted fs-7">Selected: <strong id="bulkCount">0</strong></span>
                            <select id="bulkAction" class="form-select form-select-sm d-inline-block" style="width:auto">
                                <option value="">-- Action --</option>
                                {{-- One permission per action, the way the users and roles bars
                                     do it: the endpoint maps the same pairs back, so this only
                                     stops the caller being offered something they would lose. --}}
                                @can('member_tiers.delete')<option value="delete">Move to Trash</option>@endcan
                                @can('member_tiers.restore')<option value="restore">Restore</option>@endcan
                                @can('member_tiers.force_delete')<option value="force_delete">Permanent Delete</option>@endcan
                            </select>
                            <button type="button" class="btn btn-sm btn-primary" id="bulkApplyBtn">Apply</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="bulkClearBtn">Clear</button>
                        </div>
                    @endif
                </form>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col" style="width: 36px" class="align-middle">
                                    @if ($bulkUrl)
                                        <input type="checkbox" id="bulkSelectAll" aria-label="Select all tiers">
                                    @endif
                                </th>
                                <th scope="col">Name</th>
                                <th scope="col">Slug</th>
                                <th scope="col" class="text-end">Price</th>
                                <th scope="col" class="text-end">Duration (days)</th>
                                <th scope="col" class="text-end">Point Multiplier</th>
                                <th scope="col">Assigned Role</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($tiers as $tier)
                                <tr @if ($tier->is_trashed) style="background-color: color-mix(in srgb, var(--lbp-danger, #ef4444) 8%, transparent);" @endif>
                                    <td>
                                        @if ($bulkUrl)
                                            <input type="checkbox" class="bulk-check" value="{{ $tier->id }}"
                                                data-status="{{ $tier->is_trashed ? 'trashed' : ($tier->is_active ? 'active' : 'inactive') }}"
                                                data-bs-toggle="tooltip" title="Select for bulk action">
                                        @endif
                                    </td>
                                    <td class="fw-semibold text-body">{{ $tier->name }}</td>
                                    <td class="text-muted">{{ $tier->slug }}</td>
                                    {{-- Formatted by the Action: the symbol and the separators
                                         belong to the configured currency, not to this file. --}}
                                    <td class="text-end">{{ $tier->price_display }}</td>
                                    <td class="text-end">{{ $tier->duration_days }}</td>
                                    <td class="text-end">{{ $tier->point_multiplier }}</td>
                                    <td>{{ $tier->role_name ?? '—' }}</td>
                                    <td>
                                        <x-ui.badge :variant="$tier->is_active ? 'success' : 'neutral'"
                                            :text="$tier->is_active ? 'ACTIVE' : 'INACTIVE'" />
                                        @if ($tier->is_trashed)
                                            <span class="badge bg-danger text-white ms-1">DELETED</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex align-items-center gap-1">
                                            {{-- Every button is driven by its URL being
                                                 there or not: a trashed tier has no Edit
                                                 and no trash trigger, a live one no Restore
                                                 and no Permanent Delete. --}}
                                            @if ($tier->edit_url)
                                                @can('member_tiers.update')
                                                    <a href="{{ $tier->edit_url }}" class="btn btn-sm btn-outline-primary"
                                                        title="Edit"><i class="fas fa-pen"></i></a>
                                                @endcan
                                            @endif
                                            {{-- The state pair carries no `action-type`:
                                                ACTION_CONFIG's activate/deactivate copy speaks
                                                of logging in, which a tier never does. The
                                                modal gets the words that match the row. --}}
                                            @if ($tier->activate_url)
                                                @can('member_tiers.activate')
                                                    <x-ui.confirm-action :action="$tier->activate_url" method="POST"
                                                        :item-name="$tier->name" label="Activate" title="Activate"
                                                        variant="success"
                                                        message="Activate <b>__BOLD__</b>? New subscriptions can use it again."
                                                        class="btn btn-sm btn-outline-success">
                                                        <i class="fas fa-toggle-on"></i>
                                                    </x-ui.confirm-action>
                                                @endcan
                                            @endif
                                            @if ($tier->deactivate_url)
                                                @can('member_tiers.deactivate')
                                                    <x-ui.confirm-action :action="$tier->deactivate_url" method="POST"
                                                        :item-name="$tier->name" label="Deactivate" title="Deactivate"
                                                        message="Deactivate <b>__BOLD__</b>? Members already on it keep their subscription."
                                                        variant="warning" class="btn btn-sm btn-outline-warning">
                                                        <i class="fas fa-toggle-off"></i>
                                                    </x-ui.confirm-action>
                                                @endcan
                                            @endif
                                            @if ($tier->delete_url)
                                                @can('member_tiers.delete')
                                                    <x-ui.confirm-action :action="$tier->delete_url" method="DELETE"
                                                        action-type="delete" :item-name="$tier->name"
                                                        label="Move to Trash" title="Move to Trash"
                                                        class="btn btn-sm btn-outline-danger">
                                                        <i class="fas fa-trash"></i>
                                                    </x-ui.confirm-action>
                                                @endcan
                                            @endif
                                            @if ($tier->restore_url)
                                                @can('member_tiers.restore')
                                                    <x-ui.confirm-action :action="$tier->restore_url" method="POST"
                                                        action-type="restore" :item-name="$tier->name" label="Restore"
                                                        title="Restore" class="btn btn-sm btn-outline-success">
                                                        <i class="fas fa-rotate-left"></i>
                                                    </x-ui.confirm-action>
                                                @endcan
                                            @endif
                                            @if ($tier->force_delete_url)
                                                @can('member_tiers.force_delete')
                                                    <x-ui.confirm-action :action="$tier->force_delete_url"
                                                        method="DELETE" action-type="force_delete"
                                                        :item-name="$tier->name" label="Permanent Delete"
                                                        title="Permanent Delete" class="btn btn-sm btn-danger">
                                                        <i class="fas fa-trash"></i>
                                                    </x-ui.confirm-action>
                                                @endcan
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center py-4">
                                        <x-ui.empty-state message="No tiers configured yet." />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Pagination --}}
                <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                    @if ($tiers->total() > 0)
                        <small class="text-muted">
                            Showing {{ $tiers->firstItem() }} to {{ $tiers->lastItem() }} of
                            {{ $tiers->total() }} entries
                        </small>
                    @endif
                    <div class="d-flex">
                        {{ $tiers->links() }}
                    </div>
                </div>
            </div>
        </div>
    @endfeature
@endsection
