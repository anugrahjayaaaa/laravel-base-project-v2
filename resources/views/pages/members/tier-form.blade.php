{{-- Phase 18 Group A (D-4): every URL and value arrives from the view model.
     One file serves create and edit — the field set is identical and the only
     difference is which row the Action writes, so a second copy would be a
     second place to forget a rule. --}}
@extends('layouts.app', ['title' => $tier ? 'Edit Tier' : 'Create Tier'])

@section('content')
    @feature('member_loyalty')
        <div class="content-header mb-3">
            <div class="d-flex justify-content-between align-items-start w-100">
                <div>
                    <h1 class="page-title fw-bold">{{ $tier ? 'Edit Tier' : 'Create Tier' }}</h1>
                    <p class="page-description text-muted fs-7 mb-0">
                        <a href="{{ $tiersUrl }}" class="text-muted text-decoration-none">
                            <i class="fas fa-arrow-left me-1"></i>Back to Tiers</a>
                    </p>
                </div>
                <ol class="breadcrumb float-sm-end mb-0">
                    <li class="breadcrumb-item"><a href="{{ $indexUrl }}">Members</a></li>
                    <li class="breadcrumb-item"><a href="{{ $tiersUrl }}">Tiers</a></li>
                    <li class="breadcrumb-item active">{{ $tier ? 'Edit' : 'Create' }}</li>
                </ol>
            </div>
        </div>

        @include('layouts.partials.alerts')

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
                        <h5 class="card-title mb-0 fw-semibold">Tier Details</h5>
                    </div>
                    <form method="POST" action="{{ $formUrl }}">
                        @csrf
                        @if ($formMethod === 'PUT')
                            @method('PUT')
                        @endif
                        <div class="card-body p-4">
                            <div class="mb-3">
                                <label for="name" class="form-label">Name</label>
                                <input type="text" name="name" id="name"
                                    class="form-control @error('name') is-invalid @enderror"
                                    value="{{ old('name', $tier?->name) }}" placeholder="Gold">
                                @error('name')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label for="slug" class="form-label">Slug</label>
                                {{-- Disabled, not merely read-only: `MemberTierRequest` derives this
                                     from the name on every save, so a typed value would be thrown
                                     away. A disabled box says so; a readonly one invites the edit. --}}
                                <input type="text" name="slug" id="slug" disabled
                                    class="form-control @error('slug') is-invalid @enderror"
                                    value="{{ old('slug', $tier?->slug) }}" placeholder="gold">
                                <small class="text-muted fs-7">Generated from the name. Lowercase, used by exports and integrations to refer to this tier without its id.</small>
                                @error('slug')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                {{-- The unit is the configured currency's symbol. Not an
                                     input-group: wrapping a validated field in one hides the
                                     Bootstrap sibling `invalid-feedback`. --}}
                                <label for="price" class="form-label">Price ({{ $currencySymbol }})</label>
                                <input type="number" name="price" id="price" step="0.01" min="0"
                                    class="form-control @error('price') is-invalid @enderror"
                                    value="{{ old('price', $tier?->price) }}" placeholder="100000">
                                @error('price')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label for="duration_days" class="form-label">Duration (days)</label>
                                <input type="number" name="duration_days" id="duration_days" min="1" max="65535"
                                    class="form-control @error('duration_days') is-invalid @enderror"
                                    value="{{ old('duration_days', $tier?->duration_days) }}" placeholder="100">
                                <small class="text-muted fs-7">How long a subscription to this tier stays live from the day it starts.</small>
                                @error('duration_days')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label for="point_multiplier" class="form-label">Point Multiplier</label>
                                <input type="number" name="point_multiplier" id="point_multiplier" step="0.01"
                                    min="0" max="99.99"
                                    class="form-control @error('point_multiplier') is-invalid @enderror"
                                    value="{{ old('point_multiplier', $tier?->point_multiplier) }}" placeholder="1.00">
                                @error('point_multiplier')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label for="role_name" class="form-label">Assigned Role</label>
                                <select name="role_name" id="role_name"
                                    class="form-select @error('role_name') is-invalid @enderror">
                                    <option value="">No role</option>
                                    @foreach ($roleOptions as $roleName => $roleLabel)
                                        <option value="{{ $roleName }}"
                                            @selected(old('role_name', $tier?->role_name) === $roleName)>
                                            {{ $roleLabel }}
                                        </option>
                                    @endforeach
                                </select>
                                <small class="text-muted fs-7">Applied to the account while the subscription is active. The list never offers superadmin.</small>
                                @error('role_name')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label for="sort_order" class="form-label">Sort Order</label>
                                <input type="number" name="sort_order" id="sort_order" min="0" max="65535"
                                    class="form-control @error('sort_order') is-invalid @enderror"
                                    value="{{ old('sort_order', $tier?->sort_order ?? 0) }}">
                                @error('sort_order')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>

                            {{-- Hidden 0 + checkbox 1: an unchecked box submits nothing, which
                                 a `boolean` rule reads as a missing field. --}}
                            <div class="form-check form-switch">
                                <input type="hidden" name="is_active" value="0">
                                <input type="checkbox" class="form-check-input" name="is_active" id="is_active"
                                    value="1" @checked(old('is_active', $tier?->is_active ?? true))>
                                <label class="form-check-label" for="is_active">Active</label>
                                @error('is_active')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="card-footer bg-body-tertiary border-top py-3 d-flex justify-content-end align-items-center gap-2">
                            <a href="{{ $tiersUrl }}"
                                class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-2">
                                <i class="bi bi-check-lg"></i> {{ $tier ? 'Save Tier' : 'Create Tier' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-lg-4 col-12">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-transparent border-bottom py-2">
                        <h5 class="card-title mb-0 fw-semibold">What this changes</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-start gap-2 mb-3">
                            <i class="fas fa-arrow-up text-primary mt-1"></i>
                            <small class="text-muted">Only new subscriptions read these values. A member already
                                subscribed keeps the price, expiry and role recorded on their own subscription.</small>
                        </div>
                        <div class="d-flex align-items-start gap-2 mb-3">
                            <i class="fas fa-money-bill-wave text-primary mt-1"></i>
                            <small class="text-muted">The number is stored as written. Which currency it means is one
                                setting for the whole app, on the Settings page.</small>
                        </div>
                        <div class="d-flex align-items-start gap-2">
                            <i class="fas fa-power-off text-primary mt-1"></i>
                            <small class="text-muted">Deactivating keeps the tier on members who already have
                                it — it only stops it being offered again.</small>
                        </div>
                    </div>
                </div>

                {{-- The card is the button: no delete URL (a tier that does not
                     exist yet) means no card. Same rule as the member profile, and
                     `members.delete` is the route's own gate, so the trigger does not
                     appear for a caller it would refuse. --}}
                @can('members.delete')
                    @if ($deleteUrl)
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header bg-transparent border-bottom py-2">
                                <h5 class="card-title mb-0 fw-semibold text-danger">Danger Zone</h5>
                            </div>
                            <div class="card-body p-4">
                                <p class="text-muted fs-7 mb-3">Moving a tier to the trash stops it being offered to
                                    new subscriptions; members already on it keep theirs. A tier with subscriptions
                                    on it cannot be deleted — deactivate it instead.</p>
                                <x-ui.confirm-action :action="$deleteUrl" method="DELETE" action-type="delete"
                                    :item-name="$tier->name" label="Delete" title="Delete"
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

@push('scripts')
<script @nonce>
(function () {
    var name = document.getElementById('name');
    var slug = document.getElementById('slug');

    if (! name || ! slug) {
        return;
    }

    // Preview of what the server writes. `MemberTierRequest` runs `Str::slug`
    // on the name and that result is what is stored — this only keeps the
    // disabled box from showing a stale value while the name is being typed.
    // shortcut: no transliteration of non-Latin names, so the preview can read
    // empty where `Str::slug` would still find something.
    var sync = function () {
        slug.value = name.value
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    };

    name.addEventListener('input', sync);
    sync();
})();
</script>
@endpush
