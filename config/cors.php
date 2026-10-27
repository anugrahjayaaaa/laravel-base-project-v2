<?php

// Default-deny: with no origin configured, `allowed_origins` is empty and no
// CORS grant is emitted anywhere. The switch is deployment-tied — a new origin
// is a deploy decision, not something an admin toggles at runtime — so it lives
// in .env rather than the settings table.
$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

return [

    // API only. The Blade pages are same-origin; a CORS grant on them would be
    // a cross-origin read of an authenticated document, which nothing needs.
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Authorization',
        'Content-Type',
        'Accept',
        'X-CSRF-TOKEN',
        'X-Requested-With',
        // The header `GenerateRequestCorrelationId` actually reads. Listing a
        // name nothing reads would let a caller send an id that is ignored.
        'X-Request-ID',
    ],

    'exposed_headers' => ['X-Request-ID'],

    // Derived, not a second switch. `*` with credentials is invalid per the CORS
    // spec, and fruitcake answers that pair by echoing any caller's origin with
    // `Allow-Credentials: true` — so a wildcard is honoured only uncredentialed.
    // shortcut: a list mixing `*` with named origins drops credentials for all of
    // them; upgrade to an explicit origin list if credentialed mixed access is needed.
    'supports_credentials' => $origins !== [] && ! in_array('*', $origins, true),

    'max_age' => 3600,

];
