<?php

declare(strict_types=1);

use Minhyung\LaravelTranslator\Drivers\CachingTranslator;
use Minhyung\LaravelTranslator\Drivers\DeeplTranslator;
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
