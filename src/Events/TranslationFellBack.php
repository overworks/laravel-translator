<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Events;

use Throwable;

/**
 * Dispatched by the fallback driver each time a child translator throws and the
 * chain moves on to the next one.
 */
final readonly class TranslationFellBack
{
    /**
     * @param  string  $translator  The child translator that failed.
     */
    public function __construct(
        public string $translator,
        public Throwable $exception,
    ) {
    }
}
