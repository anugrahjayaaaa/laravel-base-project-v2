{{-- Voucher is its own domain — not part of membership, validated at payment
     time (Phase 19). Group A ships no routes (phase-18 D-4 carve-out): every
     URL and value arrives from the view model. One HTML5 form per row sits
     above the table (a <form> may not wrap <tr>) and the row's inputs bind to
     it via the `form` attribute. The view never computes remaining quota —
     claimed and quota are rendered exactly as handed over. --}}
@extends('layouts.app', ['title' => 'Vouchers'])

@section('content')
    @feature('vouchers')
        <div class="content-header mb-3">
            <div class="d-flex justify-content-between align-items-start w-100">
                <div>
                    <h1 class="page-title fw-bold">Vouchers</h1>
                    <p class="page-description text-muted fs-7 mb-0">
                        Discount codes validated at payment time. Quotas and windows are
                        enforced server-side when a sale applies one.
                    </p>
                </div>
                <ol class="breadcrumb float-sm-end mb-0">
                    <li class="breadcrumb-item active">Vouchers</li>
                </ol>
            </div>
        </div>

        @include('layouts.partials.alerts')

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                @foreach ($vouchers as $voucher)
                    <form id="voucher-{{ $voucher->id }}" method="POST" action="{{ $voucher->update_url }}"
                        class="d-none">
                        @csrf
                        @method('PUT')
                    </form>
                @endforeach

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Code</th>
                                <th scope="col">Type</th>
                                <th scope="col">Value</th>
                                <th scope="col">Max Discount</th>
                                <th scope="col">Min Purchase</th>
                                <th scope="col">Quota</th>
                                <th scope="col">Claimed</th>
                                <th scope="col">Per Member</th>
                                <th scope="col">Starts</th>
                                <th scope="col">Expires</th>
                                <th scope="col">Active</th>
                                <th scope="col" class="text-end">Save</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($vouchers as $voucher)
                                <tr>
                                    <td><input type="text" name="code" form="voucher-{{ $voucher->id }}"
                                            class="form-control form-control-sm text-uppercase"
                                            value="{{ $voucher->code }}" aria-label="Voucher code"></td>
                                    <td>
                                        <select name="type" form="voucher-{{ $voucher->id }}"
                                            class="form-select form-select-sm" aria-label="Voucher type">
                                            @foreach ($types as $type)
                                                <option value="{{ $type }}" @selected($voucher->type === $type)>
                                                    {{ ucfirst($type) }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td><input type="number" name="value" form="voucher-{{ $voucher->id }}" step="0.01"
                                            min="0" class="form-control form-control-sm" style="width: 7rem"
                                            value="{{ $voucher->value }}" aria-label="Voucher value"></td>
                                    <td><input type="number" name="max_discount" form="voucher-{{ $voucher->id }}"
                                            step="0.01" min="0" class="form-control form-control-sm" style="width: 8rem"
                                            value="{{ $voucher->max_discount }}" aria-label="Maximum discount"></td>
                                    <td><input type="number" name="min_purchase" form="voucher-{{ $voucher->id }}"
                                            step="0.01" min="0" class="form-control form-control-sm" style="width: 8rem"
                                            value="{{ $voucher->min_purchase }}" aria-label="Minimum purchase"></td>
                                    <td><input type="number" name="quota" form="voucher-{{ $voucher->id }}" min="0"
                                            class="form-control form-control-sm" style="width: 7rem"
                                            value="{{ $voucher->quota }}" placeholder="Unlimited"
                                            aria-label="Claim quota"></td>
                                    <td>{{ $voucher->claimed_count }}</td>
                                    <td><input type="number" name="per_member_limit" form="voucher-{{ $voucher->id }}"
                                            min="1" class="form-control form-control-sm" style="width: 6rem"
                                            value="{{ $voucher->per_member_limit }}" aria-label="Limit per member"></td>
                                    <td><input type="date" name="starts_at" form="voucher-{{ $voucher->id }}"
                                            class="form-control form-control-sm" value="{{ $voucher->starts_at }}"
                                            aria-label="Start date"></td>
                                    <td><input type="date" name="expires_at" form="voucher-{{ $voucher->id }}"
                                            class="form-control form-control-sm" value="{{ $voucher->expires_at }}"
                                            aria-label="Expiry date"></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <input type="hidden" name="is_active" value="0"
                                                form="voucher-{{ $voucher->id }}">
                                            <input type="checkbox" class="form-check-input" name="is_active" value="1"
                                                form="voucher-{{ $voucher->id }}" @checked($voucher->is_active)
                                                aria-label="Voucher active">
                                            @unless ($voucher->is_active)
                                                <x-ui.badge variant="neutral" text="INACTIVE" />
                                            @endunless
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        @can('vouchers.manage')
                                            <button type="submit" form="voucher-{{ $voucher->id }}"
                                                class="btn btn-sm btn-primary d-inline-flex align-items-center gap-2">
                                                <i class="bi bi-check-lg"></i> Save
                                            </button>
                                        @else
                                            <span class="text-muted fs-7">Read only</span>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="12" class="text-center py-4">
                                        <x-ui.empty-state message="No vouchers yet." />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endfeature
@endsection
