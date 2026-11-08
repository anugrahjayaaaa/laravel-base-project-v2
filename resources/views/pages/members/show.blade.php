{{-- Phase 18 Group A (D-4): every URL and value arrives from the view model;
     the member routes ship in B6. Delta display, badges and dates are
     pre-computed strings — the view renders, it does not calculate. --}}
@extends('layouts.app', ['title' => 'Member Detail'])

@section('content')
    @feature('member_loyalty')
        <div class="content-header mb-3">
            <div class="d-flex justify-content-between align-items-start w-100">
                <div>
                    <h1 class="page-title fw-bold">Member {{ $member->member_number }}</h1>
                    <p class="page-description text-muted fs-7 mb-0">
                        <a href="{{ $indexUrl }}" class="text-muted text-decoration-none">
                            <i class="fas fa-arrow-left me-1"></i>Back to Member List</a>
                    </p>
                </div>
                <ol class="breadcrumb float-sm-end mb-0">
                    <li class="breadcrumb-item"><a href="{{ $indexUrl }}">Members</a></li>
                    <li class="breadcrumb-item active">{{ $member->member_number }}</li>
                </ol>
            </div>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                <i class="fas fa-circle-exclamation me-1"></i>
                Please fix the errors below.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        <div class="row g-4">
            <div class="col-lg-8 col-12">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-transparent border-bottom py-3">
                        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                            <div class="d-flex flex-column align-items-start">
                                <h5 class="card-title mb-1 fw-semibold">
                                    {{ $member->user_name }}
                                </h5>

                                <div class="text-muted fs-7 mb-1 text-nowrap">
                                    {{ $member->user_email }}
                                </div>

                                @can('users.view')
                                    @if ($member->user_url)
                                        <a href="{{ $member->user_url }}"
                                            class="d-inline-flex align-items-center gap-1 fs-7 text-body text-decoration-none">
                                            <i class="fas fa-arrow-right"></i>
                                            View user account
                                        </a>
                                    @endif
                                @endcan
                            </div>
                            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                {{-- The list paints trashed rows red and stamps them DELETED;
                                     arriving here from a link or a URL must say the same thing,
                                     or an operator writes on a profile that is in the trash. --}}
                                @if ($member->is_trashed)
                                    <span class="badge bg-danger text-white">DELETED</span>
                                @endif
                                @if ($member->tier_name)
                                    <x-ui.badge :variant="$member->tier_variant" :text="$member->tier_name" />
                                @endif
                                <x-ui.badge :variant="$member->status_variant" :text="$member->status_label" />
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        @if ($member->notes)
                            <p class="mb-3">{{ $member->notes }}</p>
                        @endif

                        <h6 class="fw-semibold mb-2">Subscription History</h6>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">Tier</th>
                                        <th scope="col">Status</th>
                                        <th scope="col">Role</th>
                                        <th scope="col">Starts</th>
                                        <th scope="col">Expires</th>
                                        <th scope="col" class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($subscriptions as $subscription)
                                        <tr>
                                            <td>{{ $subscription->tier_name }}</td>
                                            <td><x-ui.badge :variant="$subscription->status_variant" :text="$subscription->status_label" /></td>
                                            <td><code>{{ $subscription->role_name }}</code></td>
                                            <td>{{ $subscription->starts_at }}</td>
                                            <td>{{ $subscription->expires_at }}</td>
                                            <td>
                                                <div class="d-flex align-items-center justify-content-end gap-1">
                                                    @can('members.update')
                                                        @if ($subscription->cancel_url)
                                                            <x-ui.confirm-action :action="$subscription->cancel_url" method="POST" :item-name="$subscription->tier_name"
                                                                label="Cancel Subscription" title="Cancel Subscription"
                                                                message="Cancel this subscription? The role applied by it is revoked when the change is confirmed."
                                                                variant="warning" class="btn btn-sm btn-outline-warning">
                                                                <i class="fas fa-ban"></i>
                                                            </x-ui.confirm-action>
                                                        @endif
                                                    @endcan
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center py-4">
                                                <x-ui.empty-state message="This member has never subscribed." />
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-transparent border-bottom py-3">
                        <h5 class="card-title mb-0 fw-semibold">Points Ledger</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">Date</th>
                                        <th scope="col">Reason</th>
                                        <th scope="col">Change</th>
                                        <th scope="col">Balance After</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($points as $entry)
                                        <tr>
                                            <td>{{ $entry->created_at }}</td>
                                            <td>{{ $entry->reason }}</td>
                                            <td><x-ui.badge :variant="$entry->delta_variant" :text="$entry->delta_display" /></td>
                                            <td>{{ $entry->balance_after }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-center py-4">
                                                <x-ui.empty-state message="No points issued or redeemed yet." />
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                {{-- No Adjust Points form by owner decision 2026-11-06: a balance
                     move belongs to the checkout that earned it (Phase 19), not to
                     a manual box on the profile. `POST /api/v1/members/{id}/points`
                     stays for programmatic corrections. --}}
            </div>

            <div class="col-lg-4 col-12">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-transparent border-bottom py-2">
                        <h5 class="card-title mb-0 fw-semibold">Membership</h5>
                    </div>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span class="text-muted">Points balance</span>
                            <span class="fw-semibold">{{ $member->points_balance }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span class="text-muted">Joined</span>
                            <span>{{ $member->joined_at }}</span>
                        </li>
                    </ul>
                </div>

                @can('members.update')
                    @if ($subscribeUrl)
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header bg-transparent border-bottom py-2">
                                <h5 class="card-title mb-0 fw-semibold">Activate Subscription</h5>
                            </div>
                            <form method="POST" action="{{ $subscribeUrl }}">
                                @csrf
                                <div class="card-body p-4">
                                    <div class="mb-3">
                                        <label for="member_tier_id" class="form-label">Tier</label>
                                        <select name="member_tier_id" id="member_tier_id"
                                            class="form-select @error('member_tier_id') is-invalid @enderror">
                                            <option value="">Select a tier...</option>
                                            @foreach ($tierOptions as $id => $label)
                                                <option value="{{ $id }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        @error('member_tier_id')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                                <div class="card-footer bg-body-tertiary border-top py-3 d-flex justify-content-end">
                                    <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-2">
                                        <i class="bi bi-check-lg"></i> Subscribe
                                    </button>
                                </div>
                            </form>
                        </div>
                    @endif
                @endcan

                {{-- The card is the button: no delete URL (a profile already in
                     the trash) means no card, not an empty box. `members.delete`
                     is the route's own gate, so the trigger only appears for a
                     caller who would not be refused by it. --}}
                @can('members.delete')
                    @if ($member->delete_url)
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header bg-transparent border-bottom py-2">
                                <h5 class="card-title mb-0 fw-semibold text-danger">Danger Zone</h5>
                            </div>
                            <div class="card-body p-4">
                                <p class="text-muted fs-7 mb-3">Moving a member to the trash takes the loyalty
                                    profile, tier and points history out of the list. The login account stays and
                                    is deleted on its own screen.</p>
                                <x-ui.confirm-action :action="$member->delete_url" method="DELETE"
                                    action-type="delete" :item-name="$member->member_number" label="Delete"
                                    title="Delete"
                                    class="btn btn-outline-danger d-inline-flex align-items-center gap-2">
                                    <i class="fas fa-trash"></i> Move to Trash
                                </x-ui.confirm-action>
                            </div>
                        </div>
                    @endif
                @endcan
            </div>
        </div>
    @endfeature
@endsection
