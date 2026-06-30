<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Events;

use Throwable;

/**
 * Dispatched when a translation request ultimately fails (after any failover).
 */
final readonly class TranslationFailed
{
    /**
     * @param  string  $translator  The translator name the request went through.
     * @param  array<array-key, string>  $texts  The inputs (a single translation has one entry).
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $translator,
        public Throwable $exception,
        public array $texts,
        public string $targetLang,
        public ?string $sourceLang = null,
        public array $options = [],
    ) {
    }
}
