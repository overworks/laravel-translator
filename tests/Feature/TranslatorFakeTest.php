<?php

declare(strict_types=1);

use Minhyung\LaravelTranslator\Contracts\Translator as TranslatorContract;
use Minhyung\LaravelTranslator\Facades\Translator;
use Minhyung\LaravelTranslator\Testing\TranslatorFake;
use Minhyung\LaravelTranslator\TranslatorManager;

it('records translations and echoes the source text by default', function () {
    $fake = Translator::fake();

    $result = Translator::translate('Hello', 'ko');

    expect($result->text)->toBe('Hello')
        ->and($fake)->toBeInstanceOf(TranslatorFake::class);

    $fake->assertTranslated('Hello');
    $fake->assertTranslatedCount(1);
});

it('swaps the manager in the container', function () {
    $fake = Translator::fake();

    expect(app(TranslatorManager::class))->toBe($fake)
        ->and($fake)->toBeInstanceOf(TranslatorManager::class);
});

it('returns canned translations from a map', function () {
    Translator::fake(['Hello' => '안녕']);

    expect(Translator::translate('Hello', 'ko')->text)->toBe('안녕')
        ->and(Translator::translate('Bye', 'ko')->text)->toBe('Bye'); // unknown echoes
});

it('returns canned translations from a callback', function () {
    Translator::fake(fn (string $text, string $target) => "{$text}=>{$target}");

    expect(Translator::translate('Hi', 'ko')->text)->toBe('Hi=>ko');
});

it('records the translator selected with via', function () {
    $fake = Translator::fake();

    Translator::via('claude')->translate('Hi', 'ko', 'en');

    $fake->assertTranslated('Hi', fn (array $r) => $r['translator'] === 'claude'
        && $r['target'] === 'ko'
        && $r['source'] === 'en');
});

it('records each item of a batch and preserves keys', function () {
    $fake = Translator::fake();

    $results = Translator::translateBatch(['a' => 'Hello', 'b' => 'World'], 'ko');

    expect($results)->toHaveKeys(['a', 'b'])
        ->and($results['a']->text)->toBe('Hello');

    $fake->assertTranslated('Hello');
    $fake->assertTranslated('World');
    $fake->assertTranslatedCount(2);
});

it('supports nothing/not/times assertions', function () {
    $fake = Translator::fake();

    $fake->assertNothingTranslated();

    Translator::translate('Hello', 'ko');
    Translator::translate('Hello', 'ja');

    $fake->assertTranslatedTimes('Hello', 2);
    $fake->assertNotTranslated('Goodbye');
});

it('is used by the injected Translator contract', function () {
    $fake = Translator::fake();

    app(TranslatorContract::class)->translate('Injected', 'ko');

    $fake->assertTranslated('Injected');
});

it('records translators built at runtime', function () {
    $fake = Translator::fake();

    Translator::build(['driver' => 'openai'])->translate('Built', 'ko');

    $fake->assertTranslated('Built', fn (array $r) => $r['translator'] === 'openai');
});
