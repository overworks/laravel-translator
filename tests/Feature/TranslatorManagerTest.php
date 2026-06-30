<?php

declare(strict_types=1);

use Minhyung\LaravelTranslator\Contracts\Translator as TranslatorContract;
use Minhyung\LaravelTranslator\Drivers\CachingDriver;
use Minhyung\LaravelTranslator\Drivers\ClaudeDriver;
use Minhyung\LaravelTranslator\Drivers\DeeplDriver;
use Minhyung\LaravelTranslator\Drivers\FallbackDriver;
use Minhyung\LaravelTranslator\Drivers\GoogleDriver;
use Minhyung\LaravelTranslator\Drivers\GoogleV3Driver;
use Minhyung\LaravelTranslator\Drivers\LibreTranslateDriver;
use Minhyung\LaravelTranslator\Drivers\OpenAiDriver;
use Minhyung\LaravelTranslator\Drivers\RetryingDriver;
use Minhyung\LaravelTranslator\Facades\Translator;
use Minhyung\LaravelTranslator\TranslatorManager;

beforeEach(function () {
    config()->set('translator.default', 'deepl');
    config()->set('translator.translators.deepl', ['driver' => 'deepl', 'key' => 'test-key:fx']);
});

it('returns a Translator wrapping a cache-wrapped driver when caching is enabled', function () {
    config()->set('translator.cache.enabled', true);

    $translator = app(TranslatorManager::class)->via();

    expect($translator)->toBeInstanceOf(TranslatorContract::class)
        ->and($translator->driver())->toBeInstanceOf(CachingDriver::class);
});

it('wraps the bare driver when caching is disabled', function () {
    config()->set('translator.cache.enabled', false);

    expect(app(TranslatorManager::class)->via()->driver())->toBeInstanceOf(DeeplDriver::class);
});

it('throws a helpful error when the DeepL key is missing', function () {
    config()->set('translator.translators.deepl.key', null);

    expect(fn () => app(TranslatorManager::class)->via('deepl'))
        ->toThrow(InvalidArgumentException::class);
});

it('throws when a translator is not defined', function () {
    expect(fn () => app(TranslatorManager::class)->via('does-not-exist'))
        ->toThrow(InvalidArgumentException::class);
});

it('throws when a translator entry has no driver', function () {
    config()->set('translator.translators.broken', ['key' => 'x']);

    expect(fn () => app(TranslatorManager::class)->via('broken'))
        ->toThrow(InvalidArgumentException::class);
});

it('throws for an unsupported driver', function () {
    config()->set('translator.translators.mystery', ['driver' => 'mystery', 'model' => 'x']);

    expect(fn () => app(TranslatorManager::class)->via('mystery'))
        ->toThrow(InvalidArgumentException::class);
});

it('resolves the facade to a translator', function () {
    config()->set('translator.cache.enabled', false);

    expect(Translator::via())->toBeInstanceOf(TranslatorContract::class)
        ->and(Translator::via()->driver())->toBeInstanceOf(DeeplDriver::class);
});

it('does not clobber Laravel\'s own translator binding', function () {
    expect(app('translator'))->toBeInstanceOf(\Illuminate\Translation\Translator::class);
});

it('resolves the native Claude driver', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.translators.claude', [
        'driver' => 'claude',
        'key' => 'sk-ant-test',
        'model' => 'claude-haiku-4-5',
    ]);

    expect(app(TranslatorManager::class)->via('claude')->driver())->toBeInstanceOf(ClaudeDriver::class);
});

it('throws when the Claude key is missing', function () {
    config()->set('translator.translators.claude', [
        'driver' => 'claude',
        'key' => null,
        'model' => 'claude-haiku-4-5',
    ]);

    expect(fn () => app(TranslatorManager::class)->via('claude'))
        ->toThrow(InvalidArgumentException::class);
});

it('resolves the OpenAI driver', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.translators.openai', [
        'driver' => 'openai',
        'key' => 'sk-test',
        'model' => 'gpt-5.4-mini',
    ]);

    expect(app(TranslatorManager::class)->via('openai')->driver())->toBeInstanceOf(OpenAiDriver::class);
});

