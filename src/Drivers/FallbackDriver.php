<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Drivers;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Minhyung\LaravelTranslator\Contracts\DetectsLanguage;
use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Contracts\ListsLanguages;
use Minhyung\LaravelTranslator\Events\TranslationFellBack;
use Minhyung\LaravelTranslator\Exceptions\AllTranslationDriversFailedException;
use Minhyung\LaravelTranslator\Support\LanguageDetection;
use Minhyung\LaravelTranslator\Support\TranslationResult;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Tries an ordered list of drivers, falling back to the next one whenever one
 * throws, so a single provider outage doesn't break translation.
 *
 * Drivers are passed as lazy factories and resolved only when reached, so a
 * later one that fails to even construct (e.g. missing credentials) never
 * prevents an earlier, healthy driver from running.
 */
class FallbackDriver implements Driver, DetectsLanguage, ListsLanguages
{
    /**
     * Successfully constructed drivers, memoized by name.
     *
     * @var array<string, Driver>
     */
    protected array $resolved = [];

    /**
     * @param  array<string, callable(): Driver>  $factories  Ordered, keyed by translator name.
     */
    public function __construct(
        protected array $factories,
        protected ?LoggerInterface $logger = null,
        protected ?Dispatcher $events = null,
    ) {
    }

    public function translate(
        string $text,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): TranslationResult {
        return $this->attempt(
            fn (Driver $driver): TranslationResult => $driver->translate($text, $targetLang, $sourceLang, $options)
        );
    }

    public function translateBatch(
        array $texts,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): array {
        return $this->attempt(
            fn (Driver $driver): array => $driver->translateBatch($texts, $targetLang, $sourceLang, $options)
        );
    }

    public function detect(string $text): LanguageDetection
    {
        return $this->attempt(fn (Driver $driver): LanguageDetection => $driver instanceof DetectsLanguage
            ? $driver->detect($text)
            : throw new RuntimeException('Driver does not support language detection.'));
    }

    public function languages(): array
    {
        return $this->attempt(fn (Driver $driver): array => $driver instanceof ListsLanguages
            ? $driver->languages()
            : throw new RuntimeException('Driver does not support listing languages.'));
    }

    /**
     * Run $call against each driver in turn, returning the first success.
     *
     * @param  Closure(Driver): mixed  $call
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

                $this->events?->dispatch(new TranslationFellBack($name, $e));
            }
        }

        throw new AllTranslationDriversFailedException($errors);
    }
}
