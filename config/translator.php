<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Driver
    |--------------------------------------------------------------------------
    |
    | The driver used when none is explicitly specified. This is one of the
    | keys defined in the "drivers" array below (e.g. "deepl", "google").
    |
    */

    'default' => env('TRANSLATOR_DRIVER', 'deepl'),

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    |
    | Each driver is keyed by name. The built-in names (deepl, google,
    | fallback) have dedicated implementations; any other name is treated as a
    | Prism LLM driver, where the key itself is the Prism provider name
    | (override it with an optional "provider" key). Register as many as you like.
    |
    */

    'drivers' => [

        'deepl' => [
            'key' => env('DEEPL_AUTH_KEY'),
        ],

        // Google Cloud Translation v2 — authenticated with a simple API key.
        'google' => [
            'key' => env('GOOGLE_TRANSLATE_KEY'),
        ],

        // LLM-backed translation via Prism. Any driver name that is not a
        // built-in (deepl, google, fallback) is treated as a Prism LLM driver,
        // so the key IS the Prism provider name. Configure the provider's own
        // credentials in Prism's config (config/prism.php). Each entry just
        // needs a "model" (and optional "options"); "provider" overrides the
        // Prism provider if you want the key to be an alias.
        'openai' => [
            'model' => env('TRANSLATOR_LLM_MODEL', 'gpt-4o-mini'),

            'options' => [
                // 'temperature'      => 0.0,
                // 'max_tokens'       => 1000,
                // 'system_prompt'    => 'Custom prompt with {source} and {target} placeholders.',
                // 'provider_options' => [],
            ],
        ],

        'anthropic' => [
            'model' => 'claude-3-5-sonnet-latest',
        ],

        'gemini' => [
            'model' => 'gemini-2.0-flash',
        ],

        // Failover: try each driver in order, falling back to the next one
        // whenever a driver throws. Set 'default' => 'fallback' to use it.
        'fallback' => [
            'drivers' => ['deepl', 'google'],
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
