<?php

use App\Exceptions\LastSuperadminException;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\CheckAccountState;
use App\Http\Middleware\EnsureFeatureIsEnabled;
use App\Http\Middleware\EnsurePasswordChangeRequired;
use App\Http\Middleware\GenerateRequestCorrelationId;
use App\Http\Middleware\VerifyCsrfToken;
use App\Providers\AuthServiceProvider;
use App\Support\ApiError;
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

        // Appended to GLOBAL middleware, not to a group: these are decisions
        // about this origin — HSTS, nosniff, referrer and permissions apply to
        // the JSON API and to the streamed CSV exactly as they apply to a page.
        // The CSP itself stays HTML-only inside the class, which is why moving
        // the registration here does not put a policy on JSON.
        //
        // Registered once and only here. A second registration (this plus the
        // web group) would run the class twice per request: the outer run
        // overwrites the nonce in the header after the view was rendered with
        // the inner one, and every page would be served a policy its scripts do
        // not match.
        $middleware->append(AddSecurityHeaders::class);

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
            // Debug adds a key; it never changes the shape. The envelope is the
            // contract, and a body whose keys depend on an env var is not one:
            // a client cannot branch on a payload a deploy can silently rewrite.
            // Traces stay in the log, where they belong — `report()` still runs.
            $detail = static fn (array $extra = []): array => config('app.debug')
                ? $extra + ['debug' => ['exception' => $e::class, 'message' => $e->getMessage()]]
                : $extra;

            if ($e instanceof TokenMismatchException) {
                return ApiError::make('CSRF token mismatch.', 'CSRF_TOKEN_MISMATCH', 419, $detail());
            }

            // A conflict, not a server fault: the payload was well-formed, it just
            // asked for something the app must not do. Handled ahead of the blanket
            // API handler below, which would otherwise turn it into a 500.
            if ($e instanceof LastSuperadminException) {
                if ($request->is('api/*') || $request->expectsJson()) {
                    return ApiError::make($e->getMessage(), 'LAST_SUPERADMIN', 409, $detail());
                }

                return back()->with('error', $e->getMessage());
            }

            if ($request->is('api/*')) {
                if ($e instanceof ValidationException) {
                    return ApiError::make('Validation failed.', 'VALIDATION_ERROR', 422, $detail([
                        'errors' => $e->errors(),
                    ]));
                }

                if ($e instanceof AuthenticationException) {
                    return ApiError::make('Unauthenticated.', 'UNAUTHENTICATED', 401, $detail());
                }

                if ($e instanceof InvalidSignatureException) {
                    // Unsigned URL (no signature param) → controller would reject token as fake/missing → 400
                    // Tampered signed URL (has signature param) → 403
                    if ($request->has('signature')) {
                        return ApiError::make('Invalid signature.', 'INVALID_SIGNATURE', 403, $detail());
                    }

                    return ApiError::make('Invalid or missing verification token.', 'INVALID_TOKEN', 400, $detail());
                }

                if ($e instanceof HttpResponseException) {
                    return null;
                }

                // Without this, an authorization failure on an api/* route falls through
                // to the blanket 500 below — the caller is told the server broke rather
                // than that they are not allowed, and a 403-based client cannot tell
                // "retry later" from "never". Must sit above that handler.
                if ($e instanceof AuthorizationException || $e instanceof AccessDeniedHttpException) {
                    return ApiError::make(
                        $e->getMessage() ?: 'This action is unauthorized.',
                        'FORBIDDEN',
                        403,
                        $detail(),
                    );
                }

                // The specific 404/429 branches must come BEFORE the generic
                // `HttpException 4xx` one: `NotFoundHttpException` and
                // `ThrottleRequestsException` both extend it, so with the
                // generic branch first every route-level 404 answered
                // `HTTP_ERROR` and every framework 429 answered `HTTP_ERROR` —
                // two codes the contract documents and nothing could emit.
                if ($e instanceof ModelNotFoundException) {
                    return ApiError::make('Resource not found.', 'NOT_FOUND', 404, $detail());
                }

                if ($e instanceof NotFoundHttpException) {
                    return ApiError::make('Resource not found.', 'NOT_FOUND', 404, $detail());
                }

                if ($e instanceof ThrottleRequestsException) {
                    return ApiError::make('Too many requests.', 'RATE_LIMITED', 429, $detail());
                }

                // abort(403) throws a plain HttpException, not AccessDeniedHttpException,
                // so the branch above does not catch it and it reached the blanket 500 —
                // telling an API caller the server broke when the truth is they are not
                // allowed. Matched on the status code rather than the class so any other
                // abort(4xx) gets the same treatment instead of each one being a new
                // instance to remember here.
                if ($e instanceof HttpException && $e->getStatusCode() >= 400 && $e->getStatusCode() < 500) {
                    return ApiError::make(
                        $e->getMessage() ?: 'Request could not be completed.',
                        'HTTP_ERROR',
                        $e->getStatusCode(),
                        $detail(),
                    );
                }

                return ApiError::make('Server Error.', 'SERVER_ERROR', 500, $detail());
            }
        });
    })->create();
