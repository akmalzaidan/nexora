<?php

return [
    /*
    |--------------------------------------------------------------------------
    | NEXORA Application Metadata
    |--------------------------------------------------------------------------
    |
    | Application-specific values exposed by the public API (for example the
    | health endpoint). Kept here instead of hardcoded in controllers.
    |
    */

    'name' => env('NEXORA_NAME', 'NEXORA'),

    'version' => env('APP_VERSION', '1.0.0'),
];
