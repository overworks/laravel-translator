<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Drivers;

use Closure;
use Minhyung\LaravelTranslator\Contracts\Translator;
use Minhyung\LaravelTranslator\Exceptions\AllTranslationDriversFailedException;
use Minhyung\LaravelTranslator\Support\TranslationResult;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tries an ordered list of translators, falling back to the next one whenever
 * one throws, so a single provider outage doesn't break translation.
 *
 * Translators are passed as lazy factories and resolved only when reached, so a
 * later one that fails to even construct (e.g. missing credentials) never
 * prevents an earlier, healthy translator from running.
 */
class FallbackDriver implements Translator
{
    /**
     * Successfully constructed translators, memoized by name.
     *
     * @var array<string, Translator>
     */
    protected array $resolved = [];

    /**
     * @param  array<string, callable(): Translator>  $factories  Ordered, keyed by translator name.
     */
    public function __construct(
        protected array $factories,
        protected ?LoggerInterface $logger = null,
    ) {
    }

    public function translate(
        string $text,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): TranslationResult {
        return $this->attempt(
            fn (Translator $driver): TranslationResult => $driver->translate($text, $targetLang, $sourceLang, $options)
        );
    }

    public function translateBatch(
        array $texts,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): array {
        return $this->attempt(
            fn (Translator $driver): array => $driver->translateBatch($texts, $targetLang, $sourceLang, $options)
        );
    }

    /**
     * Run $call against each driver in turn, returning the first success.
     *
     * @param  Closure(Translator): mixed  $call
     *
     * @throws AllTranslationDriversFailedException  When every driver fails.
     */
    protected function attempt(Closure $call): mixed
    {
        $errors = [];

        foreach ($this->factories as $name => $factory) {
            try {
                // Construct lazily (memoizing successes); a driver that cannot
                // even be built is treated like any other failure.
                $driver = $this->resolved[$name] ??= $factory();

                return $call($driver);
            } catch (Throwable $e) {
                $errors[$name] = $e;

                $this->logger?->warning(
                    "Translation driver [{$name}] failed, falling back.",
                    ['exception' => $e]
                );
            }
        }

        throw new AllTranslationDriversFailedException($errors);
    }
}
