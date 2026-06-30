<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Support;

use Stringable;

/**
 * Result of detecting the language of a piece of text.
 */
final readonly class LanguageDetection implements Stringable
{
    /**
     * @param  string  $language    Detected language code (e.g. "en").
     * @param  string  $translator  The translator that performed the detection.
     * @param  float|null  $confidence  Confidence in the 0–1 range, when provided.
     */
    public function __construct(
        public string $language,
        public string $translator,
        public ?float $confidence = null,
    ) {
    }

    public function __toString(): string
    {
        return $this->language;
    }
}
