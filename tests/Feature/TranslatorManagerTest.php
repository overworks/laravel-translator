<?php

declare(strict_types=1);

use Minhyung\LaravelTranslator\Contracts\Translator as TranslatorContract;
use Minhyung\LaravelTranslator\Drivers\AnthropicTranslator;
use Minhyung\LaravelTranslator\Drivers\CachingTranslator;
use Minhyung\LaravelTranslator\Drivers\DeeplTranslator;
use Minhyung\LaravelTranslator\Drivers\FallbackTranslator;
use Minhyung\LaravelTranslator\Drivers\OpenAiCompatibleTranslator;
use Minhyung\LaravelTranslator\Facades\Translator;
use Minhyung\LaravelTranslator\Support\TranslationResult;
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

it('resolves the native Anthropic driver by name', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.drivers.anthropic', [
        'key' => 'sk-ant-test',
        'model' => 'claude-3-5-sonnet-latest',
    ]);

    expect(app(TranslatorManager::class)->driver('anthropic'))
        ->toBeInstanceOf(AnthropicTranslator::class);
});

it('throws when the Anthropic key is missing', function () {
    config()->set('translator.drivers.anthropic', ['key' => null, 'model' => 'claude-3-5-sonnet-latest']);

    expect(fn () => app(TranslatorManager::class)->driver('anthropic'))
        ->toThrow(InvalidArgumentException::class);
});

it('resolves a preset OpenAI-compatible provider by name', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.drivers.openai', ['key' => 'sk-test', 'model' => 'gpt-4o-mini']);

    expect(app(TranslatorManager::class)->driver('openai'))
        ->toBeInstanceOf(OpenAiCompatibleTranslator::class);
});

it('throws when an OpenAI-compatible provider has no model', function () {
    config()->set('translator.drivers.openai', ['key' => 'sk-test', 'model' => null]);

    expect(fn () => app(TranslatorManager::class)->driver('openai'))
        ->toThrow(InvalidArgumentException::class);
});

it('throws for an unknown name with no preset and no base_uri', function () {
    config()->set('translator.drivers.mystery', ['model' => 'whatever']);

    expect(fn () => app(TranslatorManager::class)->driver('mystery'))
        ->toThrow(InvalidArgumentException::class);
});

it('routes a driver entry with a base_uri to the OpenAI-compatible driver', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.drivers.custom', [
        'base_uri' => 'https://gateway.test/v1',
        'key' => 'secret',
        'model' => 'local-model',
    ]);

    expect(app(TranslatorManager::class)->driver('custom'))
        ->toBeInstanceOf(OpenAiCompatibleTranslator::class);
});

it('forwards facade calls to the default driver', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.default', 'stub');

    app(TranslatorManager::class)->extend('stub', fn () => new class implements TranslatorContract
    {
        public function translate(string $text, string $targetLang, ?string $sourceLang = null, array $options = []): TranslationResult
        {
            return new TranslationResult(text: '안녕하세요', targetLang: $targetLang, driver: 'stub');
        }

        public function translateBatch(array $texts, string $targetLang, ?string $sourceLang = null, array $options = []): array
        {
            return [];
        }
    });

    expect(Translator::translate('Hello', 'ko')->text)->toBe('안녕하세요');
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
