<?php

declare(strict_types=1);

use Minhyung\LaravelTranslator\Drivers\LlmTranslator;
use Minhyung\LaravelTranslator\Support\TranslationResult;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;

function llmDriver(): LlmTranslator
{
    return new LlmTranslator('openai', 'gpt-4o-mini');
}

it('translates a single text via a text completion', function () {
    $fake = Prism::fake([
        TextResponseFake::make()->withText("  안녕하세요  \n"),
    ]);

    $result = llmDriver()->translate('Hello', 'ko', 'en');

    expect($result)->toBeInstanceOf(TranslationResult::class)
        ->and($result->text)->toBe('안녕하세요')   // trimmed
        ->and($result->driver)->toBe('llm')
        ->and($result->targetLang)->toBe('ko')
        ->and($result->detectedSourceLang)->toBe('en');

    $fake->assertCallCount(1);
});

it('includes the target language in the system prompt', function () {
    $fake = Prism::fake([
        TextResponseFake::make()->withText('Hola'),
    ]);

    llmDriver()->translate('Hello', 'es');

    $fake->assertRequest(function (array $requests) {
        $request = $requests[0];
        expect($request->systemPrompts()[0]->content)->toContain('into es');
    });
});

it('translates a batch via structured output preserving keys and order', function () {
    $fake = Prism::fake([
        StructuredResponseFake::make()->withStructured([
            'translations' => ['안녕', '세계'],
        ]),
    ]);

    $results = llmDriver()->translateBatch(['greet' => 'Hello', 'noun' => 'World'], 'ko');

    expect($results)->toHaveKeys(['greet', 'noun'])
        ->and($results['greet']->text)->toBe('안녕')
        ->and($results['noun']->text)->toBe('세계')
        ->and($results['greet']->driver)->toBe('llm');

    $fake->assertCallCount(1);
});

it('throws when the LLM returns a mismatched number of translations', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured([
            'translations' => ['안녕'], // only one for two inputs
        ]),
    ]);

    expect(fn () => llmDriver()->translateBatch(['Hello', 'World'], 'ko'))
        ->toThrow(RuntimeException::class);
});

it('returns an empty array for an empty batch without calling the LLM', function () {
    $fake = Prism::fake([]);

    expect(llmDriver()->translateBatch([], 'ko'))->toBe([]);

    $fake->assertCallCount(0);
});
