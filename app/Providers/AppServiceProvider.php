<?php

namespace App\Providers;

use App\View\Composers\AppMenuComposer;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Bootstrap-based pagination (Laravel defaults to Tailwind)
        Paginator::useBootstrap();
        LengthAwarePaginator::useBootstrap();

        // Shared menu data for the AdminLTE sidebar
        view()->composer('layouts.partials.sidebar', AppMenuComposer::class);
    }
}
