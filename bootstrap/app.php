<?php

use App\Http\Middleware\CheckAccountState;
use App\Http\Middleware\EnsurePasswordChangeRequired;
use App\Http\Middleware\GenerateRequestCorrelationId;
use App\Http\Middleware\VerifyCsrfToken;
use App\Providers\AuthServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        api: __DIR__.'/../routes/api.php',
    )
    ->withProviders([
        AuthServiceProvider::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Generate and propagate correlation/request ID on every request.
        $middleware->append(GenerateRequestCorrelationId::class);

        // Append custom CSRF middleware to web group (enforces CSRF in tests).
        $middleware->appendToGroup('web', VerifyCsrfToken::class);

        // Aliases for middleware used in route definitions.
        $middleware->alias([
            'password.change.required' => EnsurePasswordChangeRequired::class,
            'account.state' => CheckAccountState::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->renderable(function (Throwable $e, Request $request) {
        if ($e instanceof TokenMismatchException) {
            return response()->json([
                'message' => 'CSRF token mismatch.',
                'code' => 'CSRF_TOKEN_MISMATCH',
            ], 419);
        }

        if ($request->is('api/*') && ! config('app.debug')) {
        if ($e instanceof ValidationException) {
            return response()->json([
                'message' => 'Validation failed.',
                'code' => 'VALIDATION_ERROR',
                'errors' => $e->errors(),
            ], 422);
        }

        if ($e instanceof AuthenticationException) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        if ($e instanceof InvalidSignatureException) {
            // Unsigned URL (no signature param) → controller would reject token as fake/missing → 400
            // Tampered signed URL (has signature param) → 403
            if ($request->has('signature')) {
                return response()->json([
                    'message' => 'Invalid signature.',
                    'code' => 'INVALID_SIGNATURE',
                ], 403);
            }

            return response()->json([
                'message' => 'Invalid or missing verification token.',
                'code' => 'INVALID_TOKEN',
            ], 400);
        }

        if ($e instanceof HttpResponseException) {
            return null;
        }

        if ($e instanceof ModelNotFoundException) {
            return response()->json([
                'message' => 'Resource not found.',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        if ($e instanceof NotFoundHttpException) {
            return response()->json([
                'message' => 'Resource not found.',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        if ($e instanceof ThrottleRequestsException) {
            return response()->json([
                'message' => 'Too many requests.',
                'code' => 'RATE_LIMITED',
            ], 429);
        }

        return response()->json([
            'message' => 'Server Error.',
            'code' => 'SERVER_ERROR',
        ], 500);
    }
});
    })->create();
