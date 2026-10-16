@extends('layouts.app', ['title' => 'System Health'])

@section('content')
    <div class="content-header mb-3">
        <div class="d-flex justify-content-between align-items-start w-100">
            <div>
                <h1 class="page-title fw-bold">System Health</h1>
                <p class="page-description text-muted fs-7 mb-0">Live operational status of this application's core services.</p>
            </div>
            <ol class="breadcrumb float-sm-end mb-0">
                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">System Health</li>
            </ol>
        </div>
    </div>

    {{-- Page-level badge: the one answer the page exists for. Icon + text carry
         the state, colour only reinforces it — never colour alone. --}}
    <div class="mb-4">
        @if ($isHealthy)
            <span class="badge bg-success-subtle text-success fs-7">
                <i class="fas fa-circle-check me-1" aria-hidden="true"></i>All systems operational
            </span>
        @else
            <span class="badge bg-danger-subtle text-danger fs-7">
                <i class="fas fa-circle-exclamation me-1" aria-hidden="true"></i>One or more checks failing
            </span>
        @endif
    </div>

    {{-- One card per check, straight from the controller's $checks array — the
         view asks nothing of the service or the database itself. Read-only:
         there is no button, form or refresh action anywhere on this page. --}}
    @php
        // Presentational copy keyed by check name, not data: the status itself
        // arrives with $checks from the controller.
        $descriptions = [
            'database' => 'Primary database connectivity.',
            'cache' => 'Cache read/write round-trip.',
            'queue' => 'Queue backend reachability.',
            'storage' => 'Disk read/write round-trip.',
        ];
    @endphp
    <div class="row g-4">
        @foreach ($checks as $name => $status)
            <div class="col-md-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <h5 class="card-title mb-0">{{ ucfirst($name) }}</h5>
                            @if ($status === 'ok')
                                <span class="badge bg-success-subtle text-success">
                                    <i class="fas fa-check me-1" aria-hidden="true"></i>OK
                                </span>
                            @else
                                <span class="badge bg-danger-subtle text-danger">
                                    <i class="fas fa-xmark me-1" aria-hidden="true"></i>Fail
                                </span>
                            @endif
                        </div>
                        <p class="text-muted fs-7 mb-0">{{ $descriptions[$name] ?? 'Service status check.' }}</p>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endsection
