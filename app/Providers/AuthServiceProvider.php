<?php

namespace App\Providers;

use App\Auth\LoginThrottle;
use App\Models\SystemSetting;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

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
        $this->configureRateLimiters();
        $this->configureEmailVerification();
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
            $limit = SystemSetting::getInt('auth_login_rate_limit_per_minute', 5);

            return Limit::perMinute($limit)
                ->by($throttle->key('login', $identifier, $request->ip()))
                ->response(function (Request $request, array $headers) {
                    $retryAfter = $headers['Retry-After'] ?? 60;
                    if ($request->expectsJson()) {
                        return response()->json([
                            'message' => 'Too many requests. Please try again later.',
                            'code' => 'RATE_LIMITED',
                            'retry_after_seconds' => $retryAfter,
                        ], 429);
                    }
                    return back()->withErrors(['identifier' => 'Too many attempts. Please try again later.'])
                        ->withInput($request->only('identifier'))
                        ->with('rate_limit_seconds', $retryAfter);
                });
        });

        RateLimiter::for('forgot-password', function (Request $request) use ($throttle) {
            $identifier = $request->input('email', $request->input('username', ''));
            $limit = SystemSetting::getInt('auth_password_forgot_rate_limit', 3);

            return Limit::perMinute($limit)
                ->by($throttle->key('forgot-password', $identifier, $request->ip()))
                ->response(function (Request $request, array $headers) {
                    $retryAfter = $headers['Retry-After'] ?? 60;
                    if ($request->expectsJson()) {
                        return response()->json([
                            'message' => 'Too many requests. Please try again later.',
                            'code' => 'RATE_LIMITED',
                            'retry_after_seconds' => $retryAfter,
                        ], 429);
                    }
                    return back()->withErrors(['email' => 'Too many attempts. Please try again later.'])
                        ->withInput($request->only('email'))
                        ->with('rate_limit_seconds', $retryAfter);
                });
        });

        RateLimiter::for('resend-verification', function (Request $request) use ($throttle) {
            $identifier = $request->user()
                ? (string) $request->user()->getKey()
                : $request->input('email', '');
            $limit = SystemSetting::getInt('auth_email_verification_rate_limit', 5);

            return Limit::perHour($limit)
                ->by($throttle->key('resend-verification', $identifier, $request->ip()))
                ->response(function (Request $request, array $headers) {
                    $retryAfter = $headers['Retry-After'] ?? 60;
                    if ($request->expectsJson()) {
                        return response()->json([
                            'message' => 'Too many requests. Please try again later.',
                            'code' => 'RATE_LIMITED',
                            'retry_after_seconds' => $retryAfter,
                        ], 429);
                    }
                    return back()->withErrors(['email' => 'Too many attempts. Please try again later.'])
                        ->withInput($request->only('email'))
                        ->with('rate_limit_seconds', $retryAfter);
                });
        });

        RateLimiter::for('reset-password', function (Request $request) use ($throttle) {
            $identifier = $request->input('email', '');
            $limit = SystemSetting::getInt('auth_password_reset_rate_limit', 3);

            return Limit::perMinute($limit)
                ->by($throttle->key('reset-password', $identifier, $request->ip()))
                ->response(function (Request $request, array $headers) {
                    $retryAfter = $headers['Retry-After'] ?? 60;
                    if ($request->expectsJson()) {
                        return response()->json([
                            'message' => 'Too many requests. Please try again later.',
                            'code' => 'RATE_LIMITED',
                            'retry_after_seconds' => $retryAfter,
                        ], 429);
                    }
                    return back()->withErrors(['email' => 'Too many attempts. Please try again later.'])
                        ->withInput($request->only('email'))
                        ->with('rate_limit_seconds', $retryAfter);
                });
        });

        RateLimiter::for('email-verification', function (Request $request) use ($throttle) {
            $identifier = $request->user()
                ? (string) $request->user()->getKey()
                : $request->ip();
            $limit = SystemSetting::getInt('auth_email_verification_rate_limit', 5);

            return Limit::perHour($limit)
                ->by($throttle->key('email-verification', $identifier, $request->ip()));
        });
    }

    /**
     * Configure email verification (60-minute signed URL).
     */
    protected function configureEmailVerification(): void
    {
        VerifyEmail::toMailUsing(function ($notifiable) {
            return (new \Illuminate\Notifications\Messages\MailMessage)
                ->subject('Verify Email Address')
                ->line('Please click the link below to verify your email address.')
                ->action('Verify Email', URL::signedRoute(
                    'verification.verify',
                    [
                        'id' => $notifiable->getKey(),
                        'hash' => sha1($notifiable->getEmailForVerification()),
                    ],
                    now()->addMinutes(
                        (int) SystemSetting::getInt('auth_verification_expire_minutes', 60)
                    )
                ))
                ->line('This link will expire in 60 minutes.')
                ->line('If you did not create an account, no further action is required.');
        });
    }
}
