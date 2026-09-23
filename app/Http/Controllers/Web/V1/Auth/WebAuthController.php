<?php

namespace App\Http\Controllers\Web\V1\Auth;

use App\Actions\Auth\AuthenticateUserAction;
use App\Actions\Auth\ListUserSessionsAction;
use App\Actions\Auth\LogoutAllDevicesAction;
use App\Actions\Auth\ResendVerificationAction;
use App\Actions\Auth\SendPasswordResetLinkAction;
use App\Actions\Auth\ResetPasswordAction;
use App\Actions\Auth\VerifyEmailAction;
use App\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\PasswordForgotRequest;
use App\Http\Requests\Auth\PasswordResetRequest;
use App\Http\Requests\Auth\ResendVerificationRequest;
use App\Auth\LoginThrottle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;

class WebAuthController extends Controller
{
    public function __construct(
        private readonly ListUserSessionsAction $listSessionsAction,
    ) {}

    // === VIEW: Login ===

    public function showLogin()
    {
        return response()->view('pages.auth.login', ['title' => 'Login']);
    }

    // === LOGIC: Login ===

    public function login(LoginRequest $request, LoginThrottle $throttle, AuthenticateUserAction $action)
    {
        $data = $request->validated();
        $identifier = $data['identifier'];
        $ip = $request->ip();
        $user = User::where('email', $identifier)->orWhere('username', $identifier)->first();

        $result = $action->run($identifier, $data['password'], $ip, $throttle);

        if (isset($result['error'])) {
            if (isset($result['lockedSeconds']) && $result['lockedSeconds'] > 0) {
                $this->audit('auth.login_failed', $user, $user, [
                    'identifier' => $identifier,
                    'ip' => $ip,
                    'user_agent' => $request->userAgent(),
                    'channel' => 'web',
                ]);

                $this->audit('auth.account_locked', $user, $user, [
                    'identifier' => $identifier,
                    'ip' => $ip,
                    'user_agent' => $request->userAgent(),
                    'channel' => 'web',
                    'lock_duration_seconds' => $result['lockedSeconds'],
                ]);
            } else {
                $this->audit('auth.login_failed', $user, $user, [
                    'identifier' => $identifier,
                    'ip' => $ip,
                    'user_agent' => $request->userAgent(),
                    'channel' => 'web',
                ]);
            }

            if (($result['error']['error_code'] ?? null) === 'UNVERIFIED_EMAIL') {
                return redirect()->route('verification.notice')
                    ->with('error', $result['error']['message']);
            }

            return back()->withInput($request->only('identifier'))
                ->withErrors(['identifier' => $result['error']['message']]);
        }

        $user = $result['user'];

        Auth::login($user, $request->boolean('remember'));

        $user->updateQuietly(['last_activity_at' => now()]);

        $this->audit('auth.login', $user, $user, [
            'ip' => $ip,
            'user_agent' => $request->userAgent(),
            'channel' => 'web',
        ]);

        return redirect()->intended('/dashboard');
    }

    // === VIEW: Forgot Password ===

    public function showForgotPassword()
    {
        return response()->view('pages.auth.forgot-password', ['title' => 'Forgot Password']);
    }

    // === LOGIC: Send Password Reset Link ===

    public function sendPasswordResetLink(PasswordForgotRequest $request, LoginThrottle $throttle, SendPasswordResetLinkAction $action)
    {
        $data = $request->validated();
        $email = $data['email'];
        $ip = $request->ip();
        $user = User::where('email', $email)->first();

        $result = $action->run($email, $ip, $request, $throttle);

        if (isset($result['error'])) {
            $this->audit('auth.password_reset_requested', $user, $user, [
                'email' => $email,
                'ip' => $ip,
            ]);

            return back()->withInput($request->only('email'))
                ->withErrors(['email' => $result['error']['message']]);
        }

        if ($result['user']) {
            $this->audit('auth.password_reset_requested', $result['user'], $result['user'], [
                'ip' => $ip,
            ]);
        }

        return back()->withInput($request->only('email'))
            ->with('status', 'If the email exists, a reset link has been sent.');
    }

