@extends('layouts.app', ['title' => 'Welcome'])

@section('content')
<div class="container-fluid py-4">
    <h1 class="page-title mb-1">Welcome</h1>
    <p class="page-description mb-4">Laravel Base Project v2 — AdminLTE 4.9.1 foundation is active.</p>

    <div class="card">
        <div class="card-body">
            <h5 class="card-title mb-3">Application Status</h5>
            <p class="text-muted mb-0">Laravel {{ app()->version() }} is running.</p>
        </div>
    </div>
</div>
@endsection
