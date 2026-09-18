@extends('layouts.auth', ['title' => 'Email Verified'])

@section('content')
<div class="auth-card mx-auto" style="max-width: 420px;">
    <div class="card shadow-sm">
        <div class="card-body p-4 p-md-5 text-center">
            <div class="auth-icon mb-3">
                <i class="fas fa-circle-check text-success" style="font-size: 3rem;"></i>
            </div>
            <h1 class="page-title mb-2">Email Verified Successfully</h1>
            <p class="text-muted small mb-4">Your email has been verified. You can now log in.</p>
            <a href="{{ url('/login') }}" class="btn btn-primary">Go to Login</a>
        </div>
    </div>
</div>
@endsection