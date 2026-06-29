<?php

declare(strict_types=1);

use Google\Cloud\Translate\V3\TranslateTextRequest;
use Google\Cloud\Translate\V3\TranslateTextResponse;
use Google\Cloud\Translate\V3\Translation;
use Minhyung\LaravelTranslator\Drivers\GoogleTranslator;
use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * The Google client is a final class, so instead of mocking it we subclass the
 * driver and stub the single protected API-call seam.
 */
function fakeGoogle(TranslateTextResponse $response, string $projectId = 'demo', string $location = 'global'): object
{
    return new class($response, $projectId, $location) extends GoogleTranslator {
        public ?TranslateTextRequest $captured = null;

        public function __construct(
            private TranslateTextResponse $stub,
            string $projectId,
            string $location,
        ) {
            $this->projectId = $projectId;
            $this->location = $location;
        }

        protected function callApi(TranslateTextRequest $request): TranslateTextResponse
        {
            $this->captured = $request;

            return $this->stub;
        }
    };
}

it('maps a single Google result and builds the right request', function () {
    $response = (new TranslateTextResponse())->setTranslations([
        new Translation(['translated_text' => '안녕하세요', 'detected_language_code' => 'en']),
    ]);

    $driver = fakeGoogle($response);
    $result = $driver->translate('Hello', 'ko');

    expect($result)->toBeInstanceOf(TranslationResult::class)
        ->and($result->text)->toBe('안녕하세요')
        ->and($result->driver)->toBe('google')
        ->and($result->detectedSourceLang)->toBe('en');

    $request = $driver->captured;
    expect($request->getParent())->toBe('projects/demo/locations/global')
        ->and($request->getTargetLanguageCode())->toBe('ko')
        ->and(iterator_to_array($request->getContents()))->toBe(['Hello'])
        ->and($request->getMimeType())->toBe('text/plain');
});

it('translates a batch preserving keys and echoes explicit source language', function () {
    $response = (new TranslateTextResponse())->setTranslations([
        new Translation(['translated_text' => '안녕']),
        new Translation(['translated_text' => '세계']),
    ]);

    $driver = fakeGoogle($response, 'demo', 'us-central1');
    $results = $driver->translateBatch(['x' => 'Hello', 'y' => 'World'], 'ko', 'en');

    expect($results)->toHaveKeys(['x', 'y'])
        ->and($results['x']->text)->toBe('안녕')
        ->and($results['y']->text)->toBe('세계')
        ->and($results['x']->detectedSourceLang)->toBe('en')
        ->and($driver->captured->getParent())->toBe('projects/demo/locations/us-central1')
        ->and($driver->captured->getSourceLanguageCode())->toBe('en');
});
