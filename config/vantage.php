<?php

/*
|--------------------------------------------------------------------------
| Project overrides
|--------------------------------------------------------------------------
|
| This is the published `storviaio/vantage` config. Two keys are changed from
| the package defaults, and both are security decisions (Phase 11 §13, ADR-022):
|
|   routes        FALSE. The application declares the Vantage web routes in
|                 `routes/web.php` instead, because the package calls
|                 `loadRoutesFrom()` bare — there is no middleware hook — so its
|                 own route group cannot carry `feature:vantage` or
|                 `can:queues.*`. Declaring them is the only way this dashboard
|                 gets the same two gates every other module in the admin has.
|                 Do NOT flip this to true: the vendor group would register a
|                 second copy of every `/vantage` URI.
|
|   store_payload FALSE. The package default is true, which persists full job
|                 payloads — in this project those carry audit-export filters,
|                 model ids and mail arguments. Off unless an operator
|                 deliberately opts in with VANTAGE_STORE_PAYLOAD.
|
|                 Retry does NOT depend on it: `POST /vantage/jobs/{id}/retry`
|                 is answered from `failed_jobs` — the record `queue:retry`
|                 reads — by `App\Support\Vantage\FailedJobPayloadRestorer`
|                 (bound in `AppServiceProvider::register()`). Enabling this
|                 key stores a second copy of the same payload; it is not what
|                 makes the button work.
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Enable/Disable Package
    |--------------------------------------------------------------------------
    |
    | Master switch to enable or disable the entire Vantage package.
    | When disabled, no job tracking, routes, or listeners will be active.
    | Useful for testing in staging without affecting production, or
    | temporarily disabling monitoring.
    |
    */
    'enabled' => env('VANTAGE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Store Payload
    |--------------------------------------------------------------------------
    |
    | Whether to store job payload for debugging and retry purposes.
    | When enabled, job data will be stored with automatic redaction of
    | sensitive keys. Disable to save database space if not needed.
    |
    | Default false here, not the package's true: redaction covers named keys,
    | not the export filters, model ids and mail arguments this project's jobs
    | actually carry. See the header note.
    |
    */
    'store_payload' => env('VANTAGE_STORE_PAYLOAD', false),

    /*
    |--------------------------------------------------------------------------
    | Redact Sensitive Keys
    |--------------------------------------------------------------------------
    |
    | List of keys that should be redacted from stored payloads for security.
    | These keys will be replaced with '[REDACTED]' in the payload.
    |
    */
    'redact_keys' => [
        'password', 'token', 'authorization', 'secret', 'api_key',
        'apikey', 'access_token', 'refresh_token', 'private_key',
        'card_number', 'cvv', 'ssn', 'credit_card',
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Retention
    |--------------------------------------------------------------------------
    |
    | Number of days to keep job run history. Older records will be pruned.
    | Set to null to keep records indefinitely.
    | This value is used as the default for the `vantage:prune` command.
    |
    */
    'retention_days' => env('VANTAGE_RETENTION_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Failure Notifications
    |--------------------------------------------------------------------------
    |
    | Configure how you want to be notified when jobs fail.
    |
    */
    'notify_on_failure' => true,
    'notification_channels' => ['mail'],
    'notify' => [
        'email' => env('VANTAGE_NOTIFY_EMAIL', null),
        'slack_webhook' => env('VANTAGE_SLACK_WEBHOOK', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Tagging
    |--------------------------------------------------------------------------
    |
    | Configure job tagging behavior for better organization and filtering.
    | Tags are extracted from the job's tags() method (Laravel built-in feature).
    |
    */
    'tagging' => [
        // Enable/disable tagging feature
        'enabled' => true,

        // Automatically add tags to all jobs
        'auto_tags' => [
            'environment' => false,  // Adds: env:production, env:local, etc.
            'queue_name' => true,    // Adds: queue:default, queue:emails, etc.
            'hour' => false,         // Adds: hour:14, hour:09, etc.
        ],

        // Maximum number of tags per job
        'max_tags_per_job' => 20,

        // Sanitize tag values (lowercase, trim whitespace)
        'sanitize' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Web Routes
    |--------------------------------------------------------------------------
    |
    | Configure how the dashboard routes are registered.
    | - routes: master switch to register routes at all
    | - route_prefix: base URI segment for all dashboard routes
    |
    | `routes` is FALSE on purpose: `routes/web.php` declares the group so it can
    | carry `feature:vantage` + `can:queues.*`. See the header note — do not
    | re-enable here.
    |
    */
    'routes' => false,
    'route_prefix' => env('VANTAGE_ROUTE_PREFIX', 'vantage'),

    /*
    |--------------------------------------------------------------------------
    | JSON API
    |--------------------------------------------------------------------------
    |
    | Expose a JSON API for external consumption (dashboards, CLI tools, etc.).
    | The API is independent of the web dashboard and uses Bearer-token auth
    | instead of the Gate/session-based auth used by the Blade views.
    |
    | Set `api.token` to a strong random string and pass it as:
    |   Authorization: Bearer <token>
    |
    | If the token contains =, +, #, or spaces, wrap the value in double quotes
    | in your .env file (e.g. VANTAGE_API_TOKEN="...") so it is not truncated.
    |
    */
    'api' => [
        'enabled' => env('VANTAGE_API_ENABLED', false),
        'token' => env('VANTAGE_API_TOKEN'),
        'prefix' => env('VANTAGE_API_PREFIX', 'api/vantage'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Telemetry
    |--------------------------------------------------------------------------
    |
    | Configure automatic performance telemetry collection for queued jobs.
    | If disabled, only basic lifecycle fields are stored. Sample rate lets
    | you downsample metrics collection to reduce overhead.
    */
    'telemetry' => [
        'enabled' => env('VANTAGE_TELEMETRY_ENABLED', true),
        'sample_rate' => (float) env('VANTAGE_TELEMETRY_SAMPLE_RATE', 1.0), // 0.0 - 1.0
        'capture_cpu' => env('VANTAGE_TELEMETRY_CPU', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | Specify which database connection to use for storing queue job runs.
    | This is useful when your application uses multiple databases.
    | Set to null to use the default database connection.
    |
    */
    'database_connection' => env('VANTAGE_DATABASE_CONNECTION', null),

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | Configure authentication for the Vantage dashboard (similar to Horizon).
    | When enabled, routes will be protected using a gate authorization check.
    |
    | By default, all authenticated users can access Vantage. You can customize
    | the authorization logic by overriding the 'viewVantage' gate in your
    | AppServiceProvider:
    |
    | Gate::define('viewVantage', function ($user) {
    |     return $user->isAdmin(); // or your custom logic
    | });
    |
    | Set to false to disable authentication (not recommended for production).
    |
    */
    'auth' => [
        'enabled' => env('VANTAGE_AUTH_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Toggle package-originated log messages. When disabled, Vantage will refrain
    | from writing informational/debug/warning logs to your application log.
    |
    */
    'logging' => [
        'enabled' => env('VANTAGE_LOGGING_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Jobs
    |--------------------------------------------------------------------------
    | Job classes listed here will not be recorded by Vantage.
    | You can also implement ShouldNotBeTracked on any job class.
    */
    'exclude_jobs' => [
        // App\Jobs\SomeNoisyJob::class,
    ],
];
