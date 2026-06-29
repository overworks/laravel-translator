<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Translation Driver
    |--------------------------------------------------------------------------
    |
    | The translation service used when no driver is explicitly specified.
    | Supported out of the box: "deepl", "google".
    |
    */

    'default' => env('TRANSLATOR_DRIVER', 'deepl'),

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    |
    | Per-service credentials and options.
    |
    */

    'drivers' => [

        'deepl' => [
            'key' => env('DEEPL_AUTH_KEY'),
        ],

        'google' => [
            'project_id' => env('GOOGLE_CLOUD_PROJECT'),
            'location'   => env('GOOGLE_TRANSLATE_LOCATION', 'global'),

            // Path to a service-account JSON key file (or a decoded array).
            // Leave null to use Application Default Credentials.
            'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Result Caching
    |--------------------------------------------------------------------------
    |
    | When enabled, identical translation requests are served from the Laravel
    | cache instead of hitting the provider again, saving API calls and cost.
    |
    */

    'cache' => [
        'enabled' => env('TRANSLATOR_CACHE', true),

        // Cache store to use. Null uses the application's default store.
        'store' => env('TRANSLATOR_CACHE_STORE'),

        // Lifetime in seconds. Set to null to cache forever.
        'ttl' => env('TRANSLATOR_CACHE_TTL', 86400),

        'prefix' => 'translator',
    ],

];
