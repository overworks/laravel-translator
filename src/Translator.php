<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator;

use Illuminate\Contracts\Events\Dispatcher;
use Minhyung\LaravelTranslator\Contracts\DetectsLanguage;
use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Contracts\ListsLanguages;
use Minhyung\LaravelTranslator\Contracts\Translator as TranslatorContract;
use Minhyung\LaravelTranslator\Events\BatchTranslationCompleted;
use Minhyung\LaravelTranslator\Events\TranslationCompleted;
use Minhyung\LaravelTranslator\Events\TranslationFailed;
use Minhyung\LaravelTranslator\Support\LanguageDetection;
use Minhyung\LaravelTranslator\Support\TranslationResult;
use RuntimeException;
use Throwable;

/**
 * The public translator object returned by the manager.
 *
 * It wraps a {@see Driver} (the swappable provider implementation) and delegates
 * to it. Keeping this separate from the driver gives a stable public type, the
 * place lifecycle events are dispatched, and room to grow translator-level
 * conveniences without touching every driver.
 */
final class Translator implements TranslatorContract
{
    public function __construct(
        protected string $name,
        protected Driver $driver,
        protected ?Dispatcher $events = null,
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
     * Detect the language of $text.
     *
     * @throws RuntimeException  When the underlying driver cannot detect languages.
     */
    public function detect(string $text): LanguageDetection
    {
        if (! $this->driver instanceof DetectsLanguage) {
            throw new RuntimeException("The [{$this->name}] translator does not support language detection.");
        }

        return $this->driver->detect($text);
    }

    /**
     * List the languages this translator supports.
     *
     * @return array<int, \Minhyung\LaravelTranslator\Support\Language>
     *
     * @throws RuntimeException  When the underlying driver cannot list languages.
     */
    public function languages(): array
    {
        if (! $this->driver instanceof ListsLanguages) {
            throw new RuntimeException("The [{$this->name}] translator does not support listing languages.");
        }

        return $this->driver->languages();
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
        try {
            $result = $this->driver->translate($text, $targetLang, $sourceLang, $options);
        } catch (Throwable $e) {
            $this->events?->dispatch(
                new TranslationFailed($this->name, $e, [$text], $targetLang, $sourceLang, $options)
            );

            throw $e;
        }

        $this->events?->dispatch(
            new TranslationCompleted($this->name, $text, $result, $sourceLang, $options)
        );

        return $result;
    }

    public function translateBatch(
        array $texts,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): array {
        try {
            $results = $this->driver->translateBatch($texts, $targetLang, $sourceLang, $options);
        } catch (Throwable $e) {
            $this->events?->dispatch(
                new TranslationFailed($this->name, $e, $texts, $targetLang, $sourceLang, $options)
            );

            throw $e;
        }

        $this->events?->dispatch(
            new BatchTranslationCompleted($this->name, $texts, $results, $targetLang, $sourceLang, $options)
        );

        return $results;
    }
}
