@extends('layouts.app', ['title' => 'Active Sessions'])

@section('content')
    <style>
        .sessions-table tbody tr:first-child td { border-top: none; }
        .sessions-table thead th {
            text-transform: uppercase;
            font-size: 0.7rem;
            letter-spacing: 0.05em;
            font-weight: 600;
            color: var(--lbp-text-muted, #6c757d);
            border-bottom: 2px solid var(--lbp-border, #dee2e6);
        }
        .sessions-table { border-radius: 8px; overflow: hidden; }
        .sessions-table .badge { border-radius: 6px; }
    </style>
    <div class="content-header mb-3">
        <h1 class="page-title">Active Sessions</h1>
        <p class="page-description mb-0">Manage your active sessions across devices.</p>
    </div>

    <div class="row g-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title mb-0">Sessions</h5>
                        <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal"
                            data-bs-target="#confirmModal" data-action="{{ route('sessions.logout-all') }}"
                            data-method="POST" data-title="Logout All Devices?"
                            data-message="Are you sure you want to logout from all other devices? You will need to login again on those devices."
                            data-variant="danger" data-label="Logout All">
                            <i class="fas fa-sign-out-alt me-1"></i> Logout All Devices
                        </button>
                    </div>
                    <table class="table table-hover mb-0 sessions-table">
                        <thead>
                            <tr>
                                <th>Device</th>
                                <th>IP Address</th>
                                <th>Last Activity</th>
                                <th>Created</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {{-- Current web session --}}
                            <tr class="bg-body-secondary">
                                <td>
                                    <i class="fas fa-desktop me-1"></i> <strong>This Device</strong>
                                </td>
                                <td>{{ request()->ip() }}</td>
                                <td>{{ now()->format('Y-m-d H:i') }}</td>
                                <td>{{ auth()->user()->created_at->format('Y-m-d') }}</td>
                                <td><span class="badge bg-success-subtle text-success">Active</span></td>
                            </tr>

                            {{-- API tokens (other devices) --}}
                            @foreach ($tokens as $token)
                                <tr>
                                    <td>
                                        <i class="fas fa-mobile-alt me-1"></i> {{ $token->name }}
                                    </td>
                                    <td>{{ $token->last_used_at ? '—' : 'Never' }}</td>
                                    <td>{{ $token->last_used_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                    <td>{{ $token->created_at->format('Y-m-d H:i') }}</td>
                                    <td>
                                        <span class="badge bg-info-subtle text-info">API</span>
                                    </td>
                                </tr>
                            @endforeach

                            @if ($tokens->isEmpty())
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-3">
                                        No other active sessions.
                                    </td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
