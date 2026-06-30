<?php

declare(strict_types=1);

use Minhyung\LaravelTranslator\Drivers\OpenAiDriver;
use Minhyung\LaravelTranslator\Support\TranslationResult;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;

/**
 * Build a faked OpenAI client returning the given assistant message content.
 */
function fakeOpenAi(string ...$contents): ClientFake
{
    return new ClientFake(array_map(
        fn (string $content): CreateResponse => CreateResponse::fake([
            'choices' => [
                ['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop'],
            ],
        ]),
        $contents,
    ));
}

it('translates a single text and sends the configured model', function () {
    $client = fakeOpenAi('안녕하세요');

    $result = (new OpenAiDriver($client, 'my-model', name: 'custom'))
        ->translate('Hello', 'ko', 'en');

    expect($result)->toBeInstanceOf(TranslationResult::class)
        ->and($result->text)->toBe('안녕하세요')
        ->and($result->translator)->toBe('custom')
        ->and($result->detectedSourceLang)->toBe('en');

    $client->assertSent(Chat::class, function (string $method, array $params): bool {
        return $method === 'create'
            && $params['model'] === 'my-model'
            && $params['messages'][1]['content'] === 'Hello';
    });
});

it('translates a batch from a JSON object, preserving keys and order', function () {
    $client = fakeOpenAi('{"translations": ["안녕", "세계"]}');

    $results = (new OpenAiDriver($client, 'm'))
        ->translateBatch(['x' => 'Hello', 'y' => 'World'], 'ko');

    expect($results)->toHaveKeys(['x', 'y'])
        ->and($results['x']->text)->toBe('안녕')
        ->and($results['y']->text)->toBe('세계');

    // Batch requests JSON mode.
    $client->assertSent(Chat::class, function (string $method, array $params): bool {
        return ($params['response_format']['type'] ?? null) === 'json_object';
    });
});

it('throws when the batch count does not match the input', function () {
    $client = fakeOpenAi('{"translations": ["안녕"]}');

    expect(fn () => (new OpenAiDriver($client, 'm'))
        ->translateBatch(['x' => 'Hello', 'y' => 'World'], 'ko'))
        ->toThrow(RuntimeException::class);
});

it('passes temperature and max_tokens options through', function () {
    $client = fakeOpenAi('translated');

    (new OpenAiDriver($client, 'm', ['temperature' => 0.2]))
        ->translate('Hello', 'ko', null, ['max_tokens' => 256]);

    $client->assertSent(Chat::class, function (string $method, array $params): bool {
        return $params['temperature'] === 0.2 && $params['max_tokens'] === 256;
    });
});

it('returns an empty array for an empty batch without calling the API', function () {
    $client = new ClientFake();

    expect((new OpenAiDriver($client, 'm'))->translateBatch([], 'ko'))->toBe([]);

    $client->assertNothingSent();
});
