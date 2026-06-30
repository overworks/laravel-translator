# laravel-translator

[![Latest Version on Packagist](https://img.shields.io/packagist/v/minhyung/laravel-translator.svg?style=flat-square)](https://packagist.org/packages/minhyung/laravel-translator)
[![Tests](https://img.shields.io/github/actions/workflow/status/overworks/laravel-translator/tests.yml?branch=0.x&label=tests&style=flat-square)](https://github.com/overworks/laravel-translator/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/minhyung/laravel-translator.svg?style=flat-square)](https://packagist.org/packages/minhyung/laravel-translator)
[![License](https://img.shields.io/packagist/l/minhyung/laravel-translator.svg?style=flat-square)](LICENSE)

**English** | [한국어](README.ko.md)

A Laravel package that puts multiple translation services (DeepL, Google Cloud Translation, LLMs, ...) behind **one unified API**.
It follows the same shape as Laravel's `config/filesystems.php`: you define named **translators**, and each one picks an implementation with a **`driver`** key. Translators are selected with `Translator::via('name')`, just like `Storage::disk('name')`. Result caching is built in.

Built-in drivers:

- **`deepl`** — DeepL
- **`google`** — Google Cloud Translation (v2)
- **`claude`** — native Anthropic Messages API via [mozex/anthropic-php](https://github.com/mozex/anthropic-php)
- **`openai`** — OpenAI and any OpenAI-compatible endpoint (DeepSeek, Gemini, Groq, Mistral, xAI, OpenRouter, Ollama, self-hosted gateways) via [openai-php/client](https://github.com/openai-php/client), pointed with `base_url`
- **`fallback`** — try several translators in order

No heavyweight LLM abstraction layer — each driver talks to its provider's SDK/API directly.

## Requirements

- PHP `^8.3`
- Laravel 12 / 13 (`illuminate/support: ^12.0|^13.0`)

> The Google driver uses **Translation API v2** and works with **an API key alone** — no service-account credentials or the `ext-grpc` PECL extension required.

## Installation

```bash
composer require minhyung/laravel-translator
```

Publish the config file (optional):

```bash
php artisan vendor:publish --tag=translator-config
```

## Configuration

In `config/translator.php` or your `.env`:

```dotenv
TRANSLATOR_DEFAULT=deepl        # default translator name: deepl | google | claude | openai | ...

# DeepL
DEEPL_AUTH_KEY=xxxxxxxx:fx

# Google Cloud Translation (v2, API key)
GOOGLE_TRANSLATE_KEY=AIza...

# Claude (native)
ANTHROPIC_API_KEY=sk-ant-...
TRANSLATOR_CLAUDE_MODEL=claude-haiku-4-5

# OpenAI + compatible providers — API key + optional model override
OPENAI_API_KEY=sk-...
TRANSLATOR_OPENAI_MODEL=gpt-5.4-mini
GEMINI_API_KEY=AIza...
TRANSLATOR_GEMINI_MODEL=gemini-3-flash-preview
DEEPSEEK_API_KEY=sk-...

# Caching
TRANSLATOR_CACHE=true
TRANSLATOR_CACHE_STORE=          # empty = the application's default store
TRANSLATOR_CACHE_TTL=86400       # seconds; empty = cache forever
```

> Batch translation asks the model for a JSON object with one in-order result per input, and throws if the counts don't match.

### Defining translators

Each entry under `translators` is a named instance whose `driver` picks the implementation.
Several names may share one driver — e.g. DeepSeek and Gemini both use the `openai` driver with their own `base_url`:

```php
// config/translator.php
'translators' => [
    'deepl'  => ['driver' => 'deepl',  'key' => env('DEEPL_AUTH_KEY')],
    'google' => ['driver' => 'google', 'key' => env('GOOGLE_TRANSLATE_KEY')],
    'claude' => ['driver' => 'claude', 'key' => env('ANTHROPIC_API_KEY'), 'model' => 'claude-haiku-4-5'],
    'openai' => ['driver' => 'openai', 'key' => env('OPENAI_API_KEY'), 'model' => 'gpt-5.4-mini'],

    // OpenAI-compatible endpoints: same driver, different base_url
    'gemini' => [
        'driver'   => 'openai',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
        'key'      => env('GEMINI_API_KEY'),
        'model'    => 'gemini-3-flash-preview',
    ],
    'deepseek' => [
        'driver'   => 'openai',
        'base_url' => 'https://api.deepseek.com/v1',
        'key'      => env('DEEPSEEK_API_KEY'),
        'model'    => 'deepseek-v4-flash',
        'options'  => [
            // DeepSeek V4 enables "thinking" by default; disable it for translation.
            'extra_body' => ['thinking' => ['type' => 'disabled']],
        ],

        // 'headers' => ['X-Tenant' => 'acme'], // extra HTTP headers
    ],
],
```

For the `openai` driver, `options` accepts `temperature`, `max_tokens`, `system_prompt`, and `extra_body` (arbitrary top-level request-body fields merged into the call, like the OpenAI SDK's `extra_body` — used above to turn off DeepSeek's thinking mode).

Common `base_url`s for the `openai` driver: DeepSeek `https://api.deepseek.com/v1`, Gemini `https://generativelanguage.googleapis.com/v1beta/openai`, Groq `https://api.groq.com/openai/v1`, Mistral `https://api.mistral.ai/v1`, xAI `https://api.x.ai/v1`, OpenRouter `https://openrouter.ai/api/v1`, Ollama `http://localhost:11434/v1`.

```php
Translator::via('claude')->translate('Hello', 'ko'); // result's ->translator is "claude"
```

## Usage

### Single translation

```php
use Minhyung\LaravelTranslator\Facades\Translator;

$result = Translator::translate('Hello, world!', 'ko');

$result->text;               // "안녕하세요, 여러분!"
$result->detectedSourceLang; // "en"
$result->translator;         // "deepl"
(string) $result;            // the translated text (Stringable)
```

Specify the source language and pass options:

```php
Translator::translate('How are you?', 'de', 'en', ['formality' => 'less']);
```

### Batch translation (keys and order preserved)

```php
$results = Translator::translateBatch(
    ['greeting' => 'Hello', 'farewell' => 'Goodbye'],
    'ko',
);

$results['greeting']->text; // "안녕하세요"
$results['farewell']->text; // "안녕히 가세요"
```

### Selecting a translator

```php
Translator::via('google')->translate('Hello', 'ko');

// Per-call options
Translator::via('openai')->translate('Hello', 'ko', 'en', [
    'temperature'   => 0.0,
    'system_prompt' => 'Translate from {source} into {target}. Keep it formal.',
]);
```

### Dependency injection

The `Translator` contract is bound to the default translator.

```php
use Minhyung\LaravelTranslator\Contracts\Translator;

public function __construct(private Translator $translator) {}
```

## Caching

When `translator.cache.enabled` is on, every driver is wrapped in a `CachingDriver`.
Identical inputs (text · source/target language · options) are served straight from the Laravel cache, cutting API calls and cost.
For batch translation, only the **cache misses** are sent to the provider in a single call.

## Failover

To automatically switch to the next provider when one fails, define a translator with the `fallback` driver.
It tries each listed translator in order and moves on to the next whenever one throws.

```php
// config/translator.php
'default' => 'safe',

'translators' => [
    'deepl'  => ['driver' => 'deepl',  'key' => env('DEEPL_AUTH_KEY')],
    'claude' => ['driver' => 'claude', 'key' => env('ANTHROPIC_API_KEY'), 'model' => 'claude-haiku-4-5'],

    'safe' => [
        'driver'      => 'fallback',
        'translators' => ['deepl', 'claude'],
    ],
],
```

```php
Translator::translate('Hello', 'ko'); // if deepl fails, try claude
```

- Each child translator is **cached individually** (the `fallback` itself is not cached, to avoid double caching), and every fallback attempt is logged at `warning` level via a PSR logger.
- If every translator fails, an `AllTranslationDriversFailedException` is thrown; use `getErrors()` to get the underlying exception per translator.

## Architecture

There are two layers:

- **`Contracts\Driver`** — the low-level provider contract. Each provider is an adapter implementing it (`DeeplDriver`, `OpenAiDriver`, ...), as are the composite `CachingDriver` and `FallbackDriver`.
- **`Translator`** (implements **`Contracts\Translator`**) — the public object the manager hands back from `via()` and binds for injection. It wraps a `Driver` and delegates to it, exposing `->driver()` and `->name()`.

So `Translator::via('claude')` returns a `Translator` wrapping a (cache-wrapped) `ClaudeDriver`.

## Extending with a custom driver

Implement `Contracts\Driver` and register it on the manager. The callback receives `($container, $name, $config)` and returns a `Driver`; reference it from config with `'driver' => 'papago'`.

```php
use Minhyung\LaravelTranslator\TranslatorManager;

app(TranslatorManager::class)->extend('papago', function ($container, $name, $config) {
    return new \App\Translation\PapagoDriver($config['key'], $name); // implements Contracts\Driver
});
```

```php
// config/translator.php
'translators' => [
    'papago' => ['driver' => 'papago', 'key' => env('PAPAGO_KEY')],
],
```

## Testing

```bash
composer install
vendor/bin/pest
```

## License

MIT
