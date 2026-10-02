<?php

namespace App\Http\Controllers\Web\V1\Auth;

use App\Actions\V1\Auth\AuthAuthenticateAction;
use App\Actions\V1\Auth\AuthListSessionsAction;
use App\Actions\V1\Auth\AuthLoginCompletedAction;
use App\Actions\V1\Auth\AuthLogoutAction;
use App\Actions\V1\Auth\AuthLogoutAllDevicesAction;
use App\Actions\V1\Auth\AuthResendVerificationAction;
use App\Actions\V1\Auth\AuthSendResetLinkAction;
use App\Actions\V1\Auth\AuthResetPasswordAction;
use App\Actions\V1\Auth\AuthVerifyEmailAction;
use App\Actions\V1\User\UserCreateAction;
use App\Models\SystemSetting;
use App\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\LoginRequest;
use App\Http\Requests\V1\Auth\PasswordForgotRequest;
use App\Http\Requests\V1\Auth\PasswordResetRequest;
use App\Http\Requests\V1\Auth\RegisterRequest;
use App\Http\Requests\V1\Auth\ResendVerificationRequest;
use App\Auth\LoginThrottle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;

/**
 * Web auth controller,login, logout, password reset, email verification.
 */
class AuthController extends Controller
{
    /**
     * @param  AuthListSessionsAction  $listSessionsAction
     */
    public function __construct(
        private readonly AuthListSessionsAction $listSessionsAction,
    ) {
    }

    // === VIEW: Login ===

    /**
     * Show the login page.
     *
     * @return \Illuminate\Http\Response
     */
    public function showLogin()
    {
        return response()->view('pages.auth.login', [
            'title' => 'Login',
            // The register link is hidden rather than dead: a link to a route
            // that 404s is worse than no link.
            'registrationEnabled' => SystemSetting::getBool('registration_enabled', false),
        ]);
    }

    // === LOGIC: Login ===

    /**
     * Handle user login.
     *
     * @param  LoginRequest  $request
     * @param  LoginThrottle  $throttle
     * @param  AuthAuthenticateAction  $action
     * @return \Illuminate\Http\RedirectResponse
     */
    public function login(LoginRequest $request, LoginThrottle $throttle, AuthAuthenticateAction $action, AuthLoginCompletedAction $loginCompletedAction)
    {
        $data = $request->validated();
        $identifier = $data['identifier'];
        $ip = $request->ip();

        $result = $action->run($identifier, $data['password'], $ip, $throttle);

        if (isset($result['error'])) {
            if (($result['error']['error_code'] ?? null) === 'UNVERIFIED_EMAIL') {
                return redirect()->route('verification.notice')
                    ->with('error', $result['error']['message']);
            }

            return back()->withInput($request->only('identifier'))
                ->withErrors(['identifier' => $result['error']['message']]);
        }

        $loginCompletedAction->run($result['user'], $request->boolean('remember'));

        return redirect()->intended('/dashboard');
    }

    // === VIEW: Forgot Password ===

    /**
     * Show the forgot password page.
     *
     * @return \Illuminate\Http\Response
     */
    public function showForgotPassword()
    {
        return response()->view('pages.auth.forgot-password', ['title' => 'Forgot Password']);
    }

    // === LOGIC: Send Password Reset Link ===

