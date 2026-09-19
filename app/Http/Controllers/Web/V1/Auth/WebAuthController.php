<?php

namespace App\Http\Controllers\Web\V1\Auth;

use App\Actions\Auth\AuthenticateUserAction;
use App\Actions\Auth\SendPasswordResetLinkAction;
use App\Actions\Auth\ResetPasswordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\PasswordForgotRequest;
use App\Http\Requests\Auth\PasswordResetRequest;
use App\Auth\LoginThrottle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;

class WebAuthController extends Controller
{
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

        $result = $action->run($identifier, $data['password'], $ip, $throttle);

        if (isset($result['error'])) {
            if (isset($result['lockedSeconds']) && $result['lockedSeconds'] > 0) {
                $this->audit('auth.login_failed', null, null, [
                    'identifier' => $identifier,
                    'ip' => $ip,
                    'user_agent' => $request->userAgent(),
                    'channel' => 'web',
                ]);
                $this->audit('auth.account_locked', null, null, [
                    'identifier' => $identifier,
                    'ip' => $ip,
                    'user_agent' => $request->userAgent(),
                    'channel' => 'web',
                    'lock_duration_seconds' => $result['lockedSeconds'],
                ]);
            } else {
                $this->audit('auth.login_failed', null, null, [
                    'identifier' => $identifier,
                    'ip' => $ip,
                    'user_agent' => $request->userAgent(),
                    'channel' => 'web',
                ]);
            }

            return back()->withInput($request->only('identifier'))
                ->withErrors(['identifier' => $result['error']['message']]);
        }

        $user = $result['user'];

        if (! $user->email_verified_at) {
            return redirect()->route('verification.notice')
                ->with('error', 'Please verify your email before logging in.');
        }

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

        $result = $action->run($email, $ip, $request, $throttle);

        if (isset($result['error'])) {
            $this->audit('auth.password_reset_requested', $result['user'], $result['user'], [
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
            ->with('success', 'If the email exists, a reset link has been sent.');
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
                ->with('success', 'Password has been reset. You may now log in.');
        }

        return back()->withInput($request->only('email'))
            ->withErrors(['email' => 'Failed to reset password. Please try again.']);
    }

    // === VIEW: Verify Email ===

    public function showVerifyEmail()
    {
        return response()->view('pages.auth.verify-email', ['title' => 'Verify Email']);
    }

    public function showVerified()
    {
        return response()->view('pages.auth.verified', ['title' => 'Email Verified']);
    }

    // === LOGIC (no view) ===

    public function resendVerification()
    {
        $user = auth()->user();
        $user->sendEmailVerificationNotification();

        $this->audit('auth.verification_resent', $user, $user);

        return back()->with('success', 'Verification email resent.');
    }

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