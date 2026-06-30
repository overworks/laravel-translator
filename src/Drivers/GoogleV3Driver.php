<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Drivers;

use Closure;
use Illuminate\Http\Client\Factory as HttpFactory;
use Minhyung\LaravelTranslator\Contracts\DetectsLanguage;
use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Contracts\ListsLanguages;
use Minhyung\LaravelTranslator\Support\Language;
use Minhyung\LaravelTranslator\Support\LanguageDetection;
use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * Google Cloud Translation - Advanced (v3) driver.
 *
 * v3 has no API-key support, so requests are authenticated with an OAuth bearer
 * token (from a service account / Application Default Credentials). The token is
 * supplied by a provider closure so this class stays free of the auth library
 * and is easy to test.
 */
class GoogleV3Driver implements Driver, DetectsLanguage, ListsLanguages
{
    /**
     * @param  HttpFactory  $http      Laravel HTTP client factory.
     * @param  Closure(): string  $token  Returns a fresh OAuth bearer token.
     * @param  string  $project   Google Cloud project id.
     * @param  string  $location  Resource location (e.g. "global").
     * @param  string  $name      Translator name reported on results.
     */
    public function __construct(
        protected HttpFactory $http,
        protected Closure $token,
        protected string $project,
        protected string $location = 'global',
        protected string $name = 'google',
        protected string $endpoint = 'https://translation.googleapis.com/v3',
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
        $mimeType = ($options['format'] ?? 'text') === 'html' ? 'text/html' : 'text/plain';

        $payload = array_filter([
            'contents' => array_values($texts),
            'targetLanguageCode' => $targetLang,
            'sourceLanguageCode' => $sourceLang,
            'mimeType' => $mimeType,
        ], fn ($value): bool => $value !== null);

        $url = "{$this->endpoint}/projects/{$this->project}/locations/{$this->location}:translateText";

        $translations = $this->http
            ->withToken(($this->token)())
            ->asJson()
            ->acceptJson()
            ->post($url, $payload)
            ->throw()
            ->json('translations', []);

        $mapped = array_map(
            fn (array $translation): TranslationResult => new TranslationResult(
                text: $this->decode($translation['translatedText'] ?? '', $mimeType),
                targetLang: $targetLang,
                translator: $this->name,
                detectedSourceLang: $sourceLang ?? ($translation['detectedLanguageCode'] ?? null),
            ),
            $translations,
        );

        return array_combine($keys, $mapped);
    }

    public function detect(string $text): LanguageDetection
    {
        $url = "{$this->endpoint}/projects/{$this->project}/locations/{$this->location}:detectLanguage";

        $language = $this->http
            ->withToken(($this->token)())
            ->asJson()
            ->acceptJson()
            ->post($url, ['content' => $text, 'mimeType' => 'text/plain'])
            ->throw()
            ->json('languages.0', []);

        return new LanguageDetection(
            language: $language['languageCode'] ?? '',
            translator: $this->name,
            confidence: isset($language['confidence']) ? (float) $language['confidence'] : null,
        );
    }

    public function languages(): array
    {
        $url = "{$this->endpoint}/projects/{$this->project}/locations/{$this->location}/supportedLanguages";

        $languages = $this->http
            ->withToken(($this->token)())
            ->acceptJson()
            ->get($url, ['displayLanguageCode' => 'en'])
            ->throw()
            ->json('languages', []);

        return array_map(
            fn (array $language): Language => new Language(
                code: $language['languageCode'] ?? '',
                name: $language['displayName'] ?? null,
                source: (bool) ($language['supportSource'] ?? true),
                target: (bool) ($language['supportTarget'] ?? true),
            ),
            $languages,
        );
    }

    /**
     * Plain-text responses may carry HTML entities; decode them.
     */
    protected function decode(string $text, string $mimeType): string
    {
        return $mimeType === 'text/html'
            ? $text
            : html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
