{{-- The signed-in member's own dashboard. Every value arrives from
     MemberSelfDashboardAction; nothing is computed here and no route() is
     called, because a member account holds no permission and so has no other
     screen to link to. --}}
@extends('layouts.app', ['title' => 'My Dashboard'])

@section('content')
    <div class="content-header mb-3">
        <div>
            <h1 class="page-title fw-bold">My Dashboard</h1>
            <p class="page-description text-muted fs-7 mb-0">Member {{ $memberNumber }} — joined {{ $joinedAt }}.</p>
        </div>
    </div>

    @include('layouts.partials.alerts')

    <div class="row g-4 mb-4">
        <div class="col-md-4 col-12">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <p class="text-muted mb-1">Tier</p>
                    <h3 class="fw-bold mb-1">{{ $tierName ?? '—' }}</h3>
                    <x-ui.badge :variant="$statusVariant" :text="$statusLabel" />
                </div>
            </div>
        </div>
        <div class="col-md-4 col-12">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <p class="text-muted mb-1">Points</p>
                    <h3 class="fw-bold mb-0">{{ $pointsBalance }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-4 col-12">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <p class="text-muted mb-1">Subscription expires</p>
                    <h3 class="fw-bold mb-1">{{ $expiresAt ?? '—' }}</h3>
                    <p class="text-muted fs-7 mb-0">
                        @if ($daysRemaining !== null)
                            {{ $daysRemaining }} day{{ $daysRemaining === 1 ? '' : 's' }} left
                        @else
                            No active period.
                        @endif
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent border-bottom py-2">
            <h5 class="card-title mb-0 fw-semibold">Points History</h5>
        </div>
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Reason</th>
                            <th scope="col" class="text-end">Points</th>
                            <th scope="col" class="text-end">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($points as $row)
                            <tr>
                                <td>{{ $row->created_at }}</td>
                                <td>{{ $row->reason }}</td>
                                <td class="text-end"><x-ui.badge :variant="$row->delta_variant" :text="$row->delta_display" /></td>
                                <td class="text-end">{{ $row->balance_after }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center py-4">
                                    <x-ui.empty-state message="No points recorded yet." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
