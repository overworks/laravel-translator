<?php

declare(strict_types=1);

use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Contracts\ListsLanguages;
use Minhyung\LaravelTranslator\Facades\Translator;
use Minhyung\LaravelTranslator\Support\Language;
use Minhyung\LaravelTranslator\Support\TranslationResult;
use Minhyung\LaravelTranslator\TranslatorManager;

/**
 * A driver that can list languages.
 */
function listingDriver(): Driver
{
    return new class implements Driver, ListsLanguages
    {
        public function translate(string $text, string $targetLang, ?string $sourceLang = null, array $options = []): TranslationResult
        {
            return new TranslationResult($text, $targetLang, 'list');
        }

        public function translateBatch(array $texts, string $targetLang, ?string $sourceLang = null, array $options = []): array
        {
            return [];
        }

        public function languages(): array
        {
            return [new Language('en', 'English'), new Language('ko', 'Korean')];
        }
    };
}

beforeEach(function () {
    config()->set('translator.translators.list', ['driver' => 'list']);
    app(TranslatorManager::class)->extend('list', fn () => listingDriver());
});

it('lists languages via a capable driver', function () {
    config()->set('translator.cache.enabled', false);

    $languages = Translator::via('list')->languages();

    expect($languages)->toHaveCount(2)
        ->and($languages[0]->code)->toBe('en');
});

it('keeps listing working through the caching layer', function () {
    config()->set('translator.cache.enabled', true);

    expect(Translator::via('list')->languages())->toHaveCount(2);
});

it('throws for a translator whose driver cannot list languages', function (bool $cache) {
    config()->set('translator.cache.enabled', $cache);
    config()->set('translator.translators.openai', ['driver' => 'openai', 'key' => 'sk', 'model' => 'gpt-5.4-mini']);

    expect(fn () => Translator::via('openai')->languages())
        ->toThrow(RuntimeException::class);
})->with([true, false]);

it('is supported by the fake', function () {
    Translator::fake();

    expect(Translator::languages())->toHaveCount(3);
});
