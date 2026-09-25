<?php

namespace App\Http\Middleware;

use App\Services\InactivityLock;
use App\Services\PasswordExpiry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce that users with expired passwords, forced-change flags,
 * or inactivity locks cannot access normal application routes.
 *
 * Checks: must_change_password = true OR password_expires_at in the past
 *         OR last_activity_at beyond inactivity threshold.
 *
 * Exempts auth lifecycle routes (password change, verification, logout,
 * email resend). Works for both web (302 redirect) and API (403 JSON)
 * via request content negotiation.
 */
class EnsurePasswordChangeRequired
{
    /**
     * Substrings in route names that are exempt from enforcement.
     * Covers both web (password.change) and API (api.v1.auth.password.change) names.
     */
    protected const EXEMPT_SUBSTRINGS = [
        'password.change',
        'password.change.update',
        'verification',
        'email.resend',
        'logout',
    ];

    /**
     * Enforce that users with expired passwords or forced-change flags
     * cannot access normal application routes until they change their password.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $routeName = $request->route()->getName() ?? '';

        // Allow password-change, verification, logout, and resend flows.
        foreach (self::EXEMPT_SUBSTRINGS as $exempt) {
            if (str_contains($routeName, $exempt)) {
                return $next($request);
            }
        }

        // Check inactivity lock first (highest priority — locks the account)
        if (InactivityLock::isInactive($user)) {
            InactivityLock::lock($user);

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Account locked due to inactivity. Contact your administrator.',
                    'code' => 'ACCOUNT_LOCKED_INACTIVITY',
                ], 403);
            }

            return redirect()->route('login')->with('error', 'Account locked due to inactivity.');
        }

        if ($this->shouldForceChange($user)) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Password change required before accessing this resource.',
                    'code' => 'PASSWORD_CHANGE_REQUIRED',
                ], 403);
            }

            return redirect()->route('password.change');
        }

        return $next($request);
    }

    /**
     * Determine if the user must change their password before proceeding.
     *
     * @param User $user
     * @return bool
     */
    protected function shouldForceChange($user): bool
    {
        if ($user->must_change_password) {
            return true;
        }

        return PasswordExpiry::isExpired($user);
    }
}
