<?php

namespace App\Providers;

use App\Auth\LoginThrottle;
use App\Models\SystemSetting;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\SystemRole;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

/**
 * Authentication service provider.
 */
class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     */
    protected $policies = [
        User::class => UserPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        ThrottleRequests::shouldHashKeys(false);
        $this->configureSuperAdmin();
        $this->configureRateLimiters();
        $this->configurePasswordResetNotification();
        $this->configureEmailVerification();
    }

    /**
     * Let superadmin pass every Gate check.
     *
     * The superadmin role holds no rows in role_has_permissions — its access
     * lives here instead, so adding a permission to PermissionCatalog never
     * requires re-seeding the role and editing a role's permission set can
     * never strip a superadmin of a capability.
     *
     * MUST return null when the user is not a superadmin, never false. Gate
     * treats a non-null return as a final answer and stops: returning false
     * would short-circuit every policy in the app and deny all users, not just
     * this one. null means "no opinion, keep going" and lets the normal
     * permission/policy path run.
     *
     * Before, not after: a policy that must still apply to a superadmin (see
     * UserPolicy) can be expressed explicitly, but a `before` returning true
     * wins over it. Anything of that kind belongs in the policy, not here.
     */
    protected function configureSuperAdmin(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            return $user->hasRole(SystemRole::SUPERADMIN) ? true : null;
        });
    }

    /**
     * Configure rate limiters for auth endpoints.
     *
     * RateLimiter key format: rate_limit:{feature}:{identifier_type}:{slug}:{ip}
     *   - login:       rate_limit:login:email:<base64_email>:ip
     *   - forgot-pass: rate_limit:forgot-password:email:<base64_email>:ip
     *   - reset-pass:  rate_limit:reset-password:email:<base64_email>:ip
     *   - resend-ver:  rate_limit:resend-verification:user_id:<id>:ip
     */
    protected function configureRateLimiters(): void
    {
        $throttle = app(LoginThrottle::class);

        RateLimiter::for('login', function (Request $request) use ($throttle) {
            $identifier = $request->input('identifier', '');
            $limit = SystemSetting::getInt('login_rate_limit_per_minute', 5);

            return Limit::perMinute($limit)
                ->by($throttle->key('login', $identifier, $request->ip()))
                ->response($throttle->responseFor('identifier'));
        });

        RateLimiter::for('forgot-password', function (Request $request) use ($throttle) {
            $identifier = $request->input('email', $request->input('username', ''));
            $limit = SystemSetting::getInt('password_forgot_rate_limit', 3);

            return Limit::perMinute($limit)
                ->by($throttle->key('forgot-password', $identifier, $request->ip()))
                ->response($throttle->responseFor('email'));
        });

        // Registration is a public write endpoint: it creates accounts and sends
        // mail, so it is keyed per IP alone. The unique-email error on the form
        // tells an attacker which addresses exist; this caps how fast they can
        // collect that.
        RateLimiter::for('register', function (Request $request) use ($throttle) {
            $limit = SystemSetting::getInt('registration_rate_limit_per_minute', 3);

            return Limit::perMinute($limit)
                ->by($throttle->key('register', 'ip', $request->ip()))
                ->response($throttle->responseFor('email'));
        });

        RateLimiter::for('resend-verification', function (Request $request) use ($throttle) {
            $identifier = $request->user()
                ? (string) $request->user()->getKey()
                : $request->input('email', '');
            $limit = SystemSetting::getInt('email_verification_rate_limit', 5);

            return Limit::perHour($limit)
                ->by($throttle->key('resend-verification', $identifier, $request->ip()))
                ->response($throttle->responseFor('email'));
        });

        RateLimiter::for('reset-password', function (Request $request) use ($throttle) {
            $identifier = $request->input('email', '');
            $limit = SystemSetting::getInt('password_reset_rate_limit', 3);

            return Limit::perMinute($limit)
                ->by($throttle->key('reset-password', $identifier, $request->ip()))
                ->response($throttle->responseFor('email'));
        });

        RateLimiter::for('email-verification', function (Request $request) use ($throttle) {
            $identifier = $request->user()
                ? (string) $request->user()->getKey()
                : $request->ip();
            $limit = SystemSetting::getInt('email_verification_rate_limit', 5);

            return Limit::perHour($limit)
                ->by($throttle->key('email-verification', $identifier, $request->ip()))
                ->response($throttle->responseFor('email'));
        });
    }

    /**
     * Configure the password reset email so its stated lifetime matches the
     * one the broker will actually enforce.
     *
     * Laravel's default reset mail never mentions a duration, so an admin who
     * changed "Reset Link Lifetime" had no way to tell the new value applied —
     * the email looked identical at 5 minutes and at 4 hours. The number here
     * is read from the same config the broker uses, so the email and the
     * enforcement cannot drift apart.
     *
     * `toMailUsing` rather than a `ResetPasswordNotification` subclass: it is
     * the same extension point `configureEmailVerification()` below already
     * uses, so the two flows are wired the same way.
     */
    protected function configurePasswordResetNotification(): void
    {
        ResetPassword::toMailUsing(function ($notifiable, string $token) {
            $minutes = (int) config('auth.passwords.users.expire');

            return (new MailMessage())
                ->subject('Reset Password Notification')
                ->line('You are receiving this email because we received a password reset request for your account.')
                // No `email` in the query string, deliberately. The broker
                // dispatches to an `AnonymousNotifiable`, which has no
                // `getEmailForPasswordReset()`, so including it would read the
                // address from the URL — which is attacker-controlled on the
                // reset form. Laravel's own reset link omits it for that reason.
                ->action('Reset Password', route('password.reset', [
                    'token' => $token,
                ], false))
                ->line("This password reset link will expire in {$minutes} minutes.")
                ->line('If you did not request a password reset, no further action is required.');
        });
    }

    /**
     * Configure email verification (60-minute signed URL).
     */
    protected function configureEmailVerification(): void
    {
        VerifyEmail::toMailUsing(function ($notifiable) {
            $minutes = SystemSetting::getInt('email_verification_expire_minutes', 60);

            return (new MailMessage())
                ->subject('Verify Email Address')
                ->line('Please click the link below to verify your email address.')
                ->action('Verify Email', URL::signedRoute(
                    'verification.verify',
                    [
                        'id' => $notifiable->getKey(),
                        'hash' => sha1($notifiable->getEmailForVerification()),
                    ],
                    now()->addMinutes($minutes)
                ))
                // Reads the same value the signature was built from. This line
                // was hardcoded at 60, so an admin who set 15 got a link that
                // died in 15 and an email promising 60.
                ->line("This link will expire in {$minutes} minutes.")
                ->line('If you did not create an account, no further action is required.');
        });
    }
}
