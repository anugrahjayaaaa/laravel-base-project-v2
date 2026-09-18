<?php

namespace App\Http\Controllers\Web\V1\Auth;

use App\Traits\Auth\AuthenticatesUsers;
use App\Traits\Auth\HandlesUserLookup;
use App\Traits\Auth\HandlesPasswordResetFlow;
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
    use AuthenticatesUsers;
    use HandlesUserLookup;
    use HandlesPasswordResetFlow;

    public function login()
    {
        return response()->view('pages.auth.login', ['title' => 'Login']);
    }

    public function handleLogin(LoginRequest $request, LoginThrottle $throttle)
    {
        $validated = $request->validated();
        $identifier = $validated['identifier'];
        $ip = $request->ip();

        $throttleError = $this->checkThrottle($identifier, $ip, $throttle);
        if ($throttleError) {
            return back()->withInput($request->only('identifier'))
                ->withErrors(['identifier' => $throttleError['message']]);
        }

        $user = $this->findUser($identifier, $validated['password']);

        if (! $user) {
            $lockedSeconds = $throttle->recordFailed($identifier, $ip);
            $this->audit('auth.login_failed', null, null, [
                'identifier' => $identifier,
                'ip' => $ip,
                'user_agent' => $request->userAgent(),
                'channel' => 'web',
            ]);
            if ($lockedSeconds > 0) {
                $this->audit('auth.account_locked', null, null, [
                    'identifier' => $identifier,
                    'ip' => $ip,
                    'user_agent' => $request->userAgent(),
                    'channel' => 'web',
                    'lock_duration_seconds' => $lockedSeconds,
                ]);
            }

            return back()->withInput($request->only('identifier'))
                ->withErrors(['identifier' => 'The provided credentials do not match our records.']);
        }

        $accountError = $this->checkAccountState($user);

        if ($accountError) {
            if ($user->is_locked) {
                $minutes = (int) ceil(max($throttle->lockedFor($identifier, $ip), 0) / 60);
                $accountError['message'] = "Your account is locked. Try again in {$minutes} minute(s).";
            }

            $this->audit('auth.login_failed', $user, $user, [
                'identifier' => $identifier,
                'ip' => $ip,
                'user_agent' => $request->userAgent(),
                'channel' => 'web',
            ]);

            return back()->withInput($request->only('identifier'))
                ->withErrors(['identifier' => $accountError['message']]);
        }

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

    public function forgotPassword()
    {
        return response()->view('pages.auth.forgot-password', ['title' => 'Forgot Password']);
    }

    public function sendResetLink(PasswordForgotRequest $request)
    {
        $email = $request->input('email');
        $user = $this->lookupUser($email);

        $this->sendResetLink($email, $user, $request);

        return back()->withInput($request->only('email'))
            ->with('success', 'If the email exists, a reset link has been sent.');
    }

    public function resetPassword()
    {
        return response()->view('pages.auth.reset-password', ['title' => 'Reset Password']);
    }

    public function resetPasswordSubmit(PasswordResetRequest $request)
    {
        $email = $request->input('email');
        $user = $this->lookupUser($email);

        $status = $this->resetPassword($request, $user);
        $this->auditPasswordResetCompleted($status, $user, $request);

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')
                ->with('success', 'Password has been reset. You may now log in.');
        }

        return back()->withInput($request->only('email'))
            ->withErrors(['email' => 'Failed to reset password. Please try again.']);
    }

    public function verifyEmail()
    {
        return response()->view('pages.auth.verify-email', ['title' => 'Verify Email']);
    }

    public function verified()
    {
        return response()->view('pages.auth.verified', ['title' => 'Email Verified']);
    }

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