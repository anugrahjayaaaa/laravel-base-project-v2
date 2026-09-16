@extends('layouts.app', ['title' => 'Welcome'])

@section('content')
<div class="container-fluid py-4">
    <h1 class="mb-4">Welcome</h1>
    <p class="lead">AdminLTE 4 layout is loaded from vendored release ZIP assets.</p>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Project Status</h3>
        </div>
        <div class="card-body">
            <p class="mb-0">Laravel {{ app()->version() }} is running.</p>
        </div>
    </div>
</div>
@endsection
