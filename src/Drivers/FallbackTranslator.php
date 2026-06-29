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
 * Tries an ordered list of drivers, falling back to the next one whenever a
 * driver throws, so a single provider outage doesn't break translation.
 */
class FallbackTranslator implements Translator
{
    /**
     * @param  array<string, Translator>  $drivers  Ordered, keyed by driver name.
     */
    public function __construct(
        protected array $drivers,
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

        foreach ($this->drivers as $name => $driver) {
            try {
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
