<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Drivers;

use Illuminate\Http\Client\Factory as HttpFactory;
use Minhyung\LaravelTranslator\Contracts\DetectsLanguage;
use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Contracts\ListsLanguages;
use Minhyung\LaravelTranslator\Support\Language;
use Minhyung\LaravelTranslator\Support\LanguageDetection;
use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * LibreTranslate driver — the free/open-source translation API, self-hosted or
 * via a public instance (e.g. libretranslate.com).
 *
 * Talks to the `/translate` endpoint over REST. An API key is optional (only
 * keyed instances require it). Batch requests send `q` as an array, which the
 * API answers with an array of translations.
 */
class LibreTranslateDriver implements Driver, DetectsLanguage, ListsLanguages
{
    /**
     * @param  HttpFactory  $http     Laravel HTTP client factory.
     * @param  string  $baseUrl  Instance base URL (e.g. https://libretranslate.com).
     * @param  string|null  $apiKey  Optional API key.
     * @param  string  $name     Translator name reported on results.
     */
    public function __construct(
        protected HttpFactory $http,
        protected string $baseUrl,
        protected ?string $apiKey = null,
        protected string $name = 'libretranslate',
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
        $format = ($options['format'] ?? 'text') === 'html' ? 'html' : 'text';

        $payload = array_filter([
            'q' => $values,
            'source' => $sourceLang ?? 'auto',
            'target' => $targetLang,
            'format' => $format,
            'api_key' => $this->apiKey,
        ], fn ($value): bool => $value !== null);

        $response = $this->http
            ->asJson()
            ->acceptJson()
            ->post(rtrim($this->baseUrl, '/') . '/translate', $payload)
            ->throw()
            ->json();

        $translated = $response['translatedText'] ?? [];
        $translated = is_array($translated) ? array_values($translated) : [$translated];
        $detected = $response['detectedLanguage'] ?? null;

        $results = [];

        foreach ($values as $i => $original) {
            $results[] = new TranslationResult(
                text: (string) ($translated[$i] ?? ''),
                targetLang: $targetLang,
                translator: $this->name,
                detectedSourceLang: $sourceLang ?? $this->detectedAt($detected, $i),
            );
        }

        return array_combine($keys, $results);
    }

    public function detect(string $text): LanguageDetection
    {
        $payload = array_filter([
            'q' => $text,
            'api_key' => $this->apiKey,
        ], fn ($value): bool => $value !== null);

        $detection = $this->http
            ->asJson()
            ->acceptJson()
            ->post(rtrim($this->baseUrl, '/') . '/detect', $payload)
            ->throw()
            ->json('0', []);

        // LibreTranslate reports confidence on a 0–100 scale; normalize to 0–1.
        $confidence = isset($detection['confidence']) ? ((float) $detection['confidence']) / 100 : null;

        return new LanguageDetection(
            language: $detection['language'] ?? '',
            translator: $this->name,
            confidence: $confidence,
        );
    }

    public function languages(): array
    {
        $languages = $this->http
            ->acceptJson()
            ->get(rtrim($this->baseUrl, '/') . '/languages')
            ->throw()
            ->json() ?? [];

        return array_map(
            fn (array $language): Language => new Language(
                code: $language['code'] ?? '',
                name: $language['name'] ?? null,
            ),
            $languages,
        );
    }

    /**
     * Pull the detected language for input $i from LibreTranslate's response,
     * which may be a per-input array or a single object.
     */
    protected function detectedAt(mixed $detected, int $i): ?string
    {
        if (! is_array($detected)) {
            return null;
        }

        if (isset($detected[$i]) && is_array($detected[$i])) {
            return $detected[$i]['language'] ?? null;
        }

        return $detected['language'] ?? null;
    }
}
