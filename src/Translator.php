<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator;

use Illuminate\Contracts\Events\Dispatcher;
use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Contracts\Translator as TranslatorContract;
use Minhyung\LaravelTranslator\Events\BatchTranslationCompleted;
use Minhyung\LaravelTranslator\Events\TranslationCompleted;
use Minhyung\LaravelTranslator\Events\TranslationFailed;
use Minhyung\LaravelTranslator\Support\TranslationResult;
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
