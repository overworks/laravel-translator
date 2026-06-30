<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Contracts;

use Minhyung\LaravelTranslator\Support\Language;
use Minhyung\LaravelTranslator\Support\LanguageDetection;
use Minhyung\LaravelTranslator\Support\TranslationResult;
use RuntimeException;

/**
 * Public translator contract — the type the manager returns and that is bound
 * for dependency injection. The concrete {@see \Minhyung\LaravelTranslator\Translator}
 * implements this by delegating to a {@see Driver}.
 */
interface Translator
{
    /**
     * Translate a single piece of text.
     *
     * @param  string       $text        The text to translate.
     * @param  string       $targetLang  Target language code (e.g. "ko", "en-US").
     * @param  string|null  $sourceLang  Source language code, or null to auto-detect.
     * @param  array<string, mixed>  $options  Driver-specific options (e.g. formality).
     */
    public function translate(
        string $text,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): TranslationResult;

    /**
     * Translate several pieces of text in a single call.
     *
     * The returned array preserves the order and keys of $texts.
     *
     * @param  array<array-key, string>  $texts
     * @param  array<string, mixed>  $options
     * @return array<array-key, TranslationResult>
     */
    public function translateBatch(
        array $texts,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): array;

    /**
     * Detect the language of $text.
     *
     * @throws RuntimeException  When the underlying driver cannot detect languages.
     */
    public function detect(string $text): LanguageDetection;

    /**
     * List the languages this translator supports.
     *
     * @return array<int, Language>
     *
     * @throws RuntimeException  When the underlying driver cannot list languages.
     */
    public function languages(): array;
}
