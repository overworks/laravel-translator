<?php

declare(strict_types=1);

use DeepL\DeepLClient;
use DeepL\TextResult;
use Minhyung\LaravelTranslator\Drivers\DeeplTranslator;
use Minhyung\LaravelTranslator\Support\TranslationResult;

it('maps a single DeepL result', function () {
    $client = Mockery::mock(DeepLClient::class);
    $client->shouldReceive('translateText')
        ->once()
        ->with('Hello', 'en', 'ko', [])
        ->andReturn(new TextResult('안녕하세요', 'en', 5));

    $result = (new DeeplTranslator($client))->translate('Hello', 'ko', 'en');

    expect($result)->toBeInstanceOf(TranslationResult::class)
        ->and($result->text)->toBe('안녕하세요')
        ->and($result->driver)->toBe('deepl')
        ->and($result->targetLang)->toBe('ko')
        ->and($result->detectedSourceLang)->toBe('en')
        ->and($result->billedCharacters)->toBe(5);
});

it('translates a batch and preserves keys/order', function () {
    $client = Mockery::mock(DeepLClient::class);
    $client->shouldReceive('translateText')
        ->once()
        ->with(['Hello', 'World'], null, 'ko', [])
        ->andReturn([
            new TextResult('안녕', 'en', 5),
            new TextResult('세계', 'en', 5),
        ]);

    $results = (new DeeplTranslator($client))->translateBatch(['a' => 'Hello', 'b' => 'World'], 'ko');

    expect($results)->toHaveKeys(['a', 'b'])
        ->and($results['a']->text)->toBe('안녕')
        ->and($results['b']->text)->toBe('세계');
});

it('returns an empty array for an empty batch', function () {
    $client = Mockery::mock(DeepLClient::class);
    $client->shouldNotReceive('translateText');

    expect((new DeeplTranslator($client))->translateBatch([], 'ko'))->toBe([]);
});
