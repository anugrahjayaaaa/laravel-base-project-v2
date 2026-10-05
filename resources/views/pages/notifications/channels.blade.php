{{--
    Notification channel preferences (Phase 9 Group A, P9-A2).

    Contract with NotificationController::channels() (no queries, no route() here):
      $channels   [['key' => 'in_app', 'label' => 'In-App',
                    'description' => '…', 'enabled' => true], …]
                   `enabled` arrives as a REAL bool — a stored 'false' string is
                   truthy in PHP, so an uncast value ticks every switch ON. The cast
                   belongs in the controller.
      $updateUrl  string  the save endpoint (Group C swaps the stub for the real route)

    Each toggle is the hidden `value="0"` + checkbox `value="1"` pair (convention
    §4d). Without the companion an unticked box is simply absent from the payload,
    so the switch can only ever be turned ON — and the failure is invisible,
    because the value round-trips fine while it is ON.
--}}
@extends('layouts.app', ['title' => 'Notification Channels'])

@section('content')
    <div class="content-header mb-3">
        <div class="d-flex justify-content-between align-items-start w-100">
            <div>
                <h1 class="page-title fw-bold">Notification Channels</h1>
                <p class="page-description text-muted fs-7 mb-0">
                    Choose which channels deliver each kind of notification.
                </p>
            </div>
            <ol class="breadcrumb float-sm-end mb-0">
                <li class="breadcrumb-item"><a href="{{ route('notifications.index') }}">Notifications &amp; Mail</a></li>
                <li class="breadcrumb-item active">Channels</li>
            </ol>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
            <i class="fas fa-circle-check me-1"></i>
            {{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
            <i class="fas fa-circle-exclamation me-1"></i>
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @can('notifications.view')
        @can('notifications.manage')
            <form method="POST" action="{{ $updateUrl }}">
                @csrf

                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-transparent border-bottom py-3">
                        <h5 class="card-title mb-0 fw-semibold">Delivery Channels</h5>
                    </div>
                    {{-- p-4, matching every other table card (features, users,
                         permissions): an edge-to-edge p-0 body is a one-off
                         variant the design system does not carry. --}}
                    <div class="card-body p-4">
                        <div class="table-responsive">
                            <table class="table table-fixed align-middle mb-0">
                                {{-- One <colgroup>, pinned: with auto layout the Label column is
                                     sized by the longest description and the switch column drifts. --}}
                                <colgroup>
                                    <col style="width: 20%">
                                    <col>
                                    <col style="width: 12%">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th scope="col" class="align-middle">Channel</th>
                                        <th scope="col" class="align-middle">Description</th>
                                        <th scope="col" class="align-middle text-center">Enabled</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($channels as $channel)
                                        <tr>
                                            <td class="align-middle fw-medium">{{ $channel['label'] }}</td>
                                            <td class="align-middle text-muted fs-7">{{ $channel['description'] }}</td>
                                            <td class="text-center">
                                                {{-- Centring needs BOTH halves: the cell is text-center so
                                                     the wrapper centres, and the wrapper is d-inline-flex
                                                     because .form-switch is otherwise a block box with the
                                                     input positioned inside its own left edge. --}}
                                                <div class="form-check form-switch d-inline-flex mb-0">
                                                    <input type="hidden" name="{{ $channel['key'] }}" value="0">
                                                    <input class="form-check-input" type="checkbox"
                                                           name="{{ $channel['key'] }}"
                                                           id="channel_{{ $channel['key'] }}" value="1"
                                                           aria-label="{{ $channel['label'] }} notifications"
                                                           @checked($channel['enabled'])>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer bg-body-tertiary border-top py-3 d-flex justify-content-end align-items-center gap-2">
                        <a href="{{ route('notifications.index') }}" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
                            <i class="bi bi-x-circle"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-2">
                            <i class="bi bi-check-lg"></i> Save Channels
                        </button>
                    </div>
                </div>
            </form>
        @else
            {{-- notifications.view without notifications.manage. The switches are NOT rendered:
                 a control that looks editable and silently discards what is typed is worse than
                 none. The values stay visible as badges. The server re-checks regardless. --}}
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3 d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0 fw-semibold">Delivery Channels</h5>
                    <span class="badge bg-secondary-subtle text-secondary">Read-only</span>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted fs-7">You can view these channels but not change them.</p>
                    <div class="table-responsive">
                        <table class="table table-fixed align-middle mb-0">
                            <colgroup>
                                <col style="width: 20%">
                                <col>
                                <col style="width: 12%">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th scope="col" class="align-middle">Channel</th>
                                    <th scope="col" class="align-middle">Description</th>
                                    <th scope="col" class="align-middle text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($channels as $channel)
                                    <tr>
                                        <td class="align-middle fw-medium">{{ $channel['label'] }}</td>
                                        <td class="align-middle text-muted fs-7">{{ $channel['description'] }}</td>
                                        <td class="text-center">
                                            <span class="badge {{ $channel['enabled'] ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }}">
                                                {{ $channel['enabled'] ? 'Enabled' : 'Disabled' }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endcan
    @endcan
@endsection