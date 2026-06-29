<?php

declare(strict_types=1);

use Minhyung\LaravelTranslator\Drivers\CachingTranslator;
use Minhyung\LaravelTranslator\Drivers\DeeplTranslator;
use Minhyung\LaravelTranslator\Drivers\FallbackTranslator;
use Minhyung\LaravelTranslator\Drivers\LlmTranslator;
use Minhyung\LaravelTranslator\Facades\Translator;
use Minhyung\LaravelTranslator\TranslatorManager;

beforeEach(function () {
    config()->set('translator.default', 'deepl');
    config()->set('translator.drivers.deepl.key', 'test-key:fx');
});

it('resolves the default driver wrapped in caching when enabled', function () {
    config()->set('translator.cache.enabled', true);

    $driver = app(TranslatorManager::class)->driver();

    expect($driver)->toBeInstanceOf(CachingTranslator::class);
});

it('returns the bare driver when caching is disabled', function () {
    config()->set('translator.cache.enabled', false);

    $driver = app(TranslatorManager::class)->driver();

    expect($driver)->toBeInstanceOf(DeeplTranslator::class);
});

it('throws a helpful error when the DeepL key is missing', function () {
    config()->set('translator.drivers.deepl.key', null);

    expect(fn () => app(TranslatorManager::class)->driver('deepl'))
        ->toThrow(InvalidArgumentException::class);
});

it('resolves the facade to the manager', function () {
    config()->set('translator.cache.enabled', false);

    expect(Translator::driver())->toBeInstanceOf(DeeplTranslator::class);
});

it('does not clobber Laravel\'s own translator binding', function () {
    expect(app('translator'))->toBeInstanceOf(\Illuminate\Translation\Translator::class);
});

it('resolves the llm driver from config', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.drivers.llm', ['provider' => 'openai', 'model' => 'gpt-4o-mini']);

    expect(app(TranslatorManager::class)->driver('llm'))->toBeInstanceOf(LlmTranslator::class);
});

it('throws when the llm provider or model is missing', function () {
    config()->set('translator.drivers.llm', ['provider' => 'openai', 'model' => null]);

    expect(fn () => app(TranslatorManager::class)->driver('llm'))
        ->toThrow(InvalidArgumentException::class);
});

it('resolves the fallback driver lazily, without constructing children or caching it', function () {
    config()->set('translator.cache.enabled', true);
    // 'google' has no credentials configured: if children were built eagerly,
    // resolving the fallback driver would blow up here.
    config()->set('translator.drivers.fallback.drivers', ['deepl', 'google']);

    expect(app(TranslatorManager::class)->driver('fallback'))->toBeInstanceOf(FallbackTranslator::class);
});

it('throws when the fallback driver list is empty', function () {
    config()->set('translator.drivers.fallback.drivers', []);

    expect(fn () => app(TranslatorManager::class)->driver('fallback'))
        ->toThrow(InvalidArgumentException::class);
});
