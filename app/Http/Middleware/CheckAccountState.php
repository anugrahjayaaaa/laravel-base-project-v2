<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block inactive or locked users from accessing the application.
 *
 * If a user is deactivated (is_active = false) or locked (is_locked = true)
 * by admin, all their sessions and API tokens are revoked and every
 * subsequent request is rejected with 401 (API) or redirect to login (web).
 */
class CheckAccountState
{
    protected const EXEMPT_SUBSTRINGS = [
        'login',
        'logout',
        'register',
        'password',
        'verification',
        'email.resend',
    ];

    /**
     * Block inactive or locked users from accessing the application.
     *
     * Revokes sessions/tokens and rejects with 401 (API) or
     * redirect to login (web) for deactivated/locked/trashed users.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $routeName = $request->route()->getName() ?? '';

        foreach (self::EXEMPT_SUBSTRINGS as $exempt) {
            if (str_contains($routeName, $exempt)) {
                return $next($request);
            }
        }

        if (! $user->is_active || $user->is_locked || $user->trashed()) {
            // Revoke all sessions + tokens immediately
            $user->tokens()->delete();
            DB::table('sessions')
                ->where('user_id', $user->id)
                ->delete();

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Account is inactive or locked. Contact administrator.',
                    'code' => 'ACCOUNT_DISABLED',
                ], 403);
            }

            auth()->logout();
            return redirect()->route('login')
                ->with('error', 'Your account has been deactivated or locked. Contact administrator.');
        }

        return $next($request);
    }
}
