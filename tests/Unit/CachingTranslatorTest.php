<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Minhyung\LaravelTranslator\Contracts\Translator;
use Minhyung\LaravelTranslator\Drivers\CachingTranslator;
use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * In-memory fake driver that records how it was called.
 */
function fakeDriver(): Translator
{
    return new class implements Translator {
        public int $translateCalls = 0;

        /** @var array<int, array<array-key, string>> */
        public array $batchInputs = [];

        public function translate(string $text, string $targetLang, ?string $sourceLang = null, array $options = []): TranslationResult
        {
            $this->translateCalls++;

            return new TranslationResult(strtoupper($text), $targetLang, 'fake');
        }

        public function translateBatch(array $texts, string $targetLang, ?string $sourceLang = null, array $options = []): array
        {
            $this->batchInputs[] = $texts;

            return array_map(
                fn (string $t) => new TranslationResult(strtoupper($t), $targetLang, 'fake'),
                $texts
            );
        }
    };
}

function arrayCache(): Repository
{
    return new Repository(new ArrayStore());
}

it('caches single translations and serves the second call from cache', function () {
    $inner = fakeDriver();
    $translator = new CachingTranslator($inner, arrayCache(), 'fake');

    $first = $translator->translate('hello', 'ko');
    $second = $translator->translate('hello', 'ko');

    expect($first->text)->toBe('HELLO')
        ->and($second->text)->toBe('HELLO')
        ->and($inner->translateCalls)->toBe(1);
});

it('treats different options/languages as distinct cache entries', function () {
    $inner = fakeDriver();
    $translator = new CachingTranslator($inner, arrayCache(), 'fake');

    $translator->translate('hello', 'ko');
    $translator->translate('hello', 'ja');
    $translator->translate('hello', 'ko', null, ['formality' => 'more']);

    expect($inner->translateCalls)->toBe(3);
});

it('only sends cache misses to the inner batch call and preserves order', function () {
    $inner = fakeDriver();
    $translator = new CachingTranslator($inner, arrayCache(), 'fake');

    // Warm the cache for one of the three texts.
    $translator->translate('b', 'ko');
    $inner->batchInputs = [];

    $results = $translator->translateBatch(['a', 'b', 'c'], 'ko');

    expect(array_map(fn ($r) => $r->text, $results))->toBe(['A', 'B', 'C'])
        // 'b' was already cached, so only 'a' and 'c' hit the inner driver
        ->and($inner->batchInputs)->toHaveCount(1)
        ->and(array_values($inner->batchInputs[0]))->toBe(['a', 'c']);
});
