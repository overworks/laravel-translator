<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Localization;

use Minhyung\LaravelTranslator\Contracts\Translator;

/**
 * Translates Laravel localization lines while preserving their structure:
 *
 * - `:placeholder` tokens are shielded from the translator and restored after.
 * - Pluralization is split on `|` and each segment translated separately, with
 *   its `{n}` / `[a,b]` choice prefix kept intact.
 *
 * All translatable pieces across the given lines are sent in a single batch.
 */
class LangFileTranslator
{
    public function __construct(
        protected Translator $translator,
    ) {
    }

    /**
     * Translate a flat map of [key => source line] into [key => translated line].
     *
     * @param  array<array-key, string>  $lines
     * @return array<array-key, string>
     */
    public function translateLines(array $lines, string $targetLang, ?string $sourceLang = null): array
    {
        $units = [];   // unit id => protected text to translate
        $plan = [];    // key => list of segments
        $next = 0;

        foreach ($lines as $key => $line) {
            $segments = [];

            foreach (explode('|', $line) as $segment) {
                [$prefix, $body] = $this->splitChoicePrefix($segment);
                [$protected, $map] = $this->protect($body);

                $id = 'u' . $next++;
                $units[$id] = $protected;

                $segments[] = ['prefix' => $prefix, 'id' => $id, 'map' => $map];
            }

            $plan[$key] = $segments;
        }

        $translatable = array_filter($units, fn (string $text): bool => trim($text) !== '');
        $translated = $translatable === []
            ? []
            : array_map(
                fn ($result): string => $result->text,
                $this->translator->translateBatch($translatable, $targetLang, $sourceLang),
            );

        $out = [];

        foreach ($plan as $key => $segments) {
            $parts = [];

            foreach ($segments as $segment) {
                $body = $translated[$segment['id']] ?? $units[$segment['id']];
                $parts[] = $segment['prefix'] . $this->restore($body, $segment['map']);
            }

            $out[$key] = implode('|', $parts);
        }

        return $out;
    }

    /**
     * Split a leading pluralization choice prefix ("{0} ", "[2,*] ") off a segment.
     *
     * @return array{0: string, 1: string}
     */
    protected function splitChoicePrefix(string $segment): array
    {
        if (preg_match('/^\s*(?:\{[^}]*\}|\[[^\]]*\])\s*/', $segment, $m)) {
            return [$m[0], substr($segment, strlen($m[0]))];
        }

        return ['', $segment];
    }

    /**
     * Replace `:placeholder` tokens with sentinels translators leave untouched.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    protected function protect(string $text): array
    {
        $map = [];
        $n = 0;

        $protected = preg_replace_callback(
            '/:[A-Za-z][A-Za-z0-9_]*/',
            function (array $m) use (&$map, &$n): string {
                $token = "\u{27E6}{$n}\u{27E7}"; // ⟦n⟧
                $map[$token] = $m[0];
                $n++;

                return $token;
            },
            $text,
        );

        return [$protected, $map];
    }

    /**
     * @param  array<string, string>  $map
     */
    protected function restore(string $text, array $map): string
    {
        return $map === [] ? $text : strtr($text, $map);
    }
}
