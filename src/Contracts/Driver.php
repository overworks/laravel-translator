<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Contracts;

use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * Low-level contract implemented by every translation driver (provider
 * adapters such as DeepL/OpenAI/Claude/Google, and composite drivers such as
 * caching and fallback).
 *
 * A {@see Translator} is the public object that wraps a driver and is what the
 * manager hands back; drivers are the swappable implementation behind it.
 */
interface Driver
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
}
