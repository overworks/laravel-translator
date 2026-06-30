<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Events;

use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * Dispatched after a single text is translated successfully.
 */
final readonly class TranslationCompleted
{
    /**
     * @param  string  $translator  The translator name the request went through.
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $translator,
        public string $text,
        public TranslationResult $result,
        public ?string $sourceLang = null,
        public array $options = [],
    ) {
    }
}
