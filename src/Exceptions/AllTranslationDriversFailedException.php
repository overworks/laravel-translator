<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when every driver in a fallback chain has failed.
 */
class AllTranslationDriversFailedException extends RuntimeException
{
    /**
     * @param  array<string, Throwable>  $errors  Keyed by driver name, in attempt order.
     */
    public function __construct(protected array $errors)
    {
        $summary = implode('; ', array_map(
            fn (string $name, Throwable $e): string => "{$name}: {$e->getMessage()}",
            array_keys($errors),
            array_values($errors),
        ));

        $previous = $errors === [] ? null : array_values($errors)[count($errors) - 1];

        parent::__construct(
            $summary === ''
                ? 'All translation drivers failed.'
                : "All translation drivers failed ({$summary}).",
            0,
            $previous,
        );
    }

    /**
     * The underlying failure from each driver, keyed by driver name.
     *
     * @return array<string, Throwable>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
