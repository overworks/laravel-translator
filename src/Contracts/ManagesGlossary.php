<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Contracts;

use Minhyung\LaravelTranslator\Support\Glossary;

/**
 * Optional capability implemented by drivers that can manage glossaries
 * (DeepL) or terminologies (Amazon Translate) — named term overrides applied
 * during translation, referenced from a translate() call via the "glossary"
 * option.
 *
 * A {@see \Minhyung\LaravelTranslator\Translator} exposes these and throws a
 * clear error when its driver does not implement this.
 */
interface ManagesGlossary
{
    /**
     * Create a glossary from a source-term → target-term map.
     *
     * @param  array<string, string>  $entries  Source term => target term.
     * @param  array<string, mixed>  $options   Driver-specific extras (e.g. description).
     */
    public function createGlossary(
        string $name,
        string $sourceLang,
        string $targetLang,
        array $entries,
        array $options = []
    ): Glossary;

    /**
     * List all glossaries registered with the provider.
     *
     * @return array<int, Glossary>
     */
    public function glossaries(): array;

    /**
     * Fetch a single glossary's metadata.
     */
    public function glossary(string $id): Glossary;

    /**
     * Fetch a glossary's entries as a source-term → target-term map.
     *
     * @return array<string, string>
     */
    public function glossaryEntries(string $id): array;

    /**
     * Delete a glossary.
     */
    public function deleteGlossary(string $id): void;
}
