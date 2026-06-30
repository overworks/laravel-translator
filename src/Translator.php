<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator;

use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Contracts\Translator as TranslatorContract;
use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * The public translator object returned by the manager.
 *
 * It wraps a {@see Driver} (the swappable provider implementation) and delegates
 * to it. Keeping this separate from the driver gives a stable public type and a
 * place to grow translator-level conveniences without touching every driver.
 */
final class Translator implements TranslatorContract
{
    public function __construct(
        protected string $name,
        protected Driver $driver,
    ) {
    }

    /**
     * The configured name of this translator (e.g. "deepl", "claude").
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * The underlying driver this translator delegates to.
     */
    public function driver(): Driver
    {
        return $this->driver;
    }

    public function translate(
        string $text,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): TranslationResult {
        return $this->driver->translate($text, $targetLang, $sourceLang, $options);
    }

    public function translateBatch(
        array $texts,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): array {
        return $this->driver->translateBatch($texts, $targetLang, $sourceLang, $options);
    }
}
