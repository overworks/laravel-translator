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
 * Google Cloud Translation (v2) driver.
 *
 * Uses the simple REST endpoint authenticated with an API key, so no
 * service-account credentials or gRPC are required.
 */
class GoogleDriver implements Driver, DetectsLanguage, ListsLanguages
{
    public function __construct(
        protected HttpFactory $http,
        protected string $key,
        protected string $name = 'google',
        protected string $endpoint = 'https://translation.googleapis.com/language/translate/v2',
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
        $format = $options['format'] ?? 'text';

        $payload = array_filter([
            'q'      => array_values($texts),
            'target' => $targetLang,
            'source' => $sourceLang,
            'format' => $format,
        ], fn ($value): bool => $value !== null);

        $translations = $this->http
            ->withQueryParameters(['key' => $this->key])
            ->asJson()
            ->acceptJson()
            ->post($this->endpoint, $payload)
            ->throw()
            ->json('data.translations', []);

        $mapped = array_map(
            fn (array $translation): TranslationResult => new TranslationResult(
                text: $this->decode($translation['translatedText'] ?? '', $format),
                targetLang: $targetLang,
                translator: $this->name,
                detectedSourceLang: $sourceLang ?? ($translation['detectedSourceLanguage'] ?? null),
            ),
            $translations,
        );

        return array_combine($keys, $mapped);
    }

    public function detect(string $text): LanguageDetection
    {
        $detection = $this->http
            ->withQueryParameters(['key' => $this->key])
            ->asJson()
            ->acceptJson()
            ->post("{$this->endpoint}/detect", ['q' => $text])
            ->throw()
            ->json('data.detections.0.0', []);

        return new LanguageDetection(
            language: $detection['language'] ?? '',
            translator: $this->name,
            confidence: isset($detection['confidence']) ? (float) $detection['confidence'] : null,
        );
    }

    public function languages(): array
    {
        $languages = $this->http
            ->acceptJson()
            ->get("{$this->endpoint}/languages", ['key' => $this->key, 'target' => 'en'])
            ->throw()
            ->json('data.languages', []);

        return array_map(
            fn (array $language): Language => new Language(
                code: $language['language'] ?? '',
                name: $language['name'] ?? null,
            ),
            $languages,
        );
    }

    /**
     * The v2 API HTML-escapes translated text; undo it for plain-text output.
     */
    protected function decode(string $text, string $format): string
    {
        return $format === 'html'
            ? $text
            : html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
