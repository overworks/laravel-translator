<?php

declare(strict_types=1);

use Minhyung\LaravelTranslator\Facades\Translator;

it('translates one text into several target languages, keyed by target', function () {
    $fake = Translator::fake(); // echoes the source

    $results = Translator::translateInto(['ko', 'ja', 'es'], 'Hello');

    expect($results)->toHaveKeys(['ko', 'ja', 'es'])
        ->and($results['ko']->targetLang)->toBe('ko')
        ->and($results['ja']->targetLang)->toBe('ja')
        ->and($results['ko']->text)->toBe('Hello');

    $fake->assertTranslatedCount(3);
});

it('passes the source language and options through to each target', function () {
    Translator::fake(['Hello' => '안녕']);

    $results = Translator::via('deepl')->translateInto(['ko', 'ja'], 'Hello', 'en');

    expect($results['ko']->text)->toBe('안녕')
        ->and($results['ja']->text)->toBe('안녕')
        ->and($results['ko']->detectedSourceLang)->toBe('en');
});

it('returns an empty array for no targets', function () {
    Translator::fake();

    expect(Translator::translateInto([], 'Hello'))->toBe([]);
});
