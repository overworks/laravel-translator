<?php

declare(strict_types=1);

use Minhyung\LaravelTranslator\Contracts\DetectsLanguage;
use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Facades\Translator;
use Minhyung\LaravelTranslator\Support\LanguageDetection;
use Minhyung\LaravelTranslator\Support\TranslationResult;
use Minhyung\LaravelTranslator\TranslatorManager;

/**
 * A driver that supports language detection (always reports "en").
 */
function detectingDriver(): Driver
{
    return new class implements Driver, DetectsLanguage
    {
        public function translate(string $text, string $targetLang, ?string $sourceLang = null, array $options = []): TranslationResult
        {
            return new TranslationResult($text, $targetLang, 'det');
        }

        public function translateBatch(array $texts, string $targetLang, ?string $sourceLang = null, array $options = []): array
        {
            return [];
        }

        public function detect(string $text): LanguageDetection
        {
            return new LanguageDetection('en', 'det', 0.9);
        }
    };
}

beforeEach(function () {
    config()->set('translator.translators.det', ['driver' => 'det']);
    app(TranslatorManager::class)->extend('det', fn () => detectingDriver());
});

it('detects via a capable driver', function () {
    config()->set('translator.cache.enabled', false);

    $detection = Translator::via('det')->detect('Hello');

    expect($detection->language)->toBe('en')
        ->and($detection->confidence)->toBe(0.9);
});

it('keeps detection working through the caching layer', function () {
    config()->set('translator.cache.enabled', true);

    expect(Translator::via('det')->detect('Hello')->language)->toBe('en');
});

it('throws for a translator whose driver cannot detect (cached or not)', function (bool $cache) {
    config()->set('translator.cache.enabled', $cache);
    config()->set('translator.translators.deepl', ['driver' => 'deepl', 'key' => 'k:fx']);

    expect(fn () => Translator::via('deepl')->detect('Hello'))
        ->toThrow(RuntimeException::class);
})->with([true, false]);

it('is recorded by the fake', function () {
    $fake = Translator::fake();

    expect(Translator::detect('Hello')->language)->toBe('en');

    $fake->assertDetected('Hello');
});
