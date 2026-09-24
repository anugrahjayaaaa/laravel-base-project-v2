<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    protected $addHttpCookie = true;

    protected $except = [
        //
    ];

    public function handle($request, \Closure $next)
    {
        // Debug: verify custom middleware is loaded
        error_log('CUSTOM VerifyCsrfToken handle() called, runningUnitTests='.($this->runningUnitTests() ? 'true' : 'false'));

        return parent::handle($request, $next);
    }

    protected function runningUnitTests(): bool
    {
        return false;
    }
}