    // === VIEW: Reset Password ===

    public function showResetPassword()
    {
        return response()->view('pages.auth.reset-password', ['title' => 'Reset Password']);
    }

    // === LOGIC: Reset User Password ===

    public function resetUserPassword(PasswordResetRequest $request, LoginThrottle $throttle, ResetPasswordAction $action)
    {
        $data = $request->validated();
        $email = $data['email'];
        $ip = $request->ip();

        $result = $action->run($email, $ip, $request, $throttle);

        if (isset($result['error'])) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => $result['error']['message']]);
        }

        if ($result['status'] === Password::PASSWORD_RESET && $result['user']) {
            $this->audit('auth.password_reset_completed', $result['user'], $result['user'], [
                'ip' => $ip,
            ]);
        }

        if ($result['status'] === Password::PASSWORD_RESET) {
            return redirect()->route('login')
                ->with('status', 'Password has been reset. You may now log in.');
        }

        return back()->withInput($request->only('email'))
            ->withErrors(['email' => 'Failed to reset password. Please try again.']);
    }

    // === VIEW: Verify Email ===

    public function showVerifyEmail()
    {
        return response()->view('pages.auth.verify-email', ['title' => 'Verify Email']);
    }

    public function verifyEmail(VerifyEmailAction $action)
    {
        $mode = config('auth.verification.mode', 'public');

        if ($mode === 'admin' || $mode === 'disabled') {
            return redirect()->route('verification.notice')
                ->with('error', 'Feature disabled.');
        }

        $user = User::findOrFail(request()->route('id'));

        if (! hash_equals((string) request()->route('hash'), sha1($user->getEmailForVerification()))) {
            return redirect()->route('verification.notice')
                ->with('error', 'Invalid verification link.');
        }

        // New user: first verification → login. Existing user: already verified → notice page.
        $isNewUser = ! $user->hasVerifiedEmail();

        $result = $action->run($user);

        if (isset($result['error'])) {
            return redirect()->route('verification.notice')
                ->with('error', $result['error']['message']);
        }

        $this->audit('auth.email_verified', $result['user'], $result['user']);

        if ($isNewUser) {
            return redirect()->route('login')
                ->with('success', 'Email verified. Please log in.');
        }

        return redirect()->route('verification.notice')
            ->with('success', 'Email verified successfully.');
    }

    public function resendVerification(ResendVerificationAction $action, ResendVerificationRequest $request)
    {
        $mode = config('auth.verification.mode', 'public');

        if ($mode === 'admin' || $mode === 'disabled') {
            return back()->withErrors(['error' => 'Feature disabled.']);
        }

        $data = $request->validated();
        $email = $data['email'];
        $user = User::where('email', $email)->first();

        $result = $action->run($email, $request->ip());

        if (isset($result['error'])) {
            return back()->withErrors(['error' => $result['error']['message']]);
        }

        $this->audit('auth.verification_resent', $user, $user, [
            'email' => $email,
        ]);

        return back()->with('success', 'If the email is registered, a verification link has been sent.');
    }

    // === VIEW: Sessions ===

    public function showSessions(Request $request)
    {
        $tokens = $this->listSessionsAction->run($request->user());

        return response()->view('pages.sessions', [
            'title' => 'Active Sessions',
            'tokens' => $tokens,
        ]);
    }

    // === LOGIC: Logout All Devices ===

    public function logoutAllDevices(Request $request, LogoutAllDevicesAction $action)
    {
        $user = $request->user();
        $action->run($user);

        $this->audit('auth.logout_all', $user, $user);

        Auth::logout();

        return redirect('/login');
    }

    // === LOGIC (no view) ===

    public function logout(Request $request)
    {
        $user = $request->user();

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $this->audit('auth.logout', $user, $user, [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'channel' => 'web',
        ]);

        return redirect('/login');
    }
}
