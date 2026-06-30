<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Testing;

use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * Driver used by {@see TranslatorFake}: records every translation on the fake
 * and returns the fake's canned result instead of calling a real provider.
 */
class FakeDriver implements Driver
{
    public function __construct(
        protected string $name,
        protected TranslatorFake $fake,
    ) {
    }

    public function translate(
        string $text,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): TranslationResult {
        return $this->fake->recordTranslation($this->name, $text, $targetLang, $sourceLang, $options);
    }

    public function translateBatch(
        array $texts,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): array {
        return array_map(
            fn (string $text): TranslationResult => $this->fake->recordTranslation(
                $this->name,
                $text,
                $targetLang,
                $sourceLang,
                $options,
            ),
            $texts,
        );
    }
}
