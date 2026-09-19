@extends('layouts.guest', ['title' => config('app.name', 'Laravel')])

@section('content')
<div class="container-fluid py-5">

    {{--- Hero ---}}
    <div class="row g-4 justify-content-center text-center">
        <div class="col-12 col-md-10 col-lg-8">
            <span class="badge bg-primary-subtle text-primary mb-3" style="border-radius:9999px; font-size:.75rem; letter-spacing:.05em;">Base Architecture v2.0</span>
            <h1 class="page-title mt-3" style="font-size:2rem; font-weight:600;">Robust Starter Kit for Enterprise Applications</h1>
            <p class="page-description mt-2" style="font-size:1.05rem;">
                Modular structure with layered security, AdminLTE dashboard, and performance-first architecture.
            </p>
            <div class="d-flex gap-3 justify-content-center mt-4 flex-wrap">
                @auth
                    <a href="{{ route('dashboard') }}" class="btn btn-primary" style="border-radius:8px; padding:.5rem 1.5rem; font-weight:500;">Go to Dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="btn btn-primary" style="border-radius:8px; padding:.5rem 1.5rem; font-weight:500;">Get Started</a>
                @endauth
                <a href="https://github.com" class="btn text-decoration-none" style="border-radius:8px; padding:.5rem 1.5rem; font-weight:500; border:1px solid var(--lbp-border); color:var(--lbp-text); background:var(--lbp-surface);">GitHub</a>
            </div>

            {{--- Command Box ---}}
            <div class="mt-5 text-start" style="background:var(--lbp-surface-2); border:1px solid var(--lbp-border); border-radius:10px; padding:1rem 1.25rem; font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace; font-size:.85rem; color:var(--lbp-text); display:inline-block; max-width:100%; overflow-x:auto;">
                <i class="fas fa-terminal me-2" style="color:var(--lbp-muted);"></i><span style="color:var(--lbp-muted);">$</span> composer install && php artisan migrate
            </div>
        </div>
    </div>

    {{--- Feature Grid ---}}
    <div class="row g-4 mt-2">
        <div class="col-12 col-md-6 col-lg-4">
            <div class="card" style="border:1px solid var(--lbp-border); border-radius:10px; transition:box-shadow .15s ease;">
                <div class="card-body text-center py-4">
                    <div class="mx-auto mb-3 d-inline-flex align-items-center justify-content-center" style="width:42px;height:42px;border-radius:10px;background:var(--lbp-surface-2);color:var(--lbp-primary);">
                        <i class="fas fa-shield-halved"></i>
                    </div>
                    <h5 class="card-title mb-2" style="font-weight:600; font-size:.95rem;">Authentication & ACL</h5>
                    <p class="mb-0" style="font-size:.85rem; color:var(--lbp-muted);">Roles, permissions, and session management built in.</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="card" style="border:1px solid var(--lbp-border); border-radius:10px; transition:box-shadow .15s ease;">
                <div class="card-body text-center py-4">
                    <div class="mx-auto mb-3 d-inline-flex align-items-center justify-content-center" style="width:42px;height:42px;border-radius:10px;background:var(--lbp-surface-2);color:var(--lbp-primary);">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h5 class="card-title mb-2" style="font-weight:600; font-size:.95rem;">AdminLTE Dashboard</h5>
                    <p class="mb-0" style="font-size:.85rem; color:var(--lbp-muted);">Clean, responsive admin UI with sidebar and metrics.</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="card" style="border:1px solid var(--lbp-border); border-radius:10px; transition:box-shadow .15s ease;">
                <div class="card-body text-center py-4">
                    <div class="mx-auto mb-3 d-inline-flex align-items-center justify-content-center" style="width:42px;height:42px;border-radius:10px;background:var(--lbp-surface-2);color:var(--lbp-primary);">
                        <i class="fas fa-cubes"></i>
                    </div>
                    <h5 class="card-title mb-2" style="font-weight:600; font-size:.95rem;">Service Layer</h5>
                    <p class="mb-0" style="font-size:.85rem; color:var(--lbp-muted);">Modular structure with dedicated service classes.</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="card" style="border:1px solid var(--lbp-border); border-radius:10px; transition:box-shadow .15s ease;">
                <div class="card-body text-center py-4">
                    <div class="mx-auto mb-3 d-inline-flex align-items-center justify-content-center" style="width:42px;height:42px;border-radius:10px;background:var(--lbp-surface-2);color:var(--lbp-primary);">
                        <i class="fas fa-file-export"></i>
                    </div>
                    <h5 class="card-title mb-2" style="font-weight:600; font-size:.95rem;">Data Export</h5>
                    <p class="mb-0" style="font-size:.85rem; color:var(--lbp-muted);">CSV, Excel, and PDF export utilities included.</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="card" style="border:1px solid var(--lbp-border); border-radius:10px; transition:box-shadow .15s ease;">
                <div class="card-body text-center py-4">
                    <div class="mx-auto mb-3 d-inline-flex align-items-center justify-content-center" style="width:42px;height:42px;border-radius:10px;background:var(--lbp-surface-2);color:var(--lbp-primary);">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <h5 class="card-title mb-2" style="font-weight:600; font-size:.95rem;">Performance & Security</h5>
                    <p class="mb-0" style="font-size:.85rem; color:var(--lbp-muted);">Rate limiting, CSP headers, and query optimization.</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="card" style="border:1px solid var(--lbp-border); border-radius:10px; transition:box-shadow .15s ease;">
                <div class="card-body text-center py-4">
                    <div class="mx-auto mb-3 d-inline-flex align-items-center justify-content-center" style="width:42px;height:42px;border-radius:10px;background:var(--lbp-surface-2);color:var(--lbp-primary);">
                        <i class="fas fa-plug"></i>
                    </div>
                    <h5 class="card-title mb-2" style="font-weight:600; font-size:.95rem;">REST API Ready</h5>
                    <p class="mb-0" style="font-size:.85rem; color:var(--lbp-muted);">API routes, OpenAPI docs, and automation hooks.</p>
                </div>
            </div>
        </div>
    </div>

    {{--- Footer ---}}
    <footer class="mt-5 pt-4 pb-3 border-top" style="border-color:var(--lbp-border);">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2" style="font-size:.8rem; color:var(--lbp-muted);">
            <span>&copy; {{ date('Y') }} {{ config('app.name', 'Laravel') }}</span>
            <span>Laravel v{{ Illuminate\Foundation\Application::VERSION }} &bull; PHP v{{ PHP_VERSION }}</span>
            <span><i class="fas fa-circle-check me-1" style="color:var(--lbp-success);"></i> System Status: Normal</span>
        </div>
    </footer>

</div>
@endsection