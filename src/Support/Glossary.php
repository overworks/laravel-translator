<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Support;

use Stringable;

/**
 * A glossary (DeepL) or terminology (Amazon Translate) registered with a
 * provider: a named set of source→target term overrides applied during
 * translation.
 *
 * Providers differ in shape — DeepL glossaries are a single source/target pair,
 * while an Amazon terminology can carry several target languages — so the target
 * side is always exposed as a list ({@see $targetLangs}).
 */
final readonly class Glossary implements Stringable
{
    /**
     * @param  string  $id          Provider identifier used to reference the glossary.
     * @param  string  $name        Human-readable name given at creation.
     * @param  string  $sourceLang  Language code of the source terms.
     * @param  array<int, string>  $targetLangs  Language codes of the target terms.
     * @param  string  $translator  The translator the glossary belongs to.
     * @param  int|null  $entryCount  Number of term pairs, when the provider reports it.
     * @param  bool  $ready        Whether the glossary can be used for translations yet.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $sourceLang,
        public array $targetLangs,
        public string $translator,
        public ?int $entryCount = null,
        public bool $ready = true,
    ) {
    }

    public function __toString(): string
    {
        return $this->id;
    }
}