it('resolves an OpenAI-compatible driver via base_url', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.translators.deepseek', [
        'driver' => 'openai',
        'base_url' => 'https://api.deepseek.com/v1',
        'key' => 'sk-test',
        'model' => 'deepseek-v4-flash',
    ]);

    expect(app(TranslatorManager::class)->via('deepseek')->driver())->toBeInstanceOf(OpenAiDriver::class);
});

it('resolves the Google v2 driver by default', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.translators.google', ['driver' => 'google', 'key' => 'AIza-test']);

    expect(app(TranslatorManager::class)->via('google')->driver())->toBeInstanceOf(GoogleDriver::class);
});

it('resolves the Google v3 driver when version is 3', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.translators.google', [
        'driver' => 'google',
        'version' => 3,
        'project_id' => 'my-project',
        'location' => 'global',
    ]);

    expect(app(TranslatorManager::class)->via('google')->driver())->toBeInstanceOf(GoogleV3Driver::class);
});

it('throws for Google v3 without a project id', function () {
    config()->set('translator.translators.google', ['driver' => 'google', 'version' => 3]);

    expect(fn () => app(TranslatorManager::class)->via('google'))
        ->toThrow(InvalidArgumentException::class);
});

it('resolves the LibreTranslate driver', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.translators.libretranslate', [
        'driver' => 'libretranslate',
        'base_url' => 'http://localhost:5000',
    ]);

    expect(app(TranslatorManager::class)->via('libretranslate')->driver())
        ->toBeInstanceOf(LibreTranslateDriver::class);
});

it('throws when the OpenAI driver has no model', function () {
    config()->set('translator.translators.openai', ['driver' => 'openai', 'key' => 'sk-test', 'model' => null]);

    expect(fn () => app(TranslatorManager::class)->via('openai'))
        ->toThrow(InvalidArgumentException::class);
});

it('wraps a driver with retries when the retry option is set', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.translators.flaky', [
        'driver' => 'deepl',
        'key' => 'k:fx',
        'retry' => ['times' => 3, 'sleep' => 0],
    ]);

    expect(app(TranslatorManager::class)->via('flaky')->driver())->toBeInstanceOf(RetryingDriver::class);
});

it('does not wrap a fallback driver with retries', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.translators.safe', [
        'driver' => 'fallback',
        'translators' => ['deepl'],
        'retry' => 5,
    ]);

    expect(app(TranslatorManager::class)->via('safe')->driver())->toBeInstanceOf(FallbackDriver::class);
});

it('resolves a custom driver registered via extend', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.translators.papago', ['driver' => 'papago']);

    app(TranslatorManager::class)->extend('papago', fn () => stubDriver('papago'));

    $translator = app(TranslatorManager::class)->via('papago');

    expect($translator)->toBeInstanceOf(TranslatorContract::class)
        ->and($translator->translate('Hello', 'ko')->translator)->toBe('papago');
});

it('forwards facade calls to the default translator', function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.default', 'stub');
    config()->set('translator.translators.stub', ['driver' => 'stub']);

    app(TranslatorManager::class)->extend('stub', fn () => stubDriver('stub', '안녕하세요'));

    expect(Translator::translate('Hello', 'ko')->text)->toBe('안녕하세요');
});

it('resolves the fallback driver lazily, without constructing children or caching it', function () {
    config()->set('translator.cache.enabled', true);
    // 'google' is not configured: if children were built eagerly, resolving the
    // fallback translator would blow up here.
    config()->set('translator.translators.fallback', [
        'driver' => 'fallback',
        'translators' => ['deepl', 'google'],
    ]);

    expect(app(TranslatorManager::class)->via('fallback')->driver())->toBeInstanceOf(FallbackDriver::class);
});

it('throws when the fallback translator list is empty', function () {
    config()->set('translator.translators.fallback', ['driver' => 'fallback', 'translators' => []]);

    expect(fn () => app(TranslatorManager::class)->via('fallback'))
        ->toThrow(InvalidArgumentException::class);
});
