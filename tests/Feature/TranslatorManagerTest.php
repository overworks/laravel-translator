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

it('resolves an LLM provider by its name and tags results with it', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.providers.anthropic', ['model' => 'claude-3-5-sonnet-latest']);

    Prism::fake([TextResponseFake::make()->withText('안녕')]);

    $driver = app(TranslatorManager::class)->provider('anthropic');

    expect($driver)->toBeInstanceOf(LlmTranslator::class)
        ->and($driver->translate('Hello', 'ko')->driver)->toBe('anthropic');
});

it('lets a provider key alias a different Prism provider', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.providers.claude', ['provider' => 'anthropic', 'model' => 'claude-3-5-sonnet-latest']);

    Prism::fake([TextResponseFake::make()->withText('안녕')]);

    // Result is tagged with the key ("claude"), while Prism is told "anthropic".
    expect(app(TranslatorManager::class)->provider('claude')->translate('Hello', 'ko')->driver)
        ->toBe('claude');
});

it('throws when an LLM provider has no model (incl. unconfigured names)', function () {
    config()->set('translator.providers.openai', ['model' => null]);

    expect(fn () => app(TranslatorManager::class)->provider('openai'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => app(TranslatorManager::class)->provider('does-not-exist'))
        ->toThrow(InvalidArgumentException::class);
});

it('retires the public driver() selector in favour of provider()', function () {
    expect(fn () => app(TranslatorManager::class)->driver('deepl'))
        ->toThrow(BadMethodCallException::class);
});

it('forwards facade calls to the default provider', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.default', 'anthropic');
    config()->set('translator.providers.anthropic', ['model' => 'claude-3-5-sonnet-latest']);

    Prism::fake([TextResponseFake::make()->withText('안녕하세요')]);

    expect(Translator::translate('Hello', 'ko')->text)->toBe('안녕하세요');
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
