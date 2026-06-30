<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Drivers;

use Illuminate\Contracts\Cache\Repository;
use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * Decorator driver that caches translation results from any inner driver using
 * a Laravel cache repository, avoiding repeated API calls (and billing) for
 * identical inputs.
 */
class CachingDriver implements Driver
{
    /**
     * @param  Driver      $inner       The wrapped driver that performs real translations.
     * @param  Repository  $cache       Laravel cache repository to store results in.
     * @param  string      $translator  Name of the translator (used in cache keys).
     * @param  int|null    $ttl         Cache lifetime in seconds, or null to cache forever.
     * @param  string      $prefix      Cache key prefix.
     */
    public function __construct(
        protected Driver $inner,
        protected Repository $cache,
        protected string $translator,
        protected ?int $ttl = null,
        protected string $prefix = 'translator',
    ) {
    }

    public function translate(
        string $text,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): TranslationResult {
        $key = $this->cacheKey($text, $targetLang, $sourceLang, $options);

        if (($cached = $this->cache->get($key)) instanceof TranslationResult) {
            return $cached;
        }

        $result = $this->inner->translate($text, $targetLang, $sourceLang, $options);

        $this->put($key, $result);

        return $result;
    }

    public function translateBatch(
        array $texts,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): array {
        if ($texts === []) {
            return [];
        }

        $results = [];
        $misses = [];

        // First pass: serve cache hits, collect misses (preserving keys).
        foreach ($texts as $index => $text) {
            $cached = $this->cache->get($this->cacheKey($text, $targetLang, $sourceLang, $options));

            if ($cached instanceof TranslationResult) {
                $results[$index] = $cached;
            } else {
                $misses[$index] = $text;
            }
        }

        // Second pass: translate only the misses in a single batch call, then cache each.
        if ($misses !== []) {
            $translated = $this->inner->translateBatch($misses, $targetLang, $sourceLang, $options);

            foreach ($translated as $index => $result) {
                $this->put(
                    $this->cacheKey($misses[$index], $targetLang, $sourceLang, $options),
                    $result
                );
                $results[$index] = $result;
            }
        }

        // Restore the original ordering of $texts.
        return array_replace($texts, $results);
    }

    protected function put(string $key, TranslationResult $result): void
    {
        if ($this->ttl === null) {
            $this->cache->forever($key, $result);

            return;
        }

        $this->cache->put($key, $result, $this->ttl);
    }

    protected function cacheKey(string $text, string $targetLang, ?string $sourceLang, array $options): string
    {
        ksort($options);

        $signature = implode('|', [
            $sourceLang ?? 'auto',
            $targetLang,
            serialize($options),
            $text,
        ]);

        return sprintf('%s:%s:%s', $this->prefix, $this->translator, sha1($signature));
    }
}
