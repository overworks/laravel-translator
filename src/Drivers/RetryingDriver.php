<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Drivers;

use Closure;
use Minhyung\LaravelTranslator\Contracts\DetectsLanguage;
use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Contracts\ListsLanguages;
use Minhyung\LaravelTranslator\Contracts\ManagesGlossary;
use Minhyung\LaravelTranslator\Support\Glossary;
use Minhyung\LaravelTranslator\Support\LanguageDetection;
use Minhyung\LaravelTranslator\Support\TranslationResult;
use RuntimeException;
use Throwable;

/**
 * Decorator driver that retries the wrapped driver on failure, with a linear
 * backoff between attempts. Useful for shrugging off transient provider errors
 * (timeouts, 429/5xx) without falling all the way over to another translator.
 *
 * Forwards {@see DetectsLanguage}, {@see ListsLanguages} and
 * {@see ManagesGlossary} transparently so those keep working (and are retried)
 * through the retry layer.
 */
class RetryingDriver implements Driver, DetectsLanguage, ListsLanguages, ManagesGlossary
{
    /**
     * @param  Driver  $inner    The wrapped driver.
     * @param  int  $times       Maximum attempts (including the first).
     * @param  int  $sleepMs     Base backoff in milliseconds (multiplied by the attempt number).
     */
    public function __construct(
        protected Driver $inner,
        protected int $times = 3,
        protected int $sleepMs = 200,
    ) {
    }

    public function translate(
        string $text,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): TranslationResult {
        return $this->attempt(
            fn (): TranslationResult => $this->inner->translate($text, $targetLang, $sourceLang, $options)
        );
    }

    public function translateBatch(
        array $texts,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): array {
        return $this->attempt(
            fn (): array => $this->inner->translateBatch($texts, $targetLang, $sourceLang, $options)
        );
    }

    public function detect(string $text): LanguageDetection
    {
        if (! $this->inner instanceof DetectsLanguage) {
            throw new RuntimeException('The wrapped driver does not support language detection.');
        }

        return $this->attempt(fn (): LanguageDetection => $this->inner->detect($text));
    }

    public function languages(): array
    {
        if (! $this->inner instanceof ListsLanguages) {
            throw new RuntimeException('The wrapped driver does not support listing languages.');
        }

        return $this->attempt(fn (): array => $this->inner->languages());
    }

    public function createGlossary(
        string $name,
        string $sourceLang,
        string $targetLang,
        array $entries,
        array $options = []
    ): Glossary {
        $driver = $this->glossaryDriver();

        return $this->attempt(
            fn (): Glossary => $driver->createGlossary($name, $sourceLang, $targetLang, $entries, $options)
        );
    }

    public function glossaries(): array
    {
        $driver = $this->glossaryDriver();

        return $this->attempt(fn (): array => $driver->glossaries());
    }

    public function glossary(string $id): Glossary
    {
        $driver = $this->glossaryDriver();

        return $this->attempt(fn (): Glossary => $driver->glossary($id));
    }

    public function glossaryEntries(string $id): array
    {
        $driver = $this->glossaryDriver();

        return $this->attempt(fn (): array => $driver->glossaryEntries($id));
    }

    public function deleteGlossary(string $id): void
    {
        $driver = $this->glossaryDriver();

        $this->attempt(function () use ($driver): void {
            $driver->deleteGlossary($id);
        });
    }

    protected function glossaryDriver(): ManagesGlossary
    {
        if (! $this->inner instanceof ManagesGlossary) {
            throw new RuntimeException('The wrapped driver does not support glossaries.');
        }

        return $this->inner;
    }

    /**
     * Run $call, retrying on any throwable up to $times attempts.
     *
     * @param  Closure(): mixed  $call
     */
    protected function attempt(Closure $call): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $call();
            } catch (Throwable $e) {
                if ($attempt >= $this->times) {
                    throw $e;
                }

                if ($this->sleepMs > 0) {
                    usleep($this->sleepMs * $attempt * 1000);
                }
            }
        }
    }
}
