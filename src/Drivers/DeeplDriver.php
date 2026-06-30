<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Drivers;

use DeepL\DeepLClient;
use DeepL\GlossaryEntries;
use DeepL\GlossaryInfo;
use DeepL\Language as DeepLLanguage;
use DeepL\TextResult;
use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Contracts\ListsLanguages;
use Minhyung\LaravelTranslator\Contracts\ManagesGlossary;
use Minhyung\LaravelTranslator\Support\Glossary;
use Minhyung\LaravelTranslator\Support\Language;
use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * DeepL driver backed by the official deeplcom/deepl-php client.
 *
 * Glossaries are referenced from a translate() call via the "glossary" option
 * (the DeepL client passes it straight through), and managed with the
 * {@see ManagesGlossary} methods.
 */
class DeeplDriver implements Driver, ListsLanguages, ManagesGlossary
{
    public function __construct(
        protected DeepLClient $client,
        protected string $name = 'deepl',
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

    public function languages(): array
    {
        $map = [];

        /** @var DeepLLanguage $language */
        foreach ($this->client->getSourceLanguages() as $language) {
            $map[$language->code] = new Language($language->code, $language->name, source: true, target: false);
        }

        /** @var DeepLLanguage $language */
        foreach ($this->client->getTargetLanguages() as $language) {
            $isSource = isset($map[$language->code]);
            $map[$language->code] = new Language(
                code: $language->code,
                name: $language->name,
                source: $isSource,
                target: true,
            );
        }

        return array_values($map);
    }

    public function createGlossary(
        string $name,
        string $sourceLang,
        string $targetLang,
        array $entries,
        array $options = []
    ): Glossary {
        return $this->toGlossary(
            $this->client->createGlossary($name, $sourceLang, $targetLang, GlossaryEntries::fromEntries($entries))
        );
    }

    public function glossaries(): array
    {
        return array_map(
            fn (GlossaryInfo $info): Glossary => $this->toGlossary($info),
            $this->client->listGlossaries()
        );
    }

    public function glossary(string $id): Glossary
    {
        return $this->toGlossary($this->client->getGlossary($id));
    }

    public function glossaryEntries(string $id): array
    {
        return $this->client->getGlossaryEntries($id)->getEntries();
    }

    public function deleteGlossary(string $id): void
    {
        $this->client->deleteGlossary($id);
    }

    protected function toGlossary(GlossaryInfo $info): Glossary
    {
        return new Glossary(
            id: $info->glossaryId,
            name: $info->name,
            sourceLang: $info->sourceLang,
            targetLangs: [$info->targetLang],
            translator: $this->name,
            entryCount: $info->entryCount,
            ready: $info->ready,
        );
    }

    protected function toResult(TextResult $result, string $targetLang): TranslationResult
    {
        return new TranslationResult(
            text: $result->text,
            targetLang: $targetLang,
            translator: $this->name,
            detectedSourceLang: $result->detectedSourceLang,
            billedCharacters: $result->billedCharacters,
        );
    }
}
