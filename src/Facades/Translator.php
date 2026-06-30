<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Facades;

use Illuminate\Support\Facades\Facade;
use Minhyung\LaravelTranslator\Support\TranslationResult;
use Minhyung\LaravelTranslator\Testing\TranslatorFake;
use Minhyung\LaravelTranslator\TranslatorManager;

/**
 * @method static \Minhyung\LaravelTranslator\Contracts\Translator via(string|null $name = null)
 * @method static \Minhyung\LaravelTranslator\Translator build(array $config, string|null $name = null)
 * @method static TranslationResult translate(string $text, string $targetLang, string|null $sourceLang = null, array $options = [])
 * @method static array<array-key, TranslationResult> translateBatch(array $texts, string $targetLang, string|null $sourceLang = null, array $options = [])
 * @method static array<string, TranslationResult> translateInto(array $targetLangs, string $text, string|null $sourceLang = null, array $options = [])
 * @method static \Minhyung\LaravelTranslator\Jobs\TranslateJob queue(string $text, string $targetLang, string|null $sourceLang = null, array $options = [])
 * @method static \Minhyung\LaravelTranslator\Jobs\TranslateJob queueBatch(array $texts, string $targetLang, string|null $sourceLang = null, array $options = [])
 * @method static \Minhyung\LaravelTranslator\Support\LanguageDetection detect(string $text)
 * @method static array<int, \Minhyung\LaravelTranslator\Support\Language> languages()
 *
 * @see \Minhyung\LaravelTranslator\TranslatorManager
 */
class Translator extends Facade
{
    /**
     * Replace the translator with a fake that records translations instead of
     * calling real providers, and returns it for assertions.
     *
     * @param  array<string, string>|callable|null  $resolver  Map of source => translation, or a callback.
     */
    public static function fake(array|callable|null $resolver = null): TranslatorFake
    {
        static::swap($fake = new TranslatorFake(static::getFacadeApplication(), $resolver));

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return TranslatorManager::class;
    }
}
