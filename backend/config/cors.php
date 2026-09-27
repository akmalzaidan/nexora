<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    // The v1 API speaks exactly these verbs. Wildcards would also advertise
    // PATCH/TRACE and any future verb, so the list is explicit (Phase 21A).
    'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'OPTIONS'],

    'allowed_origins' => explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:8100,http://127.0.0.1:8100')),

    'allowed_origins_patterns' => [],

    // Explicit allowlist: Accept/Content-Type for the JSON envelope,
    // Authorization for the Sanctum bearer token, plus the headers the
    // sanctum/csrf-cookie path and XMLHttpRequest need. Never "*".
    'allowed_headers' => [
        'Accept',
        'Accept-Language',
        'Authorization',
        'Content-Language',
        'Content-Type',
        'Origin',
        'X-Requested-With',
        'X-XSRF-TOKEN',
    ],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
