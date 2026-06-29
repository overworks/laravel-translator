<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Drivers;

use DeepL\DeepLClient;
use DeepL\TextResult;
use Minhyung\LaravelTranslator\Contracts\Translator;
use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * DeepL driver backed by the official deeplcom/deepl-php client.
 */
class DeeplTranslator implements Translator
{
    public function __construct(
        protected DeepLClient $client,
    ) {
    }

    public function translate(
        string $text,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): TranslationResult {
        /** @var TextResult $result */
        $result = $this->client->translateText($text, $sourceLang, $targetLang, $options);

        return $this->toResult($result, $targetLang);
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

        // DeepL returns results positionally; preserve the caller's original keys.
        $keys = array_keys($texts);

        /** @var TextResult[] $results */
        $results = $this->client->translateText(array_values($texts), $sourceLang, $targetLang, $options);

        $mapped = array_map(
            fn (TextResult $result): TranslationResult => $this->toResult($result, $targetLang),
            $results
        );

        return array_combine($keys, $mapped);
    }

    protected function toResult(TextResult $result, string $targetLang): TranslationResult
    {
        return new TranslationResult(
            text: $result->text,
            targetLang: $targetLang,
            driver: 'deepl',
            detectedSourceLang: $result->detectedSourceLang,
            billedCharacters: $result->billedCharacters,
        );
    }
}
