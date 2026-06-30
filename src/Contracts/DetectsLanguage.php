<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Contracts;

use Minhyung\LaravelTranslator\Support\LanguageDetection;

/**
 * Optional capability implemented by drivers that can detect a text's language.
 *
 * A {@see \Minhyung\LaravelTranslator\Translator} exposes detect() and throws a
 * clear error when its driver does not implement this.
 */
interface DetectsLanguage
{
    public function detect(string $text): LanguageDetection;
}
