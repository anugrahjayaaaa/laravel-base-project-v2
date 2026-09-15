@extends('layouts.app', ['title' => 'Dashboard'])

@section('content')
<div class="content-header mb-3">
    <h1 class="page-title">Dashboard</h1>
    <p class="page-description mb-0">Laravel Base Project v2 — AdminLTE 4.9.1 foundation is active.</p>
    <form class="feature-search" role="search">
        <div class="input-group input-group-sm" style="width: 200px;">
            <input type="search" class="form-control border-0" placeholder="Search features" aria-label="Search">
            <span class="input-group-text bg-transparent border-0">
                <i class="fas fa-search text-muted"></i>
            </span>
        </div>
    </form>
</div>

<div class="row g-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
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
