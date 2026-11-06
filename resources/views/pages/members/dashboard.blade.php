{{-- Phase 18 Group A (D-4): every URL and value arrives from the view model;
     the member routes ship in B6. Counters are primitives already computed by
     the read Action — the widget renders an empty state, never a Blade-made
     zero. The broadcast trigger posts to the route URL it is handed, with its
     own copy in data-*, never a hand-built URL. --}}
@extends('layouts.app', ['title' => 'Membership Dashboard'])

@section('content')
    @feature('member_loyalty')
        <div class="content-header mb-3">
            <div class="d-flex justify-content-between align-items-start w-100">
                <div>
                    <h1 class="page-title fw-bold">Membership Dashboard</h1>
                    <p class="page-description text-muted fs-7 mb-0">Programme-wide counters: points issued,
                        members by tier and subscriptions expiring soon.</p>
                </div>
                <ol class="breadcrumb float-sm-end mb-0">
                    <li class="breadcrumb-item"><a href="{{ $indexUrl }}">Members</a></li>
                    <li class="breadcrumb-item active">Dashboard</li>
                </ol>
            </div>
        </div>

        @include('layouts.partials.alerts')

        <div class="row g-4 mb-1">
            @foreach ($stats as $stat)
                <div class="col-md-4 col-12">
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body p-4">
                            <p class="text-muted mb-1">{{ $stat->label }}</p>
                            <h3 class="fw-bold mb-0">{{ $stat->value }}</h3>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="row g-4">
            <div class="col-lg-8 col-12">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-transparent border-bottom py-2 d-flex justify-content-between align-items-center gap-2 flex-wrap">
                        <h5 class="card-title mb-0 fw-semibold">Expiring within {{ $alertWindowDays }} days</h5>
                        @can('members.notify')
                            {{-- A null URL removes the trigger, not breaks the page (D-4). --}}
                            @if ($broadcastUrl)
                                <x-ui.confirm-action :action="$broadcastUrl" method="POST"
                                    label="Send Expiry Alerts" title="Send Expiry Alerts"
                                    message="Every member with a subscription inside the alert window receives one mail and one in-app notification. Nothing is sent until the change is confirmed."
                                    variant="warning"
                                    class="btn btn-sm btn-warning d-inline-flex align-items-center gap-2">
                                    <i class="bi bi-bell"></i> Notify Expiring Members
                                </x-ui.confirm-action>
                            @endif
                        @endcan
                    </div>
                    <div class="card-body p-4">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">Member No.</th>
                                        <th scope="col">User</th>
                                        <th scope="col">Tier</th>
                                        <th scope="col">Expires</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($expiring as $row)
                                        <tr>
                                            <td>{{ $row->member_number }}</td>
                                            <td>{{ $row->user_name }}</td>
                                            <td>{{ $row->tier_name }}</td>
                                            <td>{{ $row->expires_at }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-center py-4">
                                                <x-ui.empty-state
                                                    message="No subscriptions are expiring inside the alert window." />
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-12">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-transparent border-bottom py-2">
                        <h5 class="card-title mb-0 fw-semibold">Members by Tier</h5>
                    </div>
                    <ul class="list-group list-group-flush">
                        @forelse ($membersByTier as $row)
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span class="text-muted">{{ $row->label }}</span>
                                <span class="fw-semibold">{{ $row->count }}</span>
                            </li>
                        @empty
                            <li class="list-group-item text-center py-4">
                                <x-ui.empty-state message="No tier assignments yet." />
                            </li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
    @endfeature
@endsection
