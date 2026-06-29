<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Provider
    |--------------------------------------------------------------------------
    |
    | The provider used when none is explicitly specified. This is one of the
    | keys defined in the "providers" array below (e.g. "deepl", "google").
    |
    */

    'default' => env('TRANSLATOR_PROVIDER', 'deepl'),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Each provider is keyed by name. The built-in names (deepl, google,
    | fallback) have dedicated drivers; any other name is treated as a Prism
    | LLM provider, where the key itself is the Prism provider name (override
    | it with an optional "provider" key). Register as many as you like.
    |
    */

    'providers' => [

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

        // LLM-backed translation via Prism. Any provider name that is not a
        // built-in (deepl, google, fallback) is treated as a Prism provider,
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

        // Failover: try each provider in order, falling back to the next one
        // whenever a provider throws. Set 'default' => 'fallback' to use it.
        'fallback' => [
            'providers' => ['deepl', 'google'],
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
