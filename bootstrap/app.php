<?php

use App\Exceptions\LastSuperadminException;
use App\Http\Middleware\CheckAccountState;
use App\Http\Middleware\EnsureFeatureIsEnabled;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
            // Pennant ships an `EnsureFeaturesAreActive` that aborts 400 and
            // resolves through Feature::active(), so it cannot honour a
            // `disabled => true` kill switch. See the class docblock for the
            // measured proof — the two answers disagree.
            'feature' => EnsureFeatureIsEnabled::class,
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

            // A conflict, not a server fault: the payload was well-formed, it just
            // asked for something the app must not do. Handled ahead of the blanket
            // API handler below, which would otherwise turn it into a 500.
            if ($e instanceof LastSuperadminException) {
                if ($request->is('api/*') || $request->expectsJson()) {
                    return response()->json([
                        'message' => $e->getMessage(),
                        'code' => 'LAST_SUPERADMIN',
                    ], 409);
                }

                return back()->with('error', $e->getMessage());
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

                // Without this, an authorization failure on an api/* route falls through
                // to the blanket 500 below — the caller is told the server broke rather
                // than that they are not allowed, and a 403-based client cannot tell
                // "retry later" from "never". Must sit above that handler.
                if ($e instanceof AuthorizationException || $e instanceof AccessDeniedHttpException) {
                    return response()->json([
                        'message' => $e->getMessage() ?: 'This action is unauthorized.',
                        'code' => 'FORBIDDEN',
                    ], 403);
                }

                // abort(403) throws a plain HttpException, not AccessDeniedHttpException,
                // so the branch above does not catch it and it reached the blanket 500 —
                // telling an API caller the server broke when the truth is they are not
                // allowed. Matched on the status code rather than the class so any other
                // abort(4xx) gets the same treatment instead of each one being a new
                // instance to remember here.
                if ($e instanceof HttpException && $e->getStatusCode() >= 400 && $e->getStatusCode() < 500) {
                    return response()->json([
                        'message' => $e->getMessage() ?: 'Request could not be completed.',
                        'code' => 'HTTP_ERROR',
                    ], $e->getStatusCode());
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
