<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Events;

/**
 * Dispatched after a batch of texts is translated successfully.
 */
final readonly class BatchTranslationCompleted
{
    /**
     * @param  string  $translator  The translator name the request went through.
     * @param  array<array-key, string>  $texts    The original inputs.
     * @param  array<array-key, \Minhyung\LaravelTranslator\Support\TranslationResult>  $results
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $translator,
        public array $texts,
        public array $results,
        public string $targetLang,
        public ?string $sourceLang = null,
        public array $options = [],
    ) {
    }
}
