<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator;

use DeepL\DeepLClient;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Manager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Minhyung\LaravelTranslator\Contracts\Translator;
use Minhyung\LaravelTranslator\Drivers\CachingTranslator;
use Minhyung\LaravelTranslator\Drivers\DeeplTranslator;
use Minhyung\LaravelTranslator\Drivers\FallbackTranslator;
use Minhyung\LaravelTranslator\Drivers\GoogleTranslator;
use Minhyung\LaravelTranslator\Drivers\LlmTranslator;
use Minhyung\LaravelTranslator\Drivers\OpenAiCompatibleTranslator;
use OpenAI\Factory as OpenAiFactory;
use Psr\Log\LoggerInterface;

/**
 * Resolves translation drivers and (optionally) wraps them with caching.
 *
 * @mixin \Minhyung\LaravelTranslator\Contracts\Translator
 */
class TranslatorManager extends Manager
{
    public function getDefaultDriver()
    {
        return $this->config()->get('translator.default', 'deepl');
    }

    protected function createDeeplDriver(): Translator
    {
        $key = $this->config()->get('translator.drivers.deepl.key');

        if (empty($key)) {
            throw new InvalidArgumentException(
                'The DeepL driver requires an auth key. Set DEEPL_AUTH_KEY or translator.drivers.deepl.key.'
            );
        }

        return new DeeplTranslator(new DeepLClient($key));
    }

    protected function createGoogleDriver(): Translator
    {
        $key = $this->config()->get('translator.drivers.google.key');

        if (empty($key)) {
            throw new InvalidArgumentException(
                'The Google driver requires an API key. Set GOOGLE_TRANSLATE_KEY or translator.drivers.google.key.'
            );
        }

        return new GoogleTranslator($this->container->make(HttpFactory::class), $key);
    }

    /**
     * Build an LLM-backed driver (via Prism) whose name is the Prism provider —
     * e.g. "openai", "anthropic", "gemini". The Prism provider defaults to the
     * name and may be overridden with a "provider" config key.
     */
    protected function buildLlmDriver(string $name): Translator
    {
        $config = $this->config()->get("translator.drivers.{$name}", []);

        $model = $config['model'] ?? null;

        if (! is_array($config) || empty($model)) {
            throw new InvalidArgumentException(
                "The [{$name}] driver requires a model. Set translator.drivers.{$name}.model."
            );
        }

        return new LlmTranslator(
            $config['provider'] ?? $name,
            $model,
            $config['options'] ?? [],
            $name,
        );
    }

    /**
     * Build a driver that talks to an arbitrary OpenAI-compatible chat
     * completions endpoint directly (no Prism). A drivers entry opts in by
     * setting a "base_uri"; "key" and optional "headers" configure the client.
     */
    protected function buildOpenAiCompatibleDriver(string $name): Translator
    {
        $config = $this->config()->get("translator.drivers.{$name}", []);

        $model = $config['model'] ?? null;

        if (empty($model)) {
            throw new InvalidArgumentException(
                "The [{$name}] driver requires a model. Set translator.drivers.{$name}.model."
            );
        }

        $factory = (new OpenAiFactory())
            ->withBaseUri($config['base_uri'])
            ->withApiKey((string) ($config['key'] ?? ''));

        foreach ($config['headers'] ?? [] as $header => $value) {
            $factory->withHttpHeader($header, $value);
        }

        return new OpenAiCompatibleTranslator(
            $factory->make(),
            $model,
            $config['options'] ?? [],
            $name,
        );
    }

    protected function createFallbackDriver(): Translator
    {
        $names = $this->config()->get('translator.drivers.fallback.drivers', []);

        if (! is_array($names) || $names === []) {
            throw new InvalidArgumentException(
                'The fallback driver requires a non-empty translator.drivers.fallback.drivers list.'
            );
        }

        $factories = [];

        foreach ($names as $name) {
            if ($name === 'fallback') {
                throw new InvalidArgumentException('The fallback driver cannot reference itself.');
            }

            // Resolve each child lazily through driver() (so it gets its own
            // caching) only when it is actually reached. This prevents a child
            // that cannot be constructed from breaking the whole chain.
            $factories[$name] = fn (): Translator => $this->driver($name);
        }

        return new FallbackTranslator($factories, $this->container->make(LoggerInterface::class));
    }

    /**
     * Resolve a driver and wrap it with caching when enabled.
     */
    protected function createDriver($driver)
    {
        $driver = (string) $driver;

        // Built-in drivers (deepl, google, fallback) and custom extend()
        // creators win first; an entry with a "base_uri" is a direct
        // OpenAI-compatible endpoint; any other name is a Prism LLM driver.
        $resolved = match (true) {
            isset($this->customCreators[$driver]),
            method_exists($this, 'create' . Str::studly($driver) . 'Driver') => parent::createDriver($driver),
            $this->config()->get("translator.drivers.{$driver}.base_uri") !== null => $this->buildOpenAiCompatibleDriver($driver),
            default => $this->buildLlmDriver($driver),
        };

        return $this->wrapWithCache($driver, $resolved);
    }

    protected function wrapWithCache(string $driver, Translator $translator): Translator
    {
        // The fallback driver's children are already cached individually;
        // caching the composite again would mask provider recovery.
        if ($translator instanceof FallbackTranslator) {
            return $translator;
        }

        $cache = $this->config()->get('translator.cache', []);

        if (empty($cache['enabled'])) {
            return $translator;
        }

        $store = $this->container->make(CacheFactory::class)->store($cache['store'] ?? null);

        return new CachingTranslator(
            inner: $translator,
            cache: $store,
            driver: $driver,
            ttl: $cache['ttl'] ?? null,
            prefix: $cache['prefix'] ?? 'translator',
        );
    }

    protected function config(): Config
    {
        return $this->container->make(Config::class);
    }
}
