# laravel-translator

[![Latest Version on Packagist](https://img.shields.io/packagist/v/minhyung/laravel-translator.svg?style=flat-square)](https://packagist.org/packages/minhyung/laravel-translator)
[![Tests](https://img.shields.io/github/actions/workflow/status/overworks/laravel-translator/tests.yml?branch=0.x&label=tests&style=flat-square)](https://github.com/overworks/laravel-translator/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/minhyung/laravel-translator.svg?style=flat-square)](https://packagist.org/packages/minhyung/laravel-translator)
[![License](https://img.shields.io/packagist/l/minhyung/laravel-translator.svg?style=flat-square)](LICENSE)

**English** | [한국어](README.ko.md)

A Laravel package that puts multiple translation services (DeepL, Google Cloud Translation, LLMs, ...) behind **one unified API**.
It is built on Laravel's standard Manager/Driver pattern, so drivers are easy to add or swap, and it ships with translation-result caching out of the box.

Supported drivers: **DeepL**, **Google Cloud Translation (v2)**, and **LLM** (powered by [Prism](https://prismphp.com) — OpenAI/Anthropic/Gemini, etc.).

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
TRANSLATOR_DRIVER=deepl        # default driver: deepl | google | openai | anthropic | ...

# DeepL
DEEPL_AUTH_KEY=xxxxxxxx:fx

# Google Cloud Translation (v2, API key)
GOOGLE_TRANSLATE_KEY=AIza...

# LLM (Prism) — per-provider model overrides
TRANSLATOR_OPENAI_MODEL=gpt-4o-mini
TRANSLATOR_ANTHROPIC_MODEL=claude-3-5-sonnet-latest
TRANSLATOR_GEMINI_MODEL=gemini-2.0-flash

# Caching
TRANSLATOR_CACHE=true
TRANSLATOR_CACHE_STORE=          # empty = the application's default store
TRANSLATOR_CACHE_TTL=86400       # seconds; empty = cache forever
```

> **LLM translation** uses [Prism](https://prismphp.com). Provider API keys and the like are managed in Prism's own config (`config/prism.php`).
> Batch translation uses structured output to guarantee one in-order result per input, and throws if the counts don't match.

### LLM providers (register as many as you like)

Any driver name that is **not** a built-in (`deepl`, `google`, `fallback`) is treated as a **Prism LLM driver**.
In other words, the **key in the `drivers` array is the Prism provider name**, and each entry just needs a `model` (plus optional `options`).
This is handy for registering several LLM providers and dropping them into a failover chain.

```php
// config/translator.php
'drivers' => [
    'openai'    => ['model' => 'gpt-4o-mini'],
    'anthropic' => ['model' => 'claude-3-5-sonnet-latest'],
    'gemini'    => ['model' => 'gemini-2.0-flash'],

    // Use the key as an alias by pointing 'provider' at the real Prism provider
    'claude'    => ['provider' => 'anthropic', 'model' => 'claude-3-5-sonnet-latest'],
],
```

```php
Translator::driver('anthropic')->translate('Hello', 'ko'); // result's ->driver is "anthropic"
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
    // Freely combine multiple LLM providers
    'anthropic' => ['model' => 'claude-3-5-sonnet-latest'],
    'gemini'    => ['model' => 'gemini-2.0-flash'],

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
