{{-- Phase 18 Group A (D-4): every URL and value arrives from the view model.
     No route() here — the member routes ship in B6, and a Blade reference to
     a missing route name throws. B6's controllers supply these keys. --}}
@extends('layouts.app', ['title' => 'Members'])

@section('content')
    @feature('member_loyalty')
        <div class="content-header mb-3">
            <div class="d-flex justify-content-between align-items-start w-100">
                <div>
                    <h1 class="page-title fw-bold">Members</h1>
                    <p class="page-description text-muted fs-7 mb-0">Loyalty profiles, tiers and subscriptions.</p>
                </div>
                <ol class="breadcrumb float-sm-end mb-0">
                    <li class="breadcrumb-item"><a href="{{ $indexUrl }}">Members</a></li>
                    <li class="breadcrumb-item active">All Members</li>
                </ol>
            </div>
        </div>

        @include('layouts.partials.alerts')

        {{-- Summary on its own row, tier badges on the row below it: side by side
             left the badges stranded between the two labels. --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body py-3">
                <div class="d-flex flex-wrap align-items-center gap-4">
                    <span class="fw-semibold text-body">Summary</span>
                    @foreach ($summary as $row)
                        <span class="text-muted fs-7">{{ $row->label }}:
                            <span class="fw-semibold text-body">{{ $row->value }}</span></span>
                    @endforeach
                </div>
                <div class="d-flex flex-wrap align-items-center gap-3 mt-3">
                    <span class="fw-semibold text-body">Tier Badges</span>
                    @forelse ($tierBadges as $tier)
                        <span class="d-inline-flex align-items-center gap-1">
                            <x-ui.badge :variant="$tier->badge_variant" :text="$tier->name" />
                            <span class="text-muted fs-7">{{ $tier->count }}</span>
                        </span>
                    @empty
                        <span class="text-muted fs-7">No tiers configured yet.</span>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header index-card-header bg-transparent border-bottom py-2">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    {{-- The three tabs replace a single all-rows list, so the trash
                         is reachable. URLs and counts come from the view model —
                         a member Blade file builds no query string of its own. --}}
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
                    @can('members.manage')
                        {{-- createUrl is null until the write routes ship (B6):
                             no route, no button — never a link to nowhere. --}}
                        @if ($createUrl)
                            <a href="{{ $createUrl }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                                <i class="bi bi-person-plus-fill"></i> Create Member
                            </a>
                        @endif
                    @endcan
                </div>
            </div>
            <div class="card-body p-4">
                {{-- Filters --}}
                <form method="GET" action="{{ $indexUrl }}" class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                    {{-- Carries the current tab through a search: without it,
                         filtering jumps back to Active from the Trash tab. --}}
                    <input type="hidden" name="status" value="{{ $filters['status'] ?? '' }}">
                    <input type="text" name="search" class="form-control form-control-sm"
                        style="max-width: 280px" placeholder="Search member number or name..."
                        value="{{ $filters['search'] ?? '' }}" aria-label="Search members">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filter</button>
                    @if (! empty($filters['search']))
                        <a href="{{ $indexUrl }}" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    @endif
                    @if ($bulkUrl)
                        <div id="bulkBar" class="d-none align-items-center gap-2 flex-wrap ms-auto"
                            data-bulk-route="{{ $bulkUrl }}" data-bulk-field="member_ids[]"
                            data-bulk-noun="member" data-bulk-mixed="delete"
                            data-bulk-states='@json($bulkStates)'
                            data-bulk-keys='@json($bulkKeys)'>
                            <span class="text-muted fs-7">Selected: <strong id="bulkCount">0</strong></span>
                            <select id="bulkAction" class="form-select form-select-sm d-inline-block" style="width:auto">
                                <option value="">-- Action --</option>
                                @can('members.delete')<option value="delete">Move to Trash</option>@endcan
                                @can('members.restore')<option value="restore">Restore</option>@endcan
                                @can('members.force_delete')<option value="force_delete">Permanent Delete</option>@endcan
                            </select>
                            <button type="button" class="btn btn-sm btn-primary" id="bulkApplyBtn">Apply</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="bulkClearBtn">Clear</button>
                        </div>
                    @endif
                </form>

                {{-- Table --}}
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th scope="col" style="width: 36px" class="align-middle">
                                    @if ($bulkUrl)
                                        <input type="checkbox" id="bulkSelectAll" aria-label="Select all members">
                                    @endif
                                </th>
                                <x-ui.sortable-th field="#" label="#" :sortable="false" />
                                <x-ui.sortable-th field="member_number" label="Member No."
                                    :current-sort="$filters['sort'] ?? null"
                                    :current-direction="$filters['direction'] ?? null" />
                                <x-ui.sortable-th field="user_name" label="User"
                                    :current-sort="$filters['sort'] ?? null"
                                    :current-direction="$filters['direction'] ?? null" />
                                <x-ui.sortable-th field="tier_name" label="Tier"
                                    :current-sort="$filters['sort'] ?? null"
                                    :current-direction="$filters['direction'] ?? null" />
                                <x-ui.sortable-th field="status" label="Status"
                                    :current-sort="$filters['sort'] ?? null"
                                    :current-direction="$filters['direction'] ?? null" />
                                <x-ui.sortable-th field="points_balance" label="Points"
                                    :current-sort="$filters['sort'] ?? null"
                                    :current-direction="$filters['direction'] ?? null" />
                                <x-ui.sortable-th field="expires_at" label="Expires"
                                    :current-sort="$filters['sort'] ?? null"
                                    :current-direction="$filters['direction'] ?? null" />
                                <th scope="col" class="align-middle text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($members as $member)
                                <tr @if ($member->is_trashed) style="background-color: color-mix(in srgb, var(--lbp-danger, #ef4444) 8%, transparent);" @endif>
                                    <td>
                                        @if ($bulkUrl)
                                            <input type="checkbox" class="bulk-check" value="{{ $member->id }}"
                                                data-status="{{ $member->bulk_state }}"
                                                data-bs-toggle="tooltip" title="Select for bulk action">
                                        @endif
                                    </td>
                                    <td>{{ ($members->currentPage() - 1) * $members->perPage() + $loop->iteration }}</td>
                                    <td>
                                        <a href="{{ $member->show_url }}"
                                            class="text-body text-decoration-none fw-medium">{{ $member->member_number }}</a>
                                        @if ($member->is_trashed)
                                            <span class="badge bg-danger text-white ms-1">DELETED</span>
                                        @endif
                                    </td>
                                    <td>{{ $member->user_name }}</td>
                                    <td>
                                        @if ($member->tier_name)
                                            <x-ui.badge :variant="$member->tier_variant" :text="$member->tier_name" />
                                        @else
                                            <span class="text-muted">No tier</span>
                                        @endif
                                    </td>
                                    <td><x-ui.badge :variant="$member->status_variant" :text="$member->status_label" /></td>
                                    <td>{{ $member->points_balance }}</td>
                                    <td>{{ $member->expires_at ?? '—' }}</td>
                                    <td>
                                        <div class="d-flex align-items-center justify-content-end gap-1 flex-wrap flex-md-nowrap">
                                            <a href="{{ $member->show_url }}" class="btn btn-sm btn-outline-primary"
                                                title="View"><i class="fas fa-eye"></i></a>
                                            {{-- Same icon+tooltip shape as the users list,
                                and no `action-type`: ACTION_CONFIG's activate/
                                deactivate copy speaks of logging in, which this
                                switch does not do — it only leaves the programme. --}}
                                            @if ($member->activate_url)
                                                @can('members.activate')
                                                    <x-ui.confirm-action :action="$member->activate_url" method="POST"
                                                        :item-name="$member->member_number" label="Activate"
                                                        title="Activate" variant="success"
                                                        message="Bring <b>__BOLD__</b> back into the programme? The days they had paused are added back; nothing new is subscribed."
                                                        class="btn btn-sm btn-outline-success">
                                                        <i class="fas fa-user-check"></i>
                                                    </x-ui.confirm-action>
                                                @endcan
                                            @endif
                                            @if ($member->deactivate_url)
                                                @can('members.deactivate')
                                                    <x-ui.confirm-action :action="$member->deactivate_url" method="POST"
                                                        :item-name="$member->member_number" label="Deactivate"
                                                        title="Deactivate" variant="warning"
                                                        message="Take <b>__BOLD__</b> out of the programme? Say below whether this is a violation."
                                                        :deactivation-fields="true"
                                                        class="btn btn-sm btn-outline-warning">
                                                        <i class="fas fa-user-slash"></i>
                                                    </x-ui.confirm-action>
                                                @endcan
                                            @endif
                                            @if ($member->delete_url)
                                                @can('members.delete')
                                                    <x-ui.confirm-action :action="$member->delete_url"
                                                        method="DELETE" action-type="delete"
                                                        :item-name="$member->member_number" label="Move to Trash"
                                                        title="Move to Trash"
                                                        class="btn btn-sm btn-outline-danger">
                                                        <i class="fas fa-trash"></i>
                                                    </x-ui.confirm-action>
                                                @endcan
                                            @endif
                                            @if ($member->restore_url)
                                                @can('members.restore')
                                                    <x-ui.confirm-action :action="$member->restore_url" method="POST"
                                                        action-type="restore" :item-name="$member->member_number"
                                                        label="Restore" title="Restore"
                                                        class="btn btn-sm btn-outline-success">
                                                        <i class="fas fa-rotate-left"></i>
                                                    </x-ui.confirm-action>
                                                @endcan
                                            @endif
                                            @if ($member->force_delete_url)
                                                @can('members.force_delete')
                                                    <x-ui.confirm-action :action="$member->force_delete_url"
                                                        method="DELETE" action-type="force_delete"
                                                        :item-name="$member->member_number"
                                                        label="Permanent Delete" title="Permanent Delete"
                                                        class="btn btn-sm btn-danger">
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
                                        <x-ui.empty-state message="No members found." />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Pagination --}}
                <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                    @if ($members->total() > 0)
                        <small class="text-muted">
                            Showing {{ $members->firstItem() }} to {{ $members->lastItem() }} of
                            {{ $members->total() }} entries
                        </small>
                    @endif
                    <div class="d-flex">
                        {{ $members->links() }}
                    </div>
                </div>
            </div>
        </div>
    @endfeature
@endsection
