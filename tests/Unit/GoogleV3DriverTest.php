<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Minhyung\LaravelTranslator\Drivers\GoogleV3Driver;
use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * Build an HTTP factory faked to return the given v3 translations payload.
 *
 * @param  array<int, array<string, string>>  $translations
 */
function googleV3Http(array $translations): Factory
{
    $http = new Factory();
    $http->fake([
        '*' => Factory::response(['translations' => $translations]),
    ]);

    return $http;
}

function v3Driver(Factory $http): GoogleV3Driver
{
    return new GoogleV3Driver($http, fn (): string => 'fake-token', 'my-project', 'global');
}

it('maps a single v3 result and calls the OAuth-authenticated endpoint', function () {
    $http = googleV3Http([
        ['translatedText' => '안녕하세요', 'detectedLanguageCode' => 'en'],
    ]);

    $result = v3Driver($http)->translate('Hello', 'ko');

    expect($result)->toBeInstanceOf(TranslationResult::class)
        ->and($result->text)->toBe('안녕하세요')
        ->and($result->translator)->toBe('google')
        ->and($result->detectedSourceLang)->toBe('en');

    $http->assertSent(function ($request) {
        return str_contains($request->url(), '/v3/projects/my-project/locations/global:translateText')
            && $request->hasHeader('Authorization', 'Bearer fake-token')
            && $request->data()['targetLanguageCode'] === 'ko'
            && $request->data()['contents'] === ['Hello'];
    });
});

it('translates a batch preserving keys and echoes the explicit source language', function () {
    $http = googleV3Http([
        ['translatedText' => '안녕'],
        ['translatedText' => '세계'],
    ]);

    $results = (new GoogleV3Driver($http, fn (): string => 't', 'p'))
        ->translateBatch(['x' => 'Hello', 'y' => 'World'], 'ko', 'en');

    expect($results)->toHaveKeys(['x', 'y'])
        ->and($results['x']->text)->toBe('안녕')
        ->and($results['y']->text)->toBe('세계')
        ->and($results['x']->detectedSourceLang)->toBe('en');
});

it('decodes HTML entities in plain-text results', function () {
    $http = googleV3Http([['translatedText' => 'It&#39;s a &quot;test&quot;']]);

    expect(v3Driver($http)->translate('x', 'en')->text)->toBe('It\'s a "test"');
});

it('returns an empty array for an empty batch without calling the API', function () {
    $http = new Factory();
    $http->fake();

    expect(v3Driver($http)->translateBatch([], 'ko'))->toBe([]);

    $http->assertNothingSent();
});

it('detects the language via :detectLanguage', function () {
    $http = new Factory();
    $http->fake(['*' => Factory::response(['languages' => [['languageCode' => 'en', 'confidence' => 1.0]]])]);

    $detection = v3Driver($http)->detect('Hello');

    expect($detection->language)->toBe('en')
        ->and($detection->translator)->toBe('google')
        ->and($detection->confidence)->toBe(1.0);

    $http->assertSent(fn ($request) => str_contains($request->url(), ':detectLanguage')
        && $request->hasHeader('Authorization', 'Bearer fake-token')
        && $request->data()['content'] === 'Hello');
});
