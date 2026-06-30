<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Drivers;

use Closure;
use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Support\TranslationResult;
use Throwable;

/**
 * Decorator driver that retries the wrapped driver on failure, with a linear
 * backoff between attempts. Useful for shrugging off transient provider errors
 * (timeouts, 429/5xx) without falling all the way over to another translator.
 */
class RetryingDriver implements Driver
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
