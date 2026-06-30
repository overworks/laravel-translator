<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Contracts;

use Minhyung\LaravelTranslator\Support\Language;

/**
 * Optional capability implemented by drivers that can list the languages they
 * support.
 *
 * A {@see \Minhyung\LaravelTranslator\Translator} exposes languages() and throws
 * a clear error when its driver does not implement this.
 */
interface ListsLanguages
{
    /**
     * @return array<int, Language>
     */
    public function languages(): array;
}
