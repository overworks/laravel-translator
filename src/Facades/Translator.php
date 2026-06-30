<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Facades;

use Illuminate\Support\Facades\Facade;
use Minhyung\LaravelTranslator\Support\TranslationResult;
use Minhyung\LaravelTranslator\TranslatorManager;

/**
 * @method static \Minhyung\LaravelTranslator\Contracts\Translator via(string|null $name = null)
 * @method static \Minhyung\LaravelTranslator\Translator build(array $config, string|null $name = null)
 * @method static TranslationResult translate(string $text, string $targetLang, string|null $sourceLang = null, array $options = [])
 * @method static array<array-key, TranslationResult> translateBatch(array $texts, string $targetLang, string|null $sourceLang = null, array $options = [])
 *
 * @see \Minhyung\LaravelTranslator\TranslatorManager
 */
class Translator extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TranslatorManager::class;
    }
}
