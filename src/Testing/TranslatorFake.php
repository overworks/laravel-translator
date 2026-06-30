<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Testing;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Minhyung\LaravelTranslator\Contracts\Translator as TranslatorContract;
use Minhyung\LaravelTranslator\Support\TranslationResult;
use Minhyung\LaravelTranslator\Translator;
use Minhyung\LaravelTranslator\TranslatorManager;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Drop-in fake for {@see TranslatorManager}, installed by Translator::fake().
 *
 * It records every translation and returns canned results (echoing the source
 * text by default, or using a map/closure), so application code under test
 * never calls a real provider. Offers assertions about what was translated.
 */
class TranslatorFake extends TranslatorManager
{
    /**
     * @var list<array{translator: string, text: string, target: string, source: string|null, options: array<string, mixed>}>
     */
    protected array $translated = [];

    /**
     * @var array<string, string>|callable|null
     */
    protected $resolver;

    /**
     * @param  array<string, string>|callable|null  $resolver  Map of source => translation, or a callback.
     */
    public function __construct(Container $container, array|callable|null $resolver = null)
    {
        parent::__construct($container);

        $this->resolver = $resolver;
    }

    public function via(?string $name = null): TranslatorContract
    {
        $name ??= $this->getDefaultTranslator();

        return $this->wrap($name);
    }

    public function build(array $config, ?string $name = null): Translator
    {
        return $this->wrap($name ?? ($config['driver'] ?? 'fake'));
    }

    /**
     * Wrap a recording FakeDriver in a real Translator, keeping the event
     * dispatcher so lifecycle events still fire (just like the real manager).
     */
    protected function wrap(string $name): Translator
    {
        return new Translator($name, new FakeDriver($name, $this), $this->container->make(Dispatcher::class));
    }

    /**
     * Record a translation and return the canned result. Called by FakeDriver.
     *
     * @param  array<string, mixed>  $options
     */
    public function recordTranslation(
        string $translator,
        string $text,
        string $target,
        ?string $source,
        array $options
    ): TranslationResult {
        $this->translated[] = [
            'translator' => $translator,
            'text' => $text,
            'target' => $target,
            'source' => $source,
            'options' => $options,
        ];

        return new TranslationResult(
            text: $this->resolve($text, $target, $source),
            targetLang: $target,
            translator: $translator,
            detectedSourceLang: $source,
        );
    }

    /**
     * All recorded translations.
     *
     * @return list<array{translator: string, text: string, target: string, source: string|null, options: array<string, mixed>}>
     */
    public function translations(): array
    {
        return $this->translated;
    }

    /**
     * Assert a translation of $text happened (optionally matching a predicate).
     *
     * @param  string|callable(array{translator: string, text: string, target: string, source: string|null, options: array<string, mixed>}): bool  $text
     */
    public function assertTranslated(string|callable $text, ?callable $callback = null): void
    {
        if (is_callable($text)) {
            PHPUnit::assertTrue(
                $this->records()->contains(fn (array $record): bool => $text($record)),
                'Expected a translation matching the given callback, but none was recorded.',
            );

            return;
        }

        $matches = $this->recordsFor($text);

        PHPUnit::assertTrue(
            $matches->isNotEmpty(),
            "Expected text [{$text}] to be translated, but it was not.",
        );

        if ($callback !== null) {
            PHPUnit::assertTrue(
                $matches->contains(fn (array $record): bool => $callback($record)),
                "A translation of [{$text}] was recorded, but none matched the given callback.",
            );
        }
    }

    public function assertNotTranslated(string $text): void
    {
        PHPUnit::assertTrue(
            $this->recordsFor($text)->isEmpty(),
            "Expected text [{$text}] not to be translated, but it was.",
        );
    }

    public function assertNothingTranslated(): void
    {
        PHPUnit::assertSame(
            [],
            $this->translated,
            'Expected no translations, but some were recorded.',
        );
    }

    public function assertTranslatedTimes(string $text, int $times): void
    {
        $count = $this->recordsFor($text)->count();

        PHPUnit::assertSame(
            $times,
            $count,
            "Expected text [{$text}] to be translated {$times} time(s), but it was translated {$count} time(s).",
        );
    }

    public function assertTranslatedCount(int $count): void
    {
        PHPUnit::assertCount($count, $this->translated);
    }

    /**
     * @return Collection<int, array{translator: string, text: string, target: string, source: string|null, options: array<string, mixed>}>
     */
    protected function records(): Collection
    {
        return new Collection($this->translated);
    }

    /**
     * @return Collection<int, array{translator: string, text: string, target: string, source: string|null, options: array<string, mixed>}>
     */
    protected function recordsFor(string $text): Collection
    {
        return $this->records()->where('text', $text)->values();
    }

    protected function resolve(string $text, string $target, ?string $source): string
    {
        if (is_array($this->resolver)) {
            return $this->resolver[$text] ?? $text;
        }

        if (is_callable($this->resolver)) {
            return (string) (($this->resolver)($text, $target, $source) ?? $text);
        }

        return $text;
    }
}
