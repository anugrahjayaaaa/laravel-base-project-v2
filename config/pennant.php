<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Every module this application can switch off at runtime, declared once.
    | A flag here is CODE: the identity, the human label and the grouping are
    | all things a `can()` call, a route middleware name and a sidebar entry
    | have to agree on. Keeping them in the database would mean a row nobody
    | reads, which is the trap `PermissionCatalog` documents at length.
    |
    | Each entry takes:
    |
    |   label       — what an administrator reads on the management page
    |   group       — the module heading it is listed under
    |   description — one line on what turning it off actually removes
    |   disabled    — optional; true makes the flag a KILL SWITCH that needs no
    |                 deploy to flip (see AppServiceProvider::boot())
    |   settings    — optional; system-setting keys that only affect this
    |                 module. With the flag off their routes 403 before the
    |                 limiter runs, so the page hides them and a write is a
    |                 no-op (FeatureCatalog::settingGroups()).
    |
    | Declaring a flag does NOT activate it. With the `database` store,
    | Feature::active() resolves against a row in the `features` table and
    | a missing row reads as FALSE — fail-closed. `FeatureFlagSeeder` is what
    | puts the rows there; see P7-B5.
    |
    */

    'features' => [
        'users' => [
            'label' => 'User Management',
            'group' => 'Users',
            'description' => 'Accounts, roles, permissions and bulk actions.',
            'settings' => [
                'user_state_actions_rate_limit_per_minute',
                'bulk_action_rate_limit_per_minute'
            ],
        ],
        'roles' => [
            'label' => 'Roles',
            'group' => 'Users',
            'description' => 'Role creation, editing and lifecycle.',
        ],
        'permissions' => [
            'label' => 'Permissions',
            'group' => 'Users',
            'description' => 'The read-only permission catalogue.',
        ],
        'settings' => [
            'label' => 'System Settings',
            'group' => 'Settings',
            'description' => 'Login security, password policy and rate limits.',
        ],
        'notifications' => [
            'label' => 'Notifications & Mail',
            'group' => 'Settings',
            'description' => 'Mail transport configuration and the in-app notification inbox.',
            'settings' => ['send_test_mail_rate_limit_per_minute'],
        ],
        'storage_management' => [
            'label' => 'Storage Management',
            'group' => 'Settings',
            'description' => 'Storage driver configuration, capacity metrics and secure file access.',
            // Every key the two cards own, disk and backup keys included. The
            // runtime binder reads the database directly, so listing them does
            // not gate storage itself; it stops a save made while the cards are
            // hidden from writing the update action's hardcoded defaults over
            // the stored configuration.
            'settings' => [
                'storage_default_disk',
                'storage_local_root',
                'storage_local_permission',
                'storage_s3_key',
                'storage_s3_secret',
                'storage_s3_region',
                'storage_s3_bucket',
                'storage_s3_endpoint',
                'storage_s3_url',
                'storage_max_capacity_mb',
                'storage_warning_threshold_percent',
                'temp_file_retention_hours',
                'backup_enabled',
                'backup_schedule',
                'backup_retention_daily',
                'backup_retention_weekly',
                'backup_retention_monthly',
            ],
        ],
        'sessions' => [
            'label' => 'Sessions',
            'group' => 'Security',
            'description' => 'Per-user session listing and logout-all.',
        ],
        'activity_logs' => [
            'label' => 'Activity Logs',
            'group' => 'Audit',
            'description' => 'The audit trail viewer.',
            'settings' => [
                'audit_export_rate_limit_per_hour',
                'audit_export_max_manual_retries',
            ],
            // The `pending` key is gone as of Phase 10 Group B: routes ship in
            // routes/web.php and the sidebar entry is live, so the flag now
            // controls something. Leaving it would keep `/features` telling an
            // operator this switch "records intent only" about a module whose
            // 403s change the moment they flip it.
        ],
        'pulse' => [
            'label' => 'Pulse Dashboard',
            'group' => 'Monitoring',
            'description' => 'Queue, cache, exception and slow-request metrics.',
        ],
        'health' => [
            'label' => 'System Health',
            'group' => 'Monitoring',
            'description' => 'Live ok/fail status of database, cache, queue and storage.',
        ],
        'vantage' => [
            'label' => 'Queue Monitor (Vantage)',
            'group' => 'Monitoring',
            'description' => 'Per-attempt job history, failure detail, retry chains and live queue depth.',
            'settings' => ['queue_retry_rate_limit_per_minute'],
        ],
        'member_loyalty' => [
            'label' => 'Membership & Loyalty',
            'group' => 'Membership',
            'description' => 'Member profiles, tiers, subscriptions, points and the expiry sweep.',
            // The five keys the Membership card on /settings edits. Off flag →
            // SystemSettingsUpdateAction strips them, so a save cannot write a
            // value no reader will consult. `currency_code` is deliberately NOT
            // here: it is a display choice for every money amount in the app
            // (the Localization card), not a loyalty feature, so switching
            // membership off must not stop an operator from setting it.
            'settings' => [
                'member_expiry_alert_window_days',
                'member_number_prefix',
                'member_points_expiry_date',
                'member_points_earn_amount',
                'member_points_earn_value',
            ],
        ],
        'vouchers' => [
            'label' => 'Vouchers',
            // Its own domain: vouchers are validated at payment time by POS, not
            // owned by membership and not owned by the gateway — so it gets its
            // own kill switch, menu and permissions (moved out of Phase 18).
            'group' => 'Sales',
            'description' => 'Voucher codes, quotas and validity windows; checked when a payment applies one.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Pennant Store
    |--------------------------------------------------------------------------
    |
    | Here you will specify the default store that Pennant should use when
    | storing and resolving feature flag values. Pennant ships with the
    | ability to store flag values in an in-memory array or database.
    |
    | Supported: "array", "database"
    |
    */

    'default' => env('PENNANT_STORE', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Pennant Stores
    |--------------------------------------------------------------------------
    |
    | Here you may configure each of the stores that should be available to
    | Pennant. These stores shall be used to store resolved feature flag
    | values - you may configure as many of these as your application requires.
    |
    */

    'stores' => [

        'array' => [
            'driver' => 'array',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => null,
            'table' => 'features',
        ],

    ],

];
