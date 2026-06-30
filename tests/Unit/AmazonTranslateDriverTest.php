<?php

declare(strict_types=1);

use Aws\MockHandler;
use Aws\Result;
use Aws\Translate\TranslateClient;
use Minhyung\LaravelTranslator\Drivers\AmazonTranslateDriver;
use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * Build a TranslateClient whose responses come from a queued MockHandler, so no
 * AWS network call is made. Returns [client, handler] for command assertions.
 *
 * @param  array<int, Result>  $results
 * @return array{0: TranslateClient, 1: MockHandler}
 */
function amazonClient(array $results): array
{
    $handler = new MockHandler();

    foreach ($results as $result) {
        $handler->append($result);
    }

    $client = new TranslateClient([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
        'handler' => $handler,
    ]);

    return [$client, $handler];
}

/**
 * Build a TranslateClient whose handler is the given callback, letting a test
 * inspect the outgoing command and return a synthetic Result.
 */
function capturingClient(callable $handler): TranslateClient
{
    $mock = new MockHandler();
    $mock->append($handler);

    return new TranslateClient([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => ['key' => 'k', 'secret' => 's'],
        'handler' => $mock,
    ]);
}

it('maps a single result and reports the detected source', function () {
    [$client] = amazonClient([
        new Result([
            'TranslatedText' => '안녕하세요',
            'SourceLanguageCode' => 'en',
            'TargetLanguageCode' => 'ko',
        ]),
    ]);

    $result = (new AmazonTranslateDriver($client))->translate('Hello', 'ko');

    expect($result)->toBeInstanceOf(TranslationResult::class)
        ->and($result->text)->toBe('안녕하세요')
        ->and($result->translator)->toBe('amazon')
        ->and($result->detectedSourceLang)->toBe('en');
});

it('defaults the source language to auto', function () {
    $captured = null;

    $client = capturingClient(function ($command) use (&$captured) {
        $captured = $command->toArray();

        return new Result(['TranslatedText' => '안녕', 'SourceLanguageCode' => 'en']);
    });

    (new AmazonTranslateDriver($client))->translate('Hello', 'ko');

    expect($captured['SourceLanguageCode'])->toBe('auto')
        ->and($captured['TargetLanguageCode'])->toBe('ko');
});

it('passes an explicit source language and formality setting', function () {
    $captured = null;

    $client = capturingClient(function ($command) use (&$captured) {
        $captured = $command->toArray();

        return new Result(['TranslatedText' => '안녕']);
    });

    (new AmazonTranslateDriver($client))->translate('Hello', 'ko', 'en', ['formality' => 'FORMAL']);

    expect($captured['SourceLanguageCode'])->toBe('en')
        ->and($captured['Settings'])->toBe(['Formality' => 'FORMAL']);
});

it('translates a batch preserving keys and order', function () {
    [$client] = amazonClient([
        new Result(['TranslatedText' => '안녕', 'SourceLanguageCode' => 'en']),
        new Result(['TranslatedText' => '세계', 'SourceLanguageCode' => 'en']),
    ]);

    $results = (new AmazonTranslateDriver($client))
        ->translateBatch(['x' => 'Hello', 'y' => 'World'], 'ko');

    expect($results)->toHaveKeys(['x', 'y'])
        ->and($results['x']->text)->toBe('안녕')
        ->and($results['y']->text)->toBe('세계');
});

it('returns an empty array for an empty batch', function () {
    [$client] = amazonClient([]);

    expect((new AmazonTranslateDriver($client))->translateBatch([], 'ko'))->toBe([]);
});

it('lists languages, following pagination', function () {
    [$client] = amazonClient([
        new Result([
            'Languages' => [
                ['LanguageCode' => 'en', 'LanguageName' => 'English'],
                ['LanguageCode' => 'ko', 'LanguageName' => 'Korean'],
            ],
            'NextToken' => 'page-2',
        ]),
        new Result([
            'Languages' => [
                ['LanguageCode' => 'ja', 'LanguageName' => 'Japanese'],
            ],
        ]),
    ]);

    $languages = (new AmazonTranslateDriver($client))->languages();

    expect($languages)->toHaveCount(3)
        ->and($languages[0]->code)->toBe('en')
        ->and($languages[0]->name)->toBe('English')
        ->and($languages[2]->code)->toBe('ja');
});
