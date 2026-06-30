<?php

declare(strict_types=1);

use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Support\TranslationResult;
use Minhyung\LaravelTranslator\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * A minimal stub driver tagging results with the given name. Shared across
 * feature tests so each test file is self-contained.
 */
function stubDriver(string $name, string $text = 'translated'): Driver
{
    return new class($name, $text) implements Driver
    {
        public function __construct(private string $name, private string $text)
        {
        }

        public function translate(string $text, string $targetLang, ?string $sourceLang = null, array $options = []): TranslationResult
        {
            return new TranslationResult(text: $this->text, targetLang: $targetLang, translator: $this->name);
        }

        public function translateBatch(array $texts, string $targetLang, ?string $sourceLang = null, array $options = []): array
        {
            return [];
        }
    };
}
