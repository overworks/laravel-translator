<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Minhyung\LaravelTranslator\Drivers\LibreTranslateDriver;
use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * Build an HTTP factory faked to return the given LibreTranslate payload.
 *
 * @param  array<string, mixed>  $body
 */
function libreHttp(array $body): Factory
{
    $http = new Factory();
    $http->fake(['*' => Factory::response($body)]);

    return $http;
}

it('maps a single result, detects the source, and calls /translate', function () {
    $http = libreHttp([
        'translatedText' => ['안녕하세요'],
        'detectedLanguage' => [['confidence' => 100, 'language' => 'en']],
    ]);

    $result = (new LibreTranslateDriver($http, 'https://lt.test'))->translate('Hello', 'ko');

    expect($result)->toBeInstanceOf(TranslationResult::class)
        ->and($result->text)->toBe('안녕하세요')
        ->and($result->translator)->toBe('libretranslate')
        ->and($result->detectedSourceLang)->toBe('en');

    $http->assertSent(function ($request) {
        return $request->url() === 'https://lt.test/translate'
            && $request->data()['q'] === ['Hello']
            && $request->data()['target'] === 'ko'
            && $request->data()['source'] === 'auto'
            && ! array_key_exists('api_key', $request->data());
    });
});

it('echoes an explicit source language and includes the api key when set', function () {
    $http = libreHttp(['translatedText' => ['안녕']]);

    $result = (new LibreTranslateDriver($http, 'https://lt.test/', 'secret-key'))
        ->translate('Hello', 'ko', 'en');

    expect($result->detectedSourceLang)->toBe('en');

    $http->assertSent(function ($request) {
        return $request->data()['source'] === 'en'
            && $request->data()['api_key'] === 'secret-key';
    });
});

it('translates a batch preserving keys and order', function () {
    $http = libreHttp([
        'translatedText' => ['안녕', '세계'],
        'detectedLanguage' => [['language' => 'en'], ['language' => 'en']],
    ]);

    $results = (new LibreTranslateDriver($http, 'https://lt.test'))
        ->translateBatch(['x' => 'Hello', 'y' => 'World'], 'ko');

    expect($results)->toHaveKeys(['x', 'y'])
        ->and($results['x']->text)->toBe('안녕')
        ->and($results['y']->text)->toBe('세계')
        ->and($results['y']->detectedSourceLang)->toBe('en');
});

it('sends the html format when requested', function () {
    $http = libreHttp(['translatedText' => ['<b>안녕</b>']]);

    (new LibreTranslateDriver($http, 'https://lt.test'))
        ->translate('<b>Hello</b>', 'ko', null, ['format' => 'html']);

    $http->assertSent(fn ($request) => $request->data()['format'] === 'html');
});

it('returns an empty array for an empty batch without calling the API', function () {
    $http = new Factory();
    $http->fake();

    expect((new LibreTranslateDriver($http, 'https://lt.test'))->translateBatch([], 'ko'))->toBe([]);

    $http->assertNothingSent();
});
