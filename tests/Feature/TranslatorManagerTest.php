<?php

declare(strict_types=1);

use Minhyung\LaravelTranslator\Contracts\Translator as TranslatorContract;
use Minhyung\LaravelTranslator\Drivers\CachingTranslator;
use Minhyung\LaravelTranslator\Drivers\ClaudeDriver;
use Minhyung\LaravelTranslator\Drivers\DeeplDriver;
use Minhyung\LaravelTranslator\Drivers\FallbackDriver;
use Minhyung\LaravelTranslator\Drivers\OpenAiDriver;
use Minhyung\LaravelTranslator\Facades\Translator;
use Minhyung\LaravelTranslator\Support\TranslationResult;
use Minhyung\LaravelTranslator\TranslatorManager;

beforeEach(function () {
    config()->set('translator.default', 'deepl');
    config()->set('translator.translators.deepl', ['driver' => 'deepl', 'key' => 'test-key:fx']);
});

it('resolves the default translator wrapped in caching when enabled', function () {
    config()->set('translator.cache.enabled', true);

    expect(app(TranslatorManager::class)->translator())->toBeInstanceOf(CachingTranslator::class);
});

it('returns the bare translator when caching is disabled', function () {
    config()->set('translator.cache.enabled', false);

    expect(app(TranslatorManager::class)->translator())->toBeInstanceOf(DeeplDriver::class);
});

it('throws a helpful error when the DeepL key is missing', function () {
    config()->set('translator.translators.deepl.key', null);

    expect(fn () => app(TranslatorManager::class)->translator('deepl'))
        ->toThrow(InvalidArgumentException::class);
});

it('throws when a translator is not defined', function () {
    expect(fn () => app(TranslatorManager::class)->translator('does-not-exist'))
        ->toThrow(InvalidArgumentException::class);
});

it('throws when a translator entry has no driver', function () {
    config()->set('translator.translators.broken', ['key' => 'x']);

    expect(fn () => app(TranslatorManager::class)->translator('broken'))
        ->toThrow(InvalidArgumentException::class);
});

it('throws for an unsupported driver', function () {
    config()->set('translator.translators.mystery', ['driver' => 'mystery', 'model' => 'x']);

    expect(fn () => app(TranslatorManager::class)->translator('mystery'))
        ->toThrow(InvalidArgumentException::class);
});

it('resolves the facade to the manager', function () {
    config()->set('translator.cache.enabled', false);

    expect(Translator::translator())->toBeInstanceOf(DeeplDriver::class);
});

it('does not clobber Laravel\'s own translator binding', function () {
    expect(app('translator'))->toBeInstanceOf(\Illuminate\Translation\Translator::class);
});

it('resolves the native Claude translator', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.translators.claude', [
        'driver' => 'claude',
        'key' => 'sk-ant-test',
        'model' => 'claude-3-5-sonnet-latest',
    ]);

    expect(app(TranslatorManager::class)->translator('claude'))->toBeInstanceOf(ClaudeDriver::class);
});

it('throws when the Claude key is missing', function () {
    config()->set('translator.translators.claude', [
        'driver' => 'claude',
        'key' => null,
        'model' => 'claude-3-5-sonnet-latest',
    ]);

    expect(fn () => app(TranslatorManager::class)->translator('claude'))
        ->toThrow(InvalidArgumentException::class);
});

it('resolves the OpenAI translator', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.translators.openai', [
        'driver' => 'openai',
        'key' => 'sk-test',
        'model' => 'gpt-4o-mini',
    ]);

    expect(app(TranslatorManager::class)->translator('openai'))->toBeInstanceOf(OpenAiDriver::class);
});

it('resolves an OpenAI-compatible translator via base_url', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.translators.deepseek', [
        'driver' => 'openai',
        'base_url' => 'https://api.deepseek.com/v1',
        'key' => 'sk-test',
        'model' => 'deepseek-chat',
    ]);

    expect(app(TranslatorManager::class)->translator('deepseek'))->toBeInstanceOf(OpenAiDriver::class);
});

it('throws when the OpenAI driver has no model', function () {
    config()->set('translator.translators.openai', ['driver' => 'openai', 'key' => 'sk-test', 'model' => null]);

    expect(fn () => app(TranslatorManager::class)->translator('openai'))
        ->toThrow(InvalidArgumentException::class);
});

it('resolves a custom driver registered via extend', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.translators.papago', ['driver' => 'papago']);

    app(TranslatorManager::class)->extend('papago', fn () => stubTranslator('papago'));

    expect(app(TranslatorManager::class)->translator('papago'))
        ->toBeInstanceOf(TranslatorContract::class)
        ->and(app(TranslatorManager::class)->translator('papago')->translate('Hello', 'ko')->translator)
        ->toBe('papago');
});

it('forwards facade calls to the default translator', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.default', 'stub');
    config()->set('translator.translators.stub', ['driver' => 'stub']);

    app(TranslatorManager::class)->extend('stub', fn () => stubTranslator('stub', '안녕하세요'));

    expect(Translator::translate('Hello', 'ko')->text)->toBe('안녕하세요');
});

it('resolves the fallback translator lazily, without constructing children or caching it', function () {
    config()->set('translator.cache.enabled', true);
    // 'google' is not configured: if children were built eagerly, resolving the
    // fallback translator would blow up here.
    config()->set('translator.translators.fallback', [
        'driver' => 'fallback',
        'translators' => ['deepl', 'google'],
    ]);

    expect(app(TranslatorManager::class)->translator('fallback'))->toBeInstanceOf(FallbackDriver::class);
});

it('throws when the fallback translator list is empty', function () {
    config()->set('translator.translators.fallback', ['driver' => 'fallback', 'translators' => []]);

    expect(fn () => app(TranslatorManager::class)->translator('fallback'))
        ->toThrow(InvalidArgumentException::class);
});

/**
 * A minimal stub translator tagging results with the given name.
 */
function stubTranslator(string $name, string $text = 'translated'): TranslatorContract
{
    return new class($name, $text) implements TranslatorContract
    {
        public function __construct(private string $name, private string $text)
        {
        }

        public function translate(string $text, string $targetLang, ?string $sourceLang = null, array $options = []): TranslationResult
        {
            return new TranslationResult(text: $this->text, targetLang: $targetLang, translator: $this->name);
        }

        public function translateBatch(array $texts, string $targetLang, ?string $sourceLang = null, array $options = []): array
        {
            return [];
        }
    };
}
