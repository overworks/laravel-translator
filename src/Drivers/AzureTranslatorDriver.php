<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Drivers;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Minhyung\LaravelTranslator\Contracts\DetectsLanguage;
use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Contracts\ListsLanguages;
use Minhyung\LaravelTranslator\Support\Language;
use Minhyung\LaravelTranslator\Support\LanguageDetection;
use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * Azure AI Translator driver (Translator REST API v3.0).
 *
 * Talks to the global endpoint (api.cognitive.microsofttranslator.com) over
 * REST, authenticated with a subscription key. Regional and multi-service
 * resources additionally require a region. Batch requests are native: the API
 * accepts an array of inputs and answers with an array of translations.
 */
class AzureTranslatorDriver implements Driver, DetectsLanguage, ListsLanguages
{
    /**
     * @param  HttpFactory  $http     Laravel HTTP client factory.
     * @param  string  $key      Subscription (Ocp-Apim-Subscription-Key) key.
     * @param  string|null  $region   Resource region, for regional/multi-service keys.
     * @param  string  $endpoint  API base URL (override for sovereign clouds).
     * @param  string  $apiVersion  Translator API version.
     * @param  string  $name     Translator name reported on results.
     */
    public function __construct(
        protected HttpFactory $http,
        protected string $key,
        protected ?string $region = null,
        protected string $endpoint = 'https://api.cognitive.microsofttranslator.com',
        protected string $apiVersion = '3.0',
        protected string $name = 'azure',
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
        $values = array_values($texts);

        $query = array_filter([
            'api-version' => $this->apiVersion,
            'to' => $targetLang,
            'from' => $sourceLang,
            'textType' => ($options['format'] ?? 'plain') === 'html' ? 'html' : 'plain',
        ], fn ($value): bool => $value !== null);

        $body = array_map(fn (string $text): array => ['Text' => $text], $values);

        $response = $this->request()
            ->withQueryParameters($query)
            ->post(rtrim($this->endpoint, '/') . '/translate', $body)
            ->throw()
            ->json() ?? [];

        $results = [];

        foreach ($values as $i => $original) {
            $entry = $response[$i] ?? [];

            $results[] = new TranslationResult(
                text: (string) ($entry['translations'][0]['text'] ?? ''),
                targetLang: $targetLang,
                translator: $this->name,
                detectedSourceLang: $sourceLang ?? ($entry['detectedLanguage']['language'] ?? null),
            );
        }

        return array_combine($keys, $results);
    }

    public function detect(string $text): LanguageDetection
    {
        $detection = $this->request()
            ->withQueryParameters(['api-version' => $this->apiVersion])
            ->post(rtrim($this->endpoint, '/') . '/detect', [['Text' => $text]])
            ->throw()
            ->json('0', []);

        return new LanguageDetection(
            language: $detection['language'] ?? '',
            translator: $this->name,
            confidence: isset($detection['score']) ? (float) $detection['score'] : null,
        );
    }

    public function languages(): array
    {
        // The /languages endpoint is public and ignores the subscription key.
        $translation = $this->http
            ->acceptJson()
            ->withQueryParameters(['api-version' => $this->apiVersion, 'scope' => 'translation'])
            ->get(rtrim($this->endpoint, '/') . '/languages')
            ->throw()
            ->json('translation', []);

        $languages = [];

        foreach ($translation as $code => $meta) {
            $languages[] = new Language(
                code: (string) $code,
                name: $meta['name'] ?? null,
            );
        }

        return $languages;
    }

    /**
     * A request carrying the Azure auth headers (and region, when set).
     */
    protected function request(): PendingRequest
    {
        $headers = ['Ocp-Apim-Subscription-Key' => $this->key];

        if (! empty($this->region)) {
            $headers['Ocp-Apim-Subscription-Region'] = $this->region;
        }

        return $this->http
            ->asJson()
            ->acceptJson()
            ->withHeaders($headers);
    }
}
