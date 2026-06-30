<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Drivers;

use Aws\Translate\TranslateClient;
use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Contracts\ListsLanguages;
use Minhyung\LaravelTranslator\Support\Language;
use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * Amazon Translate driver backed by the official aws/aws-sdk-php client.
 *
 * The real-time TranslateText operation handles one text per call, so batches
 * are translated by looping (preserving the caller's keys and order). Source
 * language defaults to "auto", in which case Amazon returns the detected source
 * on each result. Supported languages come from the ListLanguages operation.
 */
class AmazonTranslateDriver implements Driver, ListsLanguages
{
    public function __construct(
        protected TranslateClient $client,
        protected string $name = 'amazon',
    ) {
    }

    public function translate(
        string $text,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): TranslationResult {
        $payload = array_filter([
            'Text' => $text,
            'SourceLanguageCode' => $sourceLang ?? 'auto',
            'TargetLanguageCode' => $targetLang,
            'Settings' => $this->settings($options),
            'TerminologyNames' => $options['terminology_names'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== []);

        $result = $this->client->translateText($payload);

        return new TranslationResult(
            text: (string) $result->get('TranslatedText'),
            targetLang: $targetLang,
            translator: $this->name,
            detectedSourceLang: $sourceLang ?? $result->get('SourceLanguageCode'),
        );
    }

    public function translateBatch(
        array $texts,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): array {
        return array_map(
            fn (string $text): TranslationResult => $this->translate($text, $targetLang, $sourceLang, $options),
            $texts,
        );
    }

    public function languages(): array
    {
        $languages = [];
        $token = null;

        // ListLanguages paginates; follow NextToken until the list is exhausted.
        do {
            $result = $this->client->listLanguages(array_filter([
                'DisplayLanguageCode' => 'en',
                'NextToken' => $token,
            ], fn ($value): bool => $value !== null));

            foreach ($result->get('Languages') ?? [] as $language) {
                $languages[] = new Language(
                    code: $language['LanguageCode'] ?? '',
                    name: $language['LanguageName'] ?? null,
                );
            }

            $token = $result->get('NextToken');
        } while (! empty($token));

        return $languages;
    }

    /**
     * Map our generic options onto Amazon Translate's Settings shape.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function settings(array $options): array
    {
        return array_filter([
            'Formality' => $options['formality'] ?? null,
            'Profanity' => $options['profanity'] ?? null,
        ], fn ($value): bool => $value !== null);
    }
}