    /**
     * Send a password reset link to the given email.
     *
     * @param  PasswordForgotRequest  $request
     * @param  LoginThrottle  $throttle
     * @param  AuthSendResetLinkAction  $action
     * @return \Illuminate\Http\RedirectResponse
     */
    public function sendPasswordResetLink(PasswordForgotRequest $request, LoginThrottle $throttle, AuthSendResetLinkAction $action)
    {
        $data = $request->validated();
        $email = $data['email'];
        $ip = $request->ip();

        $result = $action->run($email, $ip, $request, $throttle);

        if (isset($result['error'])) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => $result['error']['message']]);
        }

        return back()->withInput($request->only('email'))
            ->with('status', 'If the email exists, a reset link has been sent.');
    }

    // === VIEW: Reset Password ===

    /**
     * Show the reset password page.
     *
     * @return \Illuminate\Http\Response
     */
    public function showResetPassword()
    {
        return response()->view('pages.auth.reset-password', ['title' => 'Reset Password']);
    }

    // === LOGIC: Reset User Password ===

    /**
     * Reset the user's password.
     *
     * @param  PasswordResetRequest  $request
     * @param  LoginThrottle  $throttle
     * @param  AuthResetPasswordAction  $action
     * @return \Illuminate\Http\RedirectResponse
     */
    public function resetUserPassword(PasswordResetRequest $request, LoginThrottle $throttle, AuthResetPasswordAction $action)
    {
        $data = $request->validated();
        $email = $data['email'];
        $ip = $request->ip();

        $result = $action->run($email, $ip, $request, $throttle);

        if (isset($result['error'])) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => $result['error']['message']]);
        }

        if ($result['status'] === Password::PASSWORD_RESET) {
            return redirect()->route('login')
                ->with('status', 'Password has been reset. You may now log in.');
        }

        return back()->withInput($request->only('email'))
            ->withErrors(['email' => 'Failed to reset password. Please try again.']);
    }

    // === VIEW: Verify Email ===

    /**
     * Show the verify email page.
     *
     * @return \Illuminate\Http\Response
     */
    public function showVerifyEmail()
    {
        $mode = SystemSetting::getString('email_verification_mode', 'public');

        return response()->view('pages.auth.verify-email', ['title' => 'Verify Email', 'mode' => $mode]);
    }

    /**
     * Verify the user's email address.
     *
     * @param  AuthVerifyEmailAction  $action
     * @return \Illuminate\Http\RedirectResponse
     */
    public function verifyEmail(AuthVerifyEmailAction $action)
    {
        // Deliberately not gated on email_verification_mode. That setting decides
        // who may SEND a verification link, not who may use one. A link already
        // in an inbox is a capability, and gating it stranded every account whose
        // first link arrived before the operator switched modes.
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

        if ($isNewUser) {
            return redirect()->route('login')
                ->with('success', 'Email verified. Please log in.');
        }

        return redirect()->route('verification.notice')
            ->with('success', 'Email verified successfully.');
    }

    /**
     * Resend a verification email to the user.
     *
     * @param  AuthResendVerificationAction  $action
     * @param  ResendVerificationRequest  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function resendVerification(AuthResendVerificationAction $action, ResendVerificationRequest $request)
    {
        $mode = SystemSetting::getString('email_verification_mode', 'public');

        if ($mode === 'admin' || $mode === 'disabled') {
            return back()->withErrors(['error' => 'Feature disabled.']);
        }

        $data = $request->validated();
        $email = $data['email'];

        $result = $action->run($email, $request->ip());

        if (isset($result['error'])) {
            return back()->withErrors(['error' => $result['error']['message']]);
        }

        return back()->with('success', 'If the email is registered, a verification link has been sent.');
    }

    // === VIEW: Register ===

    /**
     * Show the self-registration page.
     *
     * @return \Illuminate\Http\Response
     */
    public function showRegister()
    {
        abort_unless(SystemSetting::getBool('registration_enabled', false), 404);

        return response()->view('pages.auth.register', [
            'title' => 'Register',
        ]);
    }

    // === LOGIC: Register ===

    /**
     * Create a self-registered account and email a verification link.
     *
     * No session is started: the account is unverified, and letting it sign in
     * would only lead to the `verified` middleware bouncing it straight back
     * out with a less clear message.
     *
     * @param  RegisterRequest  $request
     * @param  UserCreateAction  $action
     * @return \Illuminate\Http\RedirectResponse
     */
    public function register(RegisterRequest $request, UserCreateAction $action)
    {
        abort_unless(SystemSetting::getBool('registration_enabled', false), 404);

        $data = $request->validated();
        $action->run($data, $request->password());

        return redirect()->route('login')
            ->with('success', 'Account created. Check your email to verify it before logging in.');
    }

    // === VIEW: Sessions ===

    /**
     * Show the active sessions page.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\Response
     */
    public function showSessions(Request $request)
    {
        $tokens = $this->listSessionsAction->run($request->user());

        return response()->view('pages.sessions', [
            'title' => 'Active Sessions',
            'tokens' => $tokens,
        ]);
    }

    // === LOGIC: Logout All Devices ===

    /**
     * Log out all devices for the current user.
     *
     * @param  Request  $request
     * @param  AuthLogoutAllDevicesAction  $action
     * @return \Illuminate\Http\RedirectResponse
     */
    public function logoutAllDevices(Request $request, AuthLogoutAllDevicesAction $action)
    {
        $action->run($request->user());

        Auth::logout();

        return redirect('/login');
    }

    // === LOGIC (no view) ===

    /**
     * Log out the current session.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function logout(Request $request, AuthLogoutAction $action)
    {
        $action->run($request);

        return redirect('/login');
    }
}
