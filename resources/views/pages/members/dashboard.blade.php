{{-- Phase 18 Group A (D-4), extended by Group F: every URL and value arrives from the
     view model; the member routes ship in B6. Counters are primitives already computed
     by the read Action — the widget renders an empty state, never a Blade-made
     zero. The broadcast trigger posts to the route URL it is handed, with its
     own copy in data-*, never a hand-built URL.

     Analytics (F1): the timeframe is a query string rather than session state, and every
     bar width arrives computed from MemberAnalyticsStatsAction. No chart library is
     installed and CSP forbids inline script, so the series is server-rendered Bootstrap
     progress markup — the shape the storage card on the health page already uses.

     The info icons are the settings-page tooltip shape; `adminlte.min.js` initialises
     them and Bootstrap's default tooltip trigger is hover+focus, so `tabindex="0"` makes
     each explanation keyboard-reachable without another script. The two list widgets
     share one capped scroll height inside the card body (the permission-matrix panel's
     shape), so a long table cannot stretch its card past the neighbour's. --}}
@extends('layouts.app', ['title' => 'Membership Analytics'])

@section('content')
    @feature('member_loyalty')
        <div class="content-header mb-3">
            <div class="d-flex justify-content-between align-items-start w-100">
                <div>
                    <h1 class="page-title fw-bold">Membership Analytics</h1>
                    <p class="page-description text-muted fs-7 mb-0">Member growth, retention, and lifecycle
                        stats.</p>
                </div>
                <ol class="breadcrumb float-sm-end mb-0">
                    <li class="breadcrumb-item"><a href="{{ $indexUrl }}">Members</a></li>
                    <li class="breadcrumb-item active">Dashboard</li>
                </ol>
            </div>
        </div>

        {{-- The alerts partial is errors-only, so a success flash from the broadcast
             trigger has no home on this page without this block — the same shape
             roles/index and users/index use. --}}
        @if (session('status'))
            <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                <i class="fas fa-circle-check me-1"></i>
                {{ session('status') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @include('layouts.partials.alerts')

        {{-- `mt-0` on the row below neutralizes Bootstrap's negative gutter margin,
             so `mb-4` here is the actual spacing between card and stats row. --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body py-2 px-3 d-flex flex-wrap align-items-center gap-3">
                <div class="dropdown">
                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle d-flex align-items-center gap-2"
                        type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                        <i class="bi bi-calendar3"></i>
                        <span>{{ $window['from'] }} – {{ $window['to'] }}</span>
                    </button>
                    <div class="dropdown-menu p-3 shadow-lg border-0" style="min-width: 320px;">
                        <form method="GET" action="{{ $filterUrl }}" id="dateRangeForm">
                            <input type="hidden" name="range" value="custom">
                            <div class="mb-2">
                                <label class="form-label small text-muted mb-1" for="custom_from">Start date</label>
                                <input type="date" class="form-control form-control-sm" id="custom_from"
                                    name="date_from" value="{{ $window['from'] }}" max="{{ date('Y-m-d') }}" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small text-muted mb-1" for="custom_to">End date</label>
                                <input type="date" class="form-control form-control-sm" id="custom_to"
                                    name="date_to" value="{{ $window['to'] }}" max="{{ date('Y-m-d') }}" required>
                            </div>
                            <div class="d-flex flex-wrap gap-1 mb-3">
                                <button type="button" class="btn btn-sm btn-outline-secondary date-preset" data-days="0">Today</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary date-preset" data-days="7">7 Days</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary date-preset" data-days="30">1 Month</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary date-preset" data-days="90">3 Months</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary date-preset" data-days="180">6 Months</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary date-preset" data-days="270">9 Months</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary date-preset" data-days="365">1 Year</button>
                            </div>
                            <button type="submit" class="btn btn-sm btn-primary w-100">
                                <i class="bi bi-funnel-fill me-1"></i>Apply Filter
                            </button>
                        </form>
                    </div>
                </div>

                <div class="text-muted small ms-auto d-flex align-items-center gap-1 fs-7">
                    <i class="bi bi-clock-history text-primary"></i>
                    <span>Window: {{ $window['from'] }} – {{ $window['to'] }}</span>
                </div>
            </div>
        </div>

        <div class="row g-4 mt-0">
            @foreach ($stats as $stat)
                <div class="col-6 col-lg">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <div class="text-muted small text-uppercase fw-semibold mb-1">{{ $stat->label }}
                                <i class="bi bi-info-circle text-muted fs-7 ms-1" tabindex="0" data-bs-toggle="tooltip"
                                    data-bs-placement="top" data-bs-title="{{ $stat->hint }}"></i>
                            </div>
                            <div class="fs-3 fw-bold lh-1">{{ $stat->value }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
            @foreach ($metrics as $metric)
                <div class="col-6 col-lg">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <div class="text-muted small text-uppercase fw-semibold mb-1">{{ $metric['label'] }}
                                <i class="bi bi-info-circle text-muted fs-7 ms-1" tabindex="0" data-bs-toggle="tooltip"
                                    data-bs-placement="top" data-bs-title="{{ $metric['hint'] }}"></i>
                            </div>
                            <div class="fs-3 fw-bold lh-1">{{ $metric['value'] }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="row g-4 mt-0">
            <div class="col-lg-8 col-12">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-transparent border-bottom py-2">
                        <h5 class="card-title mb-0 fw-semibold">New Members vs Expiring Subscriptions
                            <i class="bi bi-info-circle text-muted fs-7" tabindex="0" data-bs-toggle="tooltip"
                                data-bs-placement="top"
                                data-bs-title="How many members joined and how many subscriptions ended in each period. Bar length is measured against the busiest period, so a short bar means few rows — not a broken chart."></i>
                        </h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">Period</th>
                                        <th scope="col">New members</th>
                                        <th scope="col">Expiring periods</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($series as $point)
                                        <tr>
                                            <td class="text-nowrap">{{ $point['label'] }}</td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="progress flex-grow-1" role="progressbar"
                                                        aria-label="New members in {{ $point['label'] }}: {{ $point['new'] }}"
                                                        aria-valuetext="{{ $point['new'] }} new members"
                                                        aria-valuenow="{{ $point['new_pct'] }}" aria-valuemin="0"
                                                        aria-valuemax="100" style="height: 10px;">
                                                        <div class="progress-bar bg-success"
                                                            style="width: {{ $point['new_pct'] }}%"></div>
                                                    </div>
                                                    <span class="fw-semibold text-nowrap">{{ $point['new'] }}</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="progress flex-grow-1" role="progressbar"
                                                        aria-label="Expiring periods in {{ $point['label'] }}: {{ $point['expired'] }}"
                                                        aria-valuetext="{{ $point['expired'] }} expiring periods"
                                                        aria-valuenow="{{ $point['expired_pct'] }}" aria-valuemin="0"
                                                        aria-valuemax="100" style="height: 10px;">
                                                        <div class="progress-bar bg-warning"
                                                            style="width: {{ $point['expired_pct'] }}%"></div>
                                                    </div>
                                                    <span class="fw-semibold text-nowrap">{{ $point['expired'] }}</span>
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="text-center py-4">
                                                <x-ui.empty-state message="No period resolved for this timeframe." />
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-12 d-flex flex-column gap-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-transparent border-bottom py-2">
                        <h5 class="card-title mb-0 fw-semibold">Members per Tier
                            <i class="bi bi-info-circle text-muted fs-7" tabindex="0" data-bs-toggle="tooltip"
                                data-bs-placement="top"
                                data-bs-title="How many live subscriptions each tier holds right now. Every tier you sell is listed, including the ones with nobody active on them."></i>
                        </h5>
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

                {{-- Grows so this column's bottom edge always matches the chart card's,
                     whichever of the two is the taller content. --}}
                <div class="card border-0 shadow-sm flex-grow-1">
                    <div class="card-header bg-transparent border-bottom py-2">
                        <h5 class="card-title mb-0 fw-semibold">Length of Membership
                            <i class="bi bi-info-circle text-muted fs-7" tabindex="0" data-bs-toggle="tooltip"
                                data-bs-placement="top"
                                data-bs-title="Members grouped by how long they have been a member, counted from the day they joined to today. This card ignores the timeframe filter above."></i>
                        </h5>
                    </div>
                    <ul class="list-group list-group-flush">
                        @foreach ($tenure as $band)
                            <li class="list-group-item">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-muted">{{ $band['label'] }}</span>
                                    <span class="fw-semibold">{{ $band['count'] }}</span>
                                </div>
                                <div class="progress" role="progressbar"
                                    aria-label="Members with tenure {{ $band['label'] }}: {{ $band['count'] }}"
                                    aria-valuenow="{{ $band['pct'] }}" aria-valuemin="0" aria-valuemax="100"
                                    style="height: 6px;">
                                    <div class="progress-bar bg-primary" style="width: {{ $band['pct'] }}%"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>

        <div class="row g-4 mt-0 mb-4">
            <div class="col-lg-6 col-12">
                <div class="card border-0 shadow-sm h-100 d-flex flex-column">
                    <div class="card-header bg-transparent border-bottom py-2 d-flex justify-content-between align-items-center gap-2 flex-wrap">
                        <h5 class="card-title mb-0 fw-semibold">Expiring Soon — within {{ $alertWindowDays }} days
                            <i class="bi bi-info-circle text-muted fs-7" tabindex="0" data-bs-toggle="tooltip"
                                data-bs-placement="top"
                                data-bs-title="Active subscriptions that reach their end date inside the alert window. Set the window in Settings → Membership."></i>
                        </h5>
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
                    <div class="card-body p-4 flex-grow-1 overflow-auto" style="max-height: 340px;">
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

            <div class="col-lg-6 col-12">
                <div class="card border-0 shadow-sm h-100 d-flex flex-column">
                    <div class="card-header bg-transparent border-bottom py-2">
                        {{-- Flex, not plain block: AdminLTE floats `.card-title`, which pulls
                             the count note up onto the title line. --}}
                        <div class="d-flex flex-column">
                            <h5 class="card-title mb-0 fw-semibold">Inactive Members — no sign-in for {{ $at_risk_days }} days
                                <i class="bi bi-info-circle text-muted fs-7" tabindex="0" data-bs-toggle="tooltip"
                                    data-bs-placement="top"
                                    data-bs-title="Members whose subscription is still active but who have not signed in for {{ $at_risk_days }} days. A member who has never signed in counts here too."></i>
                            </h5>
                            <p class="text-muted small mb-0">{{ $at_risk_count }}
                                member{{ $at_risk_count === 1 ? '' : 's' }}; the list shows the soonest to
                                expire.</p>
                        </div>
                    </div>
                    <div class="card-body p-4 flex-grow-1 overflow-auto" style="max-height: 340px;">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">Member No.</th>
                                        <th scope="col">User</th>
                                        <th scope="col">Last activity</th>
                                        <th scope="col">Expires</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($at_risk as $row)
                                        <tr>
                                            <td>{{ $row['member_number'] }}</td>
                                            <td>{{ $row['user_name'] }}</td>
                                            <td>{{ $row['last_activity'] }}</td>
                                            <td>{{ $row['expires_at'] }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-center py-4">
                                                <x-ui.empty-state
                                                    message="No active member has been inactive for {{ $at_risk_days }} days." />
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    @endfeature

    @push('scripts')
        <script @nonce>
            (function () {
                var from = document.getElementById('custom_from');
                var to = document.getElementById('custom_to');
                if (!from || !to) return;

                var today = new Date().toISOString().split('T')[0];

                function syncMin() {
                    to.min = from.value;
                    if (to.value < from.value) to.value = from.value;
                }

                from.addEventListener('change', function () {
                    syncMin();
                    to.focus();
                });

                to.addEventListener('change', function () {
                    if (to.value > today) to.value = today;
                    if (to.value < from.value) to.value = from.value;
                });

                document.querySelectorAll('.date-preset').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var end = new Date();
                        var start = new Date();
                        start.setDate(start.getDate() - parseInt(btn.dataset.days, 10));
                        from.value = start.toISOString().split('T')[0];
                        to.value = end.toISOString().split('T')[0];
                        syncMin();
                    });
                });
            })();
        </script>
    @endpush
@endsection
