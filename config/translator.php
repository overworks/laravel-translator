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
    | Each driver is keyed by name. "deepl", "google", "anthropic", and
    | "fallback" have dedicated implementations. Any other name is treated as
    | an OpenAI-compatible endpoint: well-known providers (openai, deepseek,
    | gemini, groq, mistral, xai, openrouter, ollama) resolve to a built-in
    | base URI automatically, and any other name just needs an explicit
    | "base_uri". Each LLM entry takes a "key", a "model", and optional
    | "options" (temperature, max_tokens, system_prompt). Register as many as
    | you like.
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

        // Native Claude driver (Anthropic Messages API via mozex/anthropic-php).
        'anthropic' => [
            'key' => env('ANTHROPIC_API_KEY'),
            'model' => env('TRANSLATOR_ANTHROPIC_MODEL', 'claude-3-5-sonnet-latest'),

            'options' => [
                // 'temperature'   => 0.0,
                // 'max_tokens'    => 4096,
                // 'system_prompt' => 'Custom prompt with {source} and {target} placeholders.',
            ],
        ],

        // OpenAI-compatible providers. The base URI is resolved from a built-in
        // preset for each well-known name; supply the provider's API key and a
        // model. Drop any you do not use.
        'openai' => [
            'key' => env('OPENAI_API_KEY'),
            'model' => env('TRANSLATOR_OPENAI_MODEL', 'gpt-4o-mini'),
        ],

        'gemini' => [
            'key' => env('GEMINI_API_KEY'),
            'model' => env('TRANSLATOR_GEMINI_MODEL', 'gemini-2.0-flash'),
        ],

        'deepseek' => [
            'key' => env('DEEPSEEK_API_KEY'),
            'model' => env('TRANSLATOR_DEEPSEEK_MODEL', 'deepseek-chat'),
        ],

        'openrouter' => [
            'key' => env('OPENROUTER_API_KEY'),
            'model' => env('TRANSLATOR_OPENROUTER_MODEL', 'openai/gpt-4o-mini'),
        ],

        // Self-hosted models via Ollama (no key required by default).
        'ollama' => [
            'model' => env('TRANSLATOR_OLLAMA_MODEL', 'llama3.2'),
        ],

        // Custom OpenAI-compatible endpoint (self-hosted gateways, proxies, or
        // vendors exposing the OpenAI chat schema that are not preset above).
        // Provide the endpoint's "base_uri". Register as many as you like.
        'custom' => [
            'base_uri' => env('TRANSLATOR_CUSTOM_BASE_URI'), // e.g. https://my-gateway.test/v1
            'key' => env('TRANSLATOR_CUSTOM_KEY'),
            'model' => env('TRANSLATOR_CUSTOM_MODEL', 'gpt-4o-mini'),

            // 'headers' => ['X-Tenant' => 'acme'],
            // 'options' => ['temperature' => 0.0],
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
