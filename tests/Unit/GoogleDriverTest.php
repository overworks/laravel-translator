<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Minhyung\LaravelTranslator\Drivers\GoogleDriver;
use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * Build an HTTP factory faked to return the given v2 translations payload.
 *
 * @param  array<int, array<string, string>>  $translations
 */
function googleHttp(array $translations): Factory
{
    $http = new Factory();
    $http->fake([
        '*' => Factory::response(['data' => ['translations' => $translations]]),
    ]);

    return $http;
}

it('maps a single v2 result and calls the API-key endpoint', function () {
    $http = googleHttp([
        ['translatedText' => '안녕하세요', 'detectedSourceLanguage' => 'en'],
    ]);

    $result = (new GoogleDriver($http, 'test-key'))->translate('Hello', 'ko');

    expect($result)->toBeInstanceOf(TranslationResult::class)
        ->and($result->text)->toBe('안녕하세요')
        ->and($result->translator)->toBe('google')
        ->and($result->detectedSourceLang)->toBe('en');

    $http->assertSent(function ($request) {
        return str_contains($request->url(), '/language/translate/v2')
            && str_contains($request->url(), 'key=test-key')
            && $request->data()['target'] === 'ko'
            && $request->data()['q'] === ['Hello'];
    });
});

it('translates a batch preserving keys and echoes explicit source language', function () {
    $http = googleHttp([
        ['translatedText' => '안녕'],
        ['translatedText' => '세계'],
    ]);

    $results = (new GoogleDriver($http, 'k'))
        ->translateBatch(['x' => 'Hello', 'y' => 'World'], 'ko', 'en');

    expect($results)->toHaveKeys(['x', 'y'])
        ->and($results['x']->text)->toBe('안녕')
        ->and($results['y']->text)->toBe('세계')
        ->and($results['x']->detectedSourceLang)->toBe('en');
});

it('decodes HTML entities in plain-text results', function () {
    $http = googleHttp([['translatedText' => 'It&#39;s a &quot;test&quot;']]);

    expect((new GoogleDriver($http, 'k'))->translate('x', 'en')->text)
        ->toBe('It\'s a "test"');
});

it('returns an empty array for an empty batch without calling the API', function () {
    $http = new Factory();
    $http->fake();

    expect((new GoogleDriver($http, 'k'))->translateBatch([], 'ko'))->toBe([]);

    $http->assertNothingSent();
});

it('detects the language via the /detect endpoint', function () {
    $http = new Factory();
    $http->fake([
        '*' => Factory::response(['data' => ['detections' => [[['language' => 'en', 'confidence' => 0.98]]]]]),
    ]);

    $detection = (new GoogleDriver($http, 'test-key'))->detect('Hello');

    expect($detection->language)->toBe('en')
        ->and($detection->translator)->toBe('google')
        ->and($detection->confidence)->toBe(0.98);

    $http->assertSent(fn ($request) => str_contains($request->url(), '/v2/detect')
        && str_contains($request->url(), 'key=test-key')
        && $request->data()['q'] === 'Hello');
});

it('lists languages via the /languages endpoint', function () {
    $http = new Factory();
    $http->fake([
        '*' => Factory::response(['data' => ['languages' => [
            ['language' => 'en', 'name' => 'English'],
            ['language' => 'ko', 'name' => 'Korean'],
        ]]]),
    ]);

    $languages = (new GoogleDriver($http, 'test-key'))->languages();

    expect($languages)->toHaveCount(2)
        ->and($languages[0]->code)->toBe('en')
        ->and($languages[0]->name)->toBe('English');

    $http->assertSent(fn ($request) => str_contains($request->url(), '/v2/languages')
        && str_contains($request->url(), 'key=test-key'));
});
