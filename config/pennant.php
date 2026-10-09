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
        ],
        'translations' => [
            'label' => 'Translations',
            'group' => 'Settings',
            'description' => 'Runtime-editable language lines.',
            // Declared ahead of its module. Nothing reads this flag yet, so
            // flipping the switch writes a store row and an audit row and
            // changes nothing else — the page says so rather than letting an
            // operator believe they switched a module off. Remove this key when
            // routes/translations ships and the flag is wired to them.
            'pending' => 'Module not built yet — this switch records intent only.',
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
