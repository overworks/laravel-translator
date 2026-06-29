<?php

declare(strict_types=1);

use Minhyung\LaravelTranslator\Drivers\CachingTranslator;
use Minhyung\LaravelTranslator\Drivers\DeeplTranslator;
use Minhyung\LaravelTranslator\Drivers\FallbackTranslator;
use Minhyung\LaravelTranslator\Drivers\LlmTranslator;
use Minhyung\LaravelTranslator\Facades\Translator;
use Minhyung\LaravelTranslator\TranslatorManager;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;

beforeEach(function () {
    config()->set('translator.default', 'deepl');
    config()->set('translator.providers.deepl.key', 'test-key:fx');
});

it('resolves the default driver wrapped in caching when enabled', function () {
    config()->set('translator.cache.enabled', true);

    $driver = app(TranslatorManager::class)->provider();

    expect($driver)->toBeInstanceOf(CachingTranslator::class);
});

it('returns the bare driver when caching is disabled', function () {
    config()->set('translator.cache.enabled', false);

    $driver = app(TranslatorManager::class)->provider();

    expect($driver)->toBeInstanceOf(DeeplTranslator::class);
});

it('throws a helpful error when the DeepL key is missing', function () {
    config()->set('translator.providers.deepl.key', null);

    expect(fn () => app(TranslatorManager::class)->provider('deepl'))
        ->toThrow(InvalidArgumentException::class);
});

it('resolves the facade to the manager', function () {
    config()->set('translator.cache.enabled', false);

    expect(Translator::provider())->toBeInstanceOf(DeeplTranslator::class);
});

it('does not clobber Laravel\'s own translator binding', function () {
    expect(app('translator'))->toBeInstanceOf(\Illuminate\Translation\Translator::class);
});

it('resolves the llm driver from config', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.providers.llm', ['provider' => 'openai', 'model' => 'gpt-4o-mini']);

    expect(app(TranslatorManager::class)->provider('llm'))->toBeInstanceOf(LlmTranslator::class);
});

it('throws when the llm provider or model is missing', function () {
    config()->set('translator.providers.llm', ['provider' => 'openai', 'model' => null]);

    expect(fn () => app(TranslatorManager::class)->provider('llm'))
        ->toThrow(InvalidArgumentException::class);
});

it('resolves a config-defined named LLM driver and tags results with its name', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.providers.claude', [
        'driver'   => 'llm',
        'provider' => 'anthropic',
        'model'    => 'claude-3-5-sonnet-latest',
    ]);

    Prism::fake([TextResponseFake::make()->withText('안녕')]);

    $driver = app(TranslatorManager::class)->provider('claude');

    expect($driver)->toBeInstanceOf(LlmTranslator::class)
        ->and($driver->translate('Hello', 'ko')->driver)->toBe('claude');
});

it('throws for a named driver that is not configured', function () {
    expect(fn () => app(TranslatorManager::class)->provider('does-not-exist'))
        ->toThrow(InvalidArgumentException::class);
});

it('throws for a named driver config missing the driver type key', function () {
    config()->set('translator.providers.broken', ['provider' => 'anthropic', 'model' => 'x']);

    expect(fn () => app(TranslatorManager::class)->provider('broken'))
        ->toThrow(InvalidArgumentException::class);
});

it('resolves the fallback driver lazily, without constructing children or caching it', function () {
    config()->set('translator.cache.enabled', true);
    // 'google' has no credentials configured: if children were built eagerly,
    // resolving the fallback driver would blow up here.
    config()->set('translator.providers.fallback.providers', ['deepl', 'google']);

    expect(app(TranslatorManager::class)->provider('fallback'))->toBeInstanceOf(FallbackTranslator::class);
});

it('throws when the fallback driver list is empty', function () {
    config()->set('translator.providers.fallback.providers', []);

    expect(fn () => app(TranslatorManager::class)->provider('fallback'))
        ->toThrow(InvalidArgumentException::class);
});
