<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Minhyung\LaravelTranslator\Drivers\AzureTranslatorDriver;
use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * Build an HTTP factory faked to return the given Azure payload.
 */
function azureHttp(mixed $body): Factory
{
    $http = new Factory();
    $http->fake(['*' => Factory::response($body)]);

    return $http;
}

it('maps a single result, detects the source, and calls /translate', function () {
    $http = azureHttp([
        [
            'detectedLanguage' => ['language' => 'en', 'score' => 1.0],
            'translations' => [['text' => '안녕하세요', 'to' => 'ko']],
        ],
    ]);

    $result = (new AzureTranslatorDriver($http, 'secret-key'))->translate('Hello', 'ko');

    expect($result)->toBeInstanceOf(TranslationResult::class)
        ->and($result->text)->toBe('안녕하세요')
        ->and($result->translator)->toBe('azure')
        ->and($result->detectedSourceLang)->toBe('en');

    $http->assertSent(function ($request) {
        return str_starts_with($request->url(), 'https://api.cognitive.microsofttranslator.com/translate')
            && $request->data() === [['Text' => 'Hello']]
            && $request->hasHeader('Ocp-Apim-Subscription-Key', 'secret-key')
            && ! $request->hasHeader('Ocp-Apim-Subscription-Region');
    });
});

it('sends the region header and an explicit source language', function () {
    $http = azureHttp([
        ['translations' => [['text' => '안녕', 'to' => 'ko']]],
    ]);

    $result = (new AzureTranslatorDriver($http, 'secret-key', 'koreacentral'))
        ->translate('Hello', 'ko', 'en');

    expect($result->detectedSourceLang)->toBe('en');

    $http->assertSent(function ($request) {
        return $request->hasHeader('Ocp-Apim-Subscription-Region', 'koreacentral')
            && str_contains($request->url(), 'from=en')
            && str_contains($request->url(), 'to=ko');
    });
});

it('translates a batch preserving keys and order', function () {
    $http = azureHttp([
        ['detectedLanguage' => ['language' => 'en'], 'translations' => [['text' => '안녕']]],
        ['detectedLanguage' => ['language' => 'en'], 'translations' => [['text' => '세계']]],
    ]);

    $results = (new AzureTranslatorDriver($http, 'secret-key'))
        ->translateBatch(['x' => 'Hello', 'y' => 'World'], 'ko');

    expect($results)->toHaveKeys(['x', 'y'])
        ->and($results['x']->text)->toBe('안녕')
        ->and($results['y']->text)->toBe('세계')
        ->and($results['y']->detectedSourceLang)->toBe('en');

    $http->assertSent(fn ($request) => $request->data() === [['Text' => 'Hello'], ['Text' => 'World']]);
});

it('sends the html text type when requested', function () {
    $http = azureHttp([['translations' => [['text' => '<b>안녕</b>']]]]);

    (new AzureTranslatorDriver($http, 'secret-key'))
        ->translate('<b>Hello</b>', 'ko', null, ['format' => 'html']);

    $http->assertSent(fn ($request) => str_contains($request->url(), 'textType=html'));
});

it('returns an empty array for an empty batch without calling the API', function () {
    $http = new Factory();
    $http->fake();

    expect((new AzureTranslatorDriver($http, 'secret-key'))->translateBatch([], 'ko'))->toBe([]);

    $http->assertNothingSent();
});

it('detects the language via /detect with a 0-1 score', function () {
    $http = azureHttp([
        ['language' => 'en', 'score' => 0.97, 'isTranslationSupported' => true],
    ]);

    $detection = (new AzureTranslatorDriver($http, 'secret-key'))->detect('Hello');

    expect($detection->language)->toBe('en')
        ->and($detection->translator)->toBe('azure')
        ->and($detection->confidence)->toBe(0.97);

    $http->assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.cognitive.microsofttranslator.com/detect')
        && $request->data() === [['Text' => 'Hello']]);
});

it('lists languages via /languages', function () {
    $http = azureHttp([
        'translation' => [
            'en' => ['name' => 'English', 'nativeName' => 'English', 'dir' => 'ltr'],
            'ko' => ['name' => 'Korean', 'nativeName' => '한국어', 'dir' => 'ltr'],
        ],
    ]);

    $languages = (new AzureTranslatorDriver($http, 'secret-key'))->languages();

    expect($languages)->toHaveCount(2)
        ->and($languages[0]->code)->toBe('en')
        ->and($languages[0]->name)->toBe('English')
        ->and($languages[1]->code)->toBe('ko');

    $http->assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.cognitive.microsofttranslator.com/languages')
        && str_contains($request->url(), 'scope=translation'));
});
