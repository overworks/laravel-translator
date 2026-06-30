<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Events\Dispatcher;
use Minhyung\LaravelTranslator\Contracts\DetectsLanguage;
use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Contracts\ListsLanguages;
use Minhyung\LaravelTranslator\Contracts\ManagesGlossary;
use Minhyung\LaravelTranslator\Contracts\Translator as TranslatorContract;
use Minhyung\LaravelTranslator\Events\BatchTranslationCompleted;
use Minhyung\LaravelTranslator\Events\TranslationCompleted;
use Minhyung\LaravelTranslator\Events\TranslationFailed;
use Minhyung\LaravelTranslator\Jobs\TranslateJob;
use Minhyung\LaravelTranslator\Support\Glossary;
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
    /**
     * @param  array<string, mixed>  $queueConfig  Defaults for queued translations (connection, queue, tries, backoff).
     */
    public function __construct(
        protected string $name,
        protected Driver $driver,
        protected ?Dispatcher $events = null,
        protected ?BusDispatcher $bus = null,
        protected array $queueConfig = [],
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
     * Translate one text into several target languages at once.
     *
     * @param  array<int, string>  $targetLangs
     * @param  array<string, mixed>  $options
     * @return array<string, TranslationResult>  Keyed by target language code.
     */
    public function translateInto(
        array $targetLangs,
        string $text,
        ?string $sourceLang = null,
        array $options = []
    ): array {
        $results = [];

        foreach ($targetLangs as $target) {
            $results[$target] = $this->translate($text, $target, $sourceLang, $options);
        }

        return $results;
    }

    /**
     * Queue a single translation to run in the background. Results are delivered
     * through the lifecycle events (TranslationCompleted / TranslationFailed),
     * which fire as the job runs on the worker.
     *
     * The job is dispatched onto the connection/queue from the `translator.queue`
     * config; the dispatched {@see TranslateJob} is returned.
     *
     * @param  array<string, mixed>  $options
     */
    public function queue(
        string $text,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): TranslateJob {
        return $this->dispatchJob(new TranslateJob($this->name, $text, $targetLang, $sourceLang, $options));
    }

    /**
     * Queue a batch translation to run in the background. Results are delivered
     * through the BatchTranslationCompleted / TranslationFailed events.
     *
     * @param  array<array-key, string>  $texts
     * @param  array<string, mixed>  $options
     */
    public function queueBatch(
        array $texts,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): TranslateJob {
        return $this->dispatchJob(new TranslateJob($this->name, $texts, $targetLang, $sourceLang, $options));
    }

    /**
     * Apply the configured queue defaults to a translation job and dispatch it.
     */
    protected function dispatchJob(TranslateJob $job): TranslateJob
    {
        if (! empty($this->queueConfig['tries'])) {
            $job->tries = (int) $this->queueConfig['tries'];
        }

        if (isset($this->queueConfig['backoff'])) {
            $job->backoff = $this->queueConfig['backoff'];
        }

        $job->onConnection($this->queueConfig['connection'] ?? null)
            ->onQueue($this->queueConfig['queue'] ?? null);

        $this->bus?->dispatch($job);

        return $job;
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
     * Create a glossary (DeepL) / terminology (Amazon) from a source-term →
     * target-term map. Reference it later via the "glossary" translate option.
     *
     * @param  array<string, string>  $entries  Source term => target term.
     * @param  array<string, mixed>  $options
     *
     * @throws RuntimeException  When the underlying driver cannot manage glossaries.
     */
    public function createGlossary(
        string $name,
        string $sourceLang,
        string $targetLang,
        array $entries,
        array $options = []
    ): Glossary {
        return $this->glossaryDriver()->createGlossary($name, $sourceLang, $targetLang, $entries, $options);
    }

    /**
     * List the glossaries registered with this translator.
     *
     * @return array<int, Glossary>
     *
     * @throws RuntimeException  When the underlying driver cannot manage glossaries.
     */
    public function glossaries(): array
    {
        return $this->glossaryDriver()->glossaries();
    }

    /**
     * Fetch a single glossary's metadata.
     *
     * @throws RuntimeException  When the underlying driver cannot manage glossaries.
     */
    public function glossary(string $id): Glossary
    {
        return $this->glossaryDriver()->glossary($id);
    }

    /**
     * Fetch a glossary's entries as a source-term → target-term map.
     *
     * @return array<string, string>
     *
     * @throws RuntimeException  When the underlying driver cannot manage glossaries.
     */
    public function glossaryEntries(string $id): array
    {
        return $this->glossaryDriver()->glossaryEntries($id);
    }

    /**
     * Delete a glossary.
     *
     * @throws RuntimeException  When the underlying driver cannot manage glossaries.
     */
    public function deleteGlossary(string $id): void
    {
        $this->glossaryDriver()->deleteGlossary($id);
    }

    /**
     * The driver as a glossary manager, or a clear error when unsupported.
     */
    protected function glossaryDriver(): ManagesGlossary
    {
        if (! $this->driver instanceof ManagesGlossary) {
            throw new RuntimeException("The [{$this->name}] translator does not support glossaries.");
        }

        return $this->driver;
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
        return $this->runTranslate($text, $targetLang, $sourceLang, $options, dispatchFailure: true);
    }

    public function translateBatch(
        array $texts,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): array {
        return $this->runTranslateBatch($texts, $targetLang, $sourceLang, $options, dispatchFailure: true);
    }

    /**
     * Like translate(), but does not dispatch TranslationFailed on error (the
     * success event still fires). A queued {@see TranslateJob} uses this so the
     * failure event is emitted once, after the job exhausts its retries, instead
     * of on every failed attempt.
     *
     * @param  array<string, mixed>  $options
     */
    public function translateQuietly(
        string $text,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): TranslationResult {
        return $this->runTranslate($text, $targetLang, $sourceLang, $options, dispatchFailure: false);
    }

    /**
     * Batch counterpart to {@see translateQuietly()}.
     *
     * @param  array<array-key, string>  $texts
     * @param  array<string, mixed>  $options
     * @return array<array-key, TranslationResult>
     */
    public function translateBatchQuietly(
        array $texts,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): array {
        return $this->runTranslateBatch($texts, $targetLang, $sourceLang, $options, dispatchFailure: false);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function runTranslate(
        string $text,
        string $targetLang,
        ?string $sourceLang,
        array $options,
        bool $dispatchFailure
    ): TranslationResult {
        try {
            $result = $this->driver->translate($text, $targetLang, $sourceLang, $options);
        } catch (Throwable $e) {
            if ($dispatchFailure) {
                $this->events?->dispatch(
                    new TranslationFailed($this->name, $e, [$text], $targetLang, $sourceLang, $options)
                );
            }

            throw $e;
        }

        $this->events?->dispatch(
            new TranslationCompleted($this->name, $text, $result, $sourceLang, $options)
        );

        return $result;
    }

    /**
     * @param  array<array-key, string>  $texts
     * @param  array<string, mixed>  $options
     * @return array<array-key, TranslationResult>
     */
    protected function runTranslateBatch(
        array $texts,
        string $targetLang,
        ?string $sourceLang,
        array $options,
        bool $dispatchFailure
    ): array {
        try {
            $results = $this->driver->translateBatch($texts, $targetLang, $sourceLang, $options);
        } catch (Throwable $e) {
            if ($dispatchFailure) {
                $this->events?->dispatch(
                    new TranslationFailed($this->name, $e, $texts, $targetLang, $sourceLang, $options)
                );
            }

            throw $e;
        }

        $this->events?->dispatch(
            new BatchTranslationCompleted($this->name, $texts, $results, $targetLang, $sourceLang, $options)
        );

        return $results;
    }
}
