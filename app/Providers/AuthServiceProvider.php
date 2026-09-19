<?php

namespace App\Providers;

use App\Auth\LoginThrottle;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
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
        $this->configureRateLimiters();
        $this->configureEmailVerification();
    }

    /**
     * Configure rate limiters for auth endpoints.
     */
    protected function configureRateLimiters(): void
    {
        // Login: transport-level throttle per IP + identifier.
        // This complements the DB-based progressive lockout.
        RateLimiter::for('login', function (Request $request) {
            $identifier = $request->input('identifier', '');
            $ip = $request->ip();

            $limit = (int) config('rate_limits.login.rate_limit_per_minute', 5);

            return Limit::perMinute($limit)
                ->by(app(LoginThrottle::class)->key($identifier, $ip))
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many login attempts. Please try again later.',
                        'code' => 'RATE_LIMITED',
                    ], 429);
                });
        });

        // Forgot password: 3/min per identifier.
        RateLimiter::for('forgot-password', function (Request $request) {
            $identifier = $request->input('email', $request->input('username', ''));
            $limit = (int) config('rate_limits.password_forgot.rate_limit_per_minute', 3);

            return Limit::perMinute($limit)
                ->by(app(LoginThrottle::class)->key($identifier, $request->ip()))
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many password reset requests. Please try again later.',
                        'code' => 'RATE_LIMITED',
                    ], 429);
                });
        });

        // Resend verification: 5/hour per identifier.
        RateLimiter::for('resend-verification', function (Request $request) {
            $identifier = $request->user()
                ? $request->user()->email
                : $request->input('email', '');

            $limit = (int) config('rate_limits.email_verification.rate_limit_per_hour', 5);

            return Limit::perHour($limit)
                ->by(app(LoginThrottle::class)->key($identifier, $request->ip()))
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many verification email requests. Please try again later.',
                        'code' => 'RATE_LIMITED',
                    ], 429);
                });
        });

        // Reset password: 3/min per identifier.
        RateLimiter::for('reset-password', function (Request $request) {
            $identifier = $request->input('email', '');
            $limit = (int) config('rate_limits.password_reset.rate_limit_per_minute', 3);

            return Limit::perMinute($limit)
                ->by(app(LoginThrottle::class)->key($identifier, $request->ip()))
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many password reset attempts. Please try again later.',
                        'code' => 'RATE_LIMITED',
                    ], 429);
                });
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
                        (int) config('auth.verification.expire_minutes', 60)
                    )
                ))
                ->line('This link will expire in 60 minutes.')
                ->line('If you did not create an account, no further action is required.');
        });
    }
}
