<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Drivers;

use Google\Cloud\Translate\V3\Client\TranslationServiceClient;
use Google\Cloud\Translate\V3\Translation;
use Google\Cloud\Translate\V3\TranslateTextRequest;
use Google\Cloud\Translate\V3\TranslateTextResponse;
use Minhyung\LaravelTranslator\Contracts\Translator;
use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * Google Cloud Translation (v3) driver backed by the official
 * google/cloud-translate client, using the REST transport (no gRPC).
 */
class GoogleTranslator implements Translator
{
    public function __construct(
        protected TranslationServiceClient $client,
        protected string $projectId,
        protected string $location = 'global',
    ) {
    }

    public function translate(
        string $text,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): TranslationResult {
        return $this->translateBatch([$text], $targetLang, $sourceLang, $options)[0];
    }

    public function translateBatch(
        array $texts,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): array {
        if ($texts === []) {
            return [];
        }

        $keys = array_keys($texts);

        $request = TranslateTextRequest::build(
            parent: $this->parent(),
            targetLanguageCode: $targetLang,
            contents: array_values($texts),
        );

        $request->setMimeType($options['mimeType'] ?? 'text/plain');

        if ($sourceLang !== null) {
            $request->setSourceLanguageCode($sourceLang);
        }

        $response = $this->callApi($request);

        $mapped = [];
        foreach ($response->getTranslations() as $translation) {
            /** @var Translation $translation */
            $mapped[] = new TranslationResult(
                text: $translation->getTranslatedText(),
                targetLang: $targetLang,
                driver: 'google',
                detectedSourceLang: $sourceLang ?? ($translation->getDetectedLanguageCode() ?: null),
            );
        }

        return array_combine($keys, $mapped);
    }

    /**
     * Perform the actual API call. Extracted so it can be overridden in tests
     * (the underlying client is a final class and cannot be mocked directly).
     */
    protected function callApi(TranslateTextRequest $request): TranslateTextResponse
    {
        return $this->client->translateText($request);
    }

    protected function parent(): string
    {
        return sprintf('projects/%s/locations/%s', $this->projectId, $this->location);
    }
}
