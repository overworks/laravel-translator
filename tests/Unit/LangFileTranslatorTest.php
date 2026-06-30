<?php

declare(strict_types=1);

use Minhyung\LaravelTranslator\Contracts\Translator as TranslatorContract;
use Minhyung\LaravelTranslator\Localization\LangFileTranslator;
use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * A translator that wraps each input in brackets, preserving any tokens within.
 */
function bracketTranslator(): TranslatorContract
{
    return new class implements TranslatorContract
    {
        public function translate(string $text, string $targetLang, ?string $sourceLang = null, array $options = []): TranslationResult
        {
            return new TranslationResult('[' . $text . ']', $targetLang, 'wrap');
        }

        public function translateBatch(array $texts, string $targetLang, ?string $sourceLang = null, array $options = []): array
        {
            return array_map(fn (string $t) => new TranslationResult('[' . $t . ']', $targetLang, 'wrap'), $texts);
        }
    };
}

function langService(): LangFileTranslator
{
    return new LangFileTranslator(bracketTranslator());
}

it('preserves :placeholders', function () {
    $out = langService()->translateLines(['greeting' => 'Welcome :name'], 'ko');

    expect($out['greeting'])->toBe('[Welcome :name]');
});

it('preserves multiple placeholders', function () {
    $out = langService()->translateLines(['x' => ':a and :b'], 'ko');

    expect($out['x'])->toBe('[:a and :b]');
});

it('translates each pluralization segment and keeps the separator', function () {
    $out = langService()->translateLines(['apples' => 'apple|apples'], 'ko');

    expect($out['apples'])->toBe('[apple]|[apples]');
});

it('keeps choice prefixes and placeholders in pluralized lines', function () {
    $out = langService()->translateLines(
        ['count' => '{1} :count apple|[2,*] :count apples'],
        'ko',
    );

    expect($out['count'])->toBe('{1} [:count apple]|[2,*] [:count apples]');
});

it('leaves empty segments untranslated', function () {
    $out = langService()->translateLines(['x' => 'a|'], 'ko');

    expect($out['x'])->toBe('[a]|');
});

it('does not treat a non-placeholder colon as a placeholder', function () {
    $out = langService()->translateLines(['ratio' => 'odds 3:5'], 'ko');

    expect($out['ratio'])->toBe('[odds 3:5]');
});
