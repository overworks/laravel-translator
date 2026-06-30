<?php

declare(strict_types=1);

use Anthropic\Resources\Messages;
use Anthropic\Responses\Messages\CreateResponse;
use Anthropic\Testing\ClientFake;
use Minhyung\LaravelTranslator\Drivers\ClaudeDriver;
use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * Build a faked Anthropic client returning the given text content blocks.
 */
function fakeAnthropic(string ...$contents): ClientFake
{
    return new ClientFake(array_map(
        fn (string $content): CreateResponse => CreateResponse::fake([
            'content' => [['type' => 'text', 'text' => $content]],
        ]),
        $contents,
    ));
}

it('translates a single text and sends model, system, and max_tokens', function () {
    $client = fakeAnthropic('안녕하세요');

    $result = (new ClaudeDriver($client, 'claude-3-5-sonnet-latest'))
        ->translate('Hello', 'ko', 'en');

    expect($result)->toBeInstanceOf(TranslationResult::class)
        ->and($result->text)->toBe('안녕하세요')
        ->and($result->translator)->toBe('claude')
        ->and($result->detectedSourceLang)->toBe('en');

    $client->assertSent(Messages::class, function (string $method, array $params): bool {
        return $method === 'create'
            && $params['model'] === 'claude-3-5-sonnet-latest'
            && is_string($params['system'])
            && $params['max_tokens'] === 4096
            && $params['messages'][0]['content'] === 'Hello';
    });
});

it('concatenates multiple text blocks', function () {
    $client = new ClientFake([
        CreateResponse::fake([
            'content' => [
                ['type' => 'text', 'text' => '안녕'],
                ['type' => 'text', 'text' => '하세요'],
            ],
        ]),
    ]);

    expect((new ClaudeDriver($client, 'm'))->translate('Hello', 'ko')->text)
        ->toBe('안녕하세요');
});

it('translates a batch from a JSON object, preserving keys and order', function () {
    $client = fakeAnthropic('{"translations": ["안녕", "세계"]}');

    $results = (new ClaudeDriver($client, 'm'))
        ->translateBatch(['x' => 'Hello', 'y' => 'World'], 'ko');

    expect($results)->toHaveKeys(['x', 'y'])
        ->and($results['x']->text)->toBe('안녕')
        ->and($results['y']->text)->toBe('세계');
});

it('tolerates a JSON code fence around the batch response', function () {
    $client = fakeAnthropic("```json\n{\"translations\": [\"안녕\"]}\n```");

    $results = (new ClaudeDriver($client, 'm'))->translateBatch(['x' => 'Hello'], 'ko');

    expect($results['x']->text)->toBe('안녕');
});

it('throws when the batch count does not match the input', function () {
    $client = fakeAnthropic('{"translations": ["안녕"]}');

    expect(fn () => (new ClaudeDriver($client, 'm'))
        ->translateBatch(['x' => 'Hello', 'y' => 'World'], 'ko'))
        ->toThrow(RuntimeException::class);
});

it('passes temperature and max_tokens options through', function () {
    $client = fakeAnthropic('translated');

    (new ClaudeDriver($client, 'm', ['temperature' => 0.2]))
        ->translate('Hello', 'ko', null, ['max_tokens' => 256]);

    $client->assertSent(Messages::class, function (string $method, array $params): bool {
        return $params['temperature'] === 0.2 && $params['max_tokens'] === 256;
    });
});

it('returns an empty array for an empty batch without calling the API', function () {
    $client = new ClientFake();

    expect((new ClaudeDriver($client, 'm'))->translateBatch([], 'ko'))->toBe([]);

    $client->assertNothingSent();
});
