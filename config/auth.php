<?php

use App\Models\User;
use Illuminate\Http\Middleware\Authenticate;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    */

    'defaults' => [
        'guard' => env('GUARD', 'web'),
        'passwords' => env('PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        'api' => [
            'driver' => 'sanctum',
            'provider' => 'users',
            'middleware' => [
                'authenticate_session' => EnsureFrontendRequestsAreStateful::class,
                'encrypt' => true,
                'precision' => 0,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to assign to any extra authentication guards for your app.
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('MODEL', User::class),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | You may specify the expiration time in minutes for password reset
    | tokens. This prevents a user from receiving a reset token that
    | has been sitting unused for too long. The throttle setting
    | limits how often a user may request a reset link.
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => env('PASSWORD_RESET_EXPIRE_MINUTES', 15),
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    */

    'password_timeout' => env('PASSWORD_TIMEOUT', 10800),

];
