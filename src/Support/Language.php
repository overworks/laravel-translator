<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Support;

use Stringable;

/**
 * A language supported by a translator.
 */
final readonly class Language implements Stringable
{
    /**
     * @param  string  $code    Language code (e.g. "en", "en-US").
     * @param  string|null  $name  English display name, when the provider gives one.
     * @param  bool  $source   Whether it can be used as a source language.
     * @param  bool  $target   Whether it can be used as a target language.
     */
    public function __construct(
        public string $code,
        public ?string $name = null,
        public bool $source = true,
        public bool $target = true,
    ) {
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
