@extends('layouts.app', ['title' => 'Dashboard'])

@section('content')
<div class="content-header mb-3">
    <h1 class="page-title">Dashboard</h1>
    <p class="page-description mb-0">Laravel Base Project v2 — AdminLTE 4.9.1 foundation is active.</p>
</div>

<div class="row g-4">
    @if (session('status'))
        <div class="col-12">
            <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                <i class="fas fa-circle-check me-1"></i>
                {{ session('status') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        </div>
    @endif
    @if (session('error'))
        <div class="col-12">
            <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                <i class="fas fa-circle-exclamation me-1"></i>
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        </div>
    @endif
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-3">Application Status</h5>
                <p class="text-muted mb-0">
                    UI foundation is ready. Extend this page as features are added.
                </p>
            </div>
        </div>
    </div>
</div>
@endsection
