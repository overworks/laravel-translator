# laravel-translator

[![Latest Version on Packagist](https://img.shields.io/packagist/v/minhyung/laravel-translator.svg?style=flat-square)](https://packagist.org/packages/minhyung/laravel-translator)
[![Tests](https://img.shields.io/github/actions/workflow/status/overworks/laravel-translator/tests.yml?branch=0.x&label=tests&style=flat-square)](https://github.com/overworks/laravel-translator/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/minhyung/laravel-translator.svg?style=flat-square)](https://packagist.org/packages/minhyung/laravel-translator)
[![License](https://img.shields.io/packagist/l/minhyung/laravel-translator.svg?style=flat-square)](LICENSE)

**English** | [한국어](README.ko.md)

A Laravel package that puts multiple translation services (DeepL, Google Cloud Translation, LLMs, ...) behind **one unified API**.
It is built on Laravel's standard Manager/Driver pattern, so drivers are easy to add or swap, and it ships with translation-result caching out of the box.

Supported drivers:

- **DeepL**
- **Google Cloud Translation (v2)**
- **Anthropic / Claude** — native Messages API via [mozex/anthropic-php](https://github.com/mozex/anthropic-php)
- **OpenAI-compatible endpoints** via [openai-php/client](https://github.com/openai-php/client) — well-known providers (OpenAI, Gemini, DeepSeek, Groq, Mistral, xAI, OpenRouter, Ollama) work by name; any other endpoint works with a `base_uri`.

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
TRANSLATOR_DRIVER=deepl        # default driver: deepl | google | anthropic | openai | ...

# DeepL
DEEPL_AUTH_KEY=xxxxxxxx:fx

# Google Cloud Translation (v2, API key)
GOOGLE_TRANSLATE_KEY=AIza...

# Anthropic / Claude (native)
ANTHROPIC_API_KEY=sk-ant-...
TRANSLATOR_ANTHROPIC_MODEL=claude-3-5-sonnet-latest

# OpenAI-compatible providers — API key + optional model override
OPENAI_API_KEY=sk-...
TRANSLATOR_OPENAI_MODEL=gpt-4o-mini
GEMINI_API_KEY=AIza...
TRANSLATOR_GEMINI_MODEL=gemini-2.0-flash
DEEPSEEK_API_KEY=sk-...
OPENROUTER_API_KEY=sk-or-...

# Caching
TRANSLATOR_CACHE=true
TRANSLATOR_CACHE_STORE=          # empty = the application's default store
TRANSLATOR_CACHE_TTL=86400       # seconds; empty = cache forever
```

> Batch translation asks the model for a JSON object with one in-order result per input, and throws if the counts don't match.

### LLM providers (register as many as you like)

Every name that is **not** `deepl`, `google`, `anthropic`, or `fallback` is treated as an **OpenAI-compatible** endpoint.
Well-known providers resolve to a built-in base URI automatically — just give a `key` and a `model`:

```php
// config/translator.php
'drivers' => [
    'openai'     => ['key' => env('OPENAI_API_KEY'),     'model' => 'gpt-4o-mini'],
    'gemini'     => ['key' => env('GEMINI_API_KEY'),     'model' => 'gemini-2.0-flash'],
    'deepseek'   => ['key' => env('DEEPSEEK_API_KEY'),   'model' => 'deepseek-chat'],
    'openrouter' => ['key' => env('OPENROUTER_API_KEY'), 'model' => 'openai/gpt-4o-mini'],
    'ollama'     => ['model' => 'llama3.2'], // self-hosted, no key
],
```

Presets cover `openai`, `gemini`, `deepseek`, `groq`, `mistral`, `xai`, `openrouter`, and `ollama`.
Claude is a first-class **native** driver (`anthropic`), so it takes a `key` and `model` too:

```php
'anthropic' => ['key' => env('ANTHROPIC_API_KEY'), 'model' => 'claude-3-5-sonnet-latest'],
```

```php
Translator::driver('anthropic')->translate('Hello', 'ko'); // result's ->driver is "anthropic"
```

### Custom OpenAI-compatible endpoints

For an endpoint that is not preset above — self-hosted gateways, proxies, or vendors that expose the OpenAI chat schema — give the driver entry a **`base_uri`**.
Register as many as you like under different keys.

```php
// config/translator.php
'drivers' => [
    'custom' => [
        'base_uri' => 'https://my-gateway.test/v1',
        'key'      => env('TRANSLATOR_CUSTOM_KEY'),
        'model'    => 'my-model',

        // 'headers' => ['X-Tenant' => 'acme'], // extra HTTP headers
        // 'options' => ['temperature' => 0.0], // temperature, max_tokens, system_prompt
    ],
],
```

```php
Translator::driver('custom')->translate('Hello', 'ko'); // result's ->driver is "custom"
```

## Usage

### Single translation

```php
use Minhyung\LaravelTranslator\Facades\Translator;

$result = Translator::translate('Hello, world!', 'ko');

$result->text;               // "안녕하세요, 여러분!"
$result->detectedSourceLang; // "en"
$result->driver;             // "deepl"
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

### Selecting a driver

```php
Translator::driver('google')->translate('Hello', 'ko');

// LLM driver — options can be passed per call
Translator::driver('openai')->translate('Hello', 'ko', 'en', [
    'temperature'   => 0.0,
    'system_prompt' => 'Translate from {source} into {target}. Keep it formal.',
]);
```

### Dependency injection

The `Translator` contract is bound to the default driver.

```php
use Minhyung\LaravelTranslator\Contracts\Translator;

public function __construct(private Translator $translator) {}
```

## Caching

When `translator.cache.enabled` is on, every driver is wrapped in a `CachingTranslator`.
Identical inputs (text · source/target language · options) are served straight from the Laravel cache, cutting API calls and cost.
For batch translation, only the **cache misses** are sent to the provider in a single call.

## Failover

To automatically switch to the next provider when one fails, use the `fallback` driver.
It tries each driver in the listed order and moves on to the next whenever a driver throws.

```php
// config/translator.php
'default' => 'fallback',

'drivers' => [
    // Freely combine any providers
    'anthropic' => ['key' => env('ANTHROPIC_API_KEY'), 'model' => 'claude-3-5-sonnet-latest'],
    'gemini'    => ['key' => env('GEMINI_API_KEY'),    'model' => 'gemini-2.0-flash'],

    'fallback' => [
        'drivers' => ['deepl', 'anthropic', 'gemini'],
    ],
],
```

```php
Translator::translate('Hello', 'ko'); // if deepl fails, try anthropic → gemini in order
```

- Each child driver is **cached individually** (the `fallback` itself is not cached, to avoid double caching), and every fallback attempt is logged at `warning` level via a PSR logger.
- If every driver fails, an `AllTranslationDriversFailedException` is thrown; use `getErrors()` to get the underlying exception per driver.

## Extending with a custom driver

Implement `Contracts\Translator` and register it on the manager.

```php
use Minhyung\LaravelTranslator\TranslatorManager;

app(TranslatorManager::class)->extend('papago', function ($app) {
    return new \App\Translation\PapagoTranslator(/* ... */);
});
```

## Testing

```bash
composer install
vendor/bin/pest
```

## License

MIT
