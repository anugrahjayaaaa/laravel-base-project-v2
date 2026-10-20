<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the generated API documentation (`/docs/api`, `/docs/api.json`).
 *
 * Replaces Scramble's `RestrictedDocsAccess` in `config/scramble.php` for one
 * reason: the vendor middleware is `environment('local') || Gate::allows(...)`,
 * so its first half is a bypass that ignores whatever the operator configured.
 * Here the app decides, in one place, and local is not special.
 *
 * The whole authorization question is the `viewApiDocs` gate — which is
 * `api_docs_public` OR the `api_docs.view` permission (see
 * `AuthServiceProvider::configureApiDocsGate()`). This class adds only what a
 * gate cannot express: a guest who is refused because the docs are private gets
 * the login page, not a 403. A guest asking for the document itself gets 401,
 * because a machine-readable spec has no login form to be redirected to.
 *
 * The sidebar reads the same gate (`AppMenuComposer`), so the menu entry and the
 * route answer together: public mode shows the link to everyone, private mode to
 * permission holders only.
 */
class RestrictApiDocsAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Gate::allows('viewApiDocs')) {
            return $next($request);
        }

        if ($request->user() === null) {
            if ($request->is('docs/api.json') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'code' => 'UNAUTHENTICATED',
                ], 401);
            }

            return redirect()->guest(route('login'));
        }

        abort(403);
    }
}
