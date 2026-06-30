<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Translator
    |--------------------------------------------------------------------------
    |
    | The translator used when none is explicitly specified. This is one of the
    | names defined in the "translators" array below (e.g. "deepl", "claude").
    |
    */

    'default' => env('TRANSLATOR_DEFAULT', 'deepl'),

    /*
    |--------------------------------------------------------------------------
    | Translators
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many named translators as you like. Each entry
    | picks an implementation with its "driver" key. Supported drivers are:
    | "deepl", "google", "claude", "openai", and "fallback" — plus any custom
    | driver registered via TranslatorManager::extend().
    |
    | Several names may share one driver: e.g. DeepSeek and Gemini both use the
    | "openai" driver, pointed at their own "base_url".
    |
    */

    'translators' => [

        'deepl' => [
            'driver' => 'deepl',
            'key' => env('DEEPL_AUTH_KEY'),
        ],

        // Google Cloud Translation v2 — authenticated with a simple API key.
        'google' => [
            'driver' => 'google',
            'key' => env('GOOGLE_TRANSLATE_KEY'),
        ],

        // Native Claude (Anthropic Messages API via mozex/anthropic-php).
        'claude' => [
            'driver' => 'claude',
            'key' => env('ANTHROPIC_API_KEY'),
            'model' => env('TRANSLATOR_CLAUDE_MODEL', 'claude-haiku-4-5'),

            'options' => [
                // 'temperature'   => 0.0,
                // 'max_tokens'    => 4096,
                // 'system_prompt' => 'Custom prompt with {source} and {target} placeholders.',
            ],
        ],

        // OpenAI via openai-php/client.
        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'model' => env('TRANSLATOR_OPENAI_MODEL', 'gpt-5.4-mini'),
        ],

        // Any OpenAI-compatible endpoint reuses the "openai" driver with its own
        // "base_url". Common ones:
        //   DeepSeek    https://api.deepseek.com/v1
        //   Gemini      https://generativelanguage.googleapis.com/v1beta/openai
        //   Groq        https://api.groq.com/openai/v1
        //   Mistral     https://api.mistral.ai/v1
        //   xAI         https://api.x.ai/v1
        //   OpenRouter  https://openrouter.ai/api/v1
        //   Ollama      http://localhost:11434/v1
        'gemini' => [
            'driver' => 'openai',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'key' => env('GEMINI_API_KEY'),
            'model' => env('TRANSLATOR_GEMINI_MODEL', 'gemini-3-flash-preview'),
        ],

        'deepseek' => [
            'driver' => 'openai',
            'base_url' => 'https://api.deepseek.com/v1',
            'key' => env('DEEPSEEK_API_KEY'),
            'model' => env('TRANSLATOR_DEEPSEEK_MODEL', 'deepseek-v4-flash'),

            'options' => [
                // DeepSeek V4 enables "thinking" by default; translation does not
                // need it, so disable it for faster, cheaper, predictable output.
                'extra_body' => ['thinking' => ['type' => 'disabled']],

                // 'temperature' => 0.0,
            ],

            // 'headers' => ['X-Tenant' => 'acme'], // extra HTTP headers
        ],

        // Failover: try each translator in order, falling back to the next one
        // whenever a translator throws. Set 'default' => 'fallback' to use it.
        'fallback' => [
            'driver' => 'fallback',
            'translators' => ['deepl', 'google'],
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
