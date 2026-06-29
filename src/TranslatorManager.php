<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator;

use BadMethodCallException;
use DeepL\DeepLClient;
use Google\Cloud\Translate\V3\Client\TranslationServiceClient;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Manager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Minhyung\LaravelTranslator\Contracts\Translator;
use Minhyung\LaravelTranslator\Drivers\CachingTranslator;
use Minhyung\LaravelTranslator\Drivers\DeeplTranslator;
use Minhyung\LaravelTranslator\Drivers\FallbackTranslator;
use Minhyung\LaravelTranslator\Drivers\GoogleTranslator;
use Minhyung\LaravelTranslator\Drivers\LlmTranslator;
use Psr\Log\LoggerInterface;

/**
 * Resolves translation providers and (optionally) wraps them with caching.
 *
 * @mixin \Minhyung\LaravelTranslator\Contracts\Translator
 */
class TranslatorManager extends Manager
{
    public function getDefaultDriver()
    {
        return $this->config()->get('translator.default', 'deepl');
    }

    /**
     * Resolve a translation provider by name (or the default when omitted).
     */
    public function provider(?string $name = null): Translator
    {
        // parent::driver() holds the resolution + memoization logic; the public
        // driver() selector below is intentionally retired in favour of this.
        return parent::driver($name);
    }

    /**
     * @deprecated Use {@see provider()} to select a translation provider.
     */
    public function driver($driver = null)
    {
        throw new BadMethodCallException(
            'TranslatorManager::driver() has been removed; use provider() to select a translation provider.'
        );
    }

    /**
     * Forward facade calls (translate, translateBatch, ...) to the default provider.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->provider()->$method(...$parameters);
    }

    protected function createDeeplDriver(): Translator
    {
        $key = $this->config()->get('translator.providers.deepl.key');

        if (empty($key)) {
            throw new InvalidArgumentException(
                'The DeepL provider requires an auth key. Set DEEPL_AUTH_KEY or translator.providers.deepl.key.'
            );
        }

        return new DeeplTranslator(new DeepLClient($key));
    }

    protected function createGoogleDriver(): Translator
    {
        $config = $this->config()->get('translator.providers.google', []);

        $projectId = $config['project_id'] ?? null;

        if (empty($projectId)) {
            throw new InvalidArgumentException(
                'The Google provider requires a project id. Set GOOGLE_CLOUD_PROJECT or translator.providers.google.project_id.'
            );
        }

        // REST transport keeps the package free of the gRPC PECL extension.
        $clientOptions = ['transport' => 'rest'];

        if (! empty($config['credentials'])) {
            $clientOptions['credentials'] = $config['credentials'];
        }

        return new GoogleTranslator(
            new TranslationServiceClient($clientOptions),
            $projectId,
            $config['location'] ?? 'global',
        );
    }

    /**
     * Build an LLM provider (via Prism) whose name is the provider itself —
     * e.g. "openai", "anthropic", "gemini". The Prism provider defaults to the
     * name and may be overridden with a "provider" config key.
     */
    protected function createLlmProvider(string $name): Translator
    {
        $config = $this->config()->get("translator.providers.{$name}", []);

        $model = $config['model'] ?? null;

        if (! is_array($config) || empty($model)) {
            throw new InvalidArgumentException(
                "The [{$name}] provider requires a model. Set translator.providers.{$name}.model."
            );
        }

        return new LlmTranslator(
            $config['provider'] ?? $name,
            $model,
            $config['options'] ?? [],
            $name,
        );
    }

    protected function createFallbackDriver(): Translator
    {
        $names = $this->config()->get('translator.providers.fallback.providers', []);

        if (! is_array($names) || $names === []) {
            throw new InvalidArgumentException(
                'The fallback driver requires a non-empty translator.providers.fallback.providers list.'
            );
        }

        $factories = [];

        foreach ($names as $name) {
            if ($name === 'fallback') {
                throw new InvalidArgumentException('The fallback driver cannot reference itself.');
            }

            // Resolve each child lazily through provider() (so it gets its own
            // caching) only when it is actually reached. This prevents a child
            // that cannot be constructed from breaking the whole chain.
            $factories[$name] = fn (): Translator => $this->provider($name);
        }

        return new FallbackTranslator($factories, $this->container->make(LoggerInterface::class));
    }

    /**
     * Resolve a provider and wrap it with caching when enabled.
     */
    protected function createDriver($driver)
    {
        $driver = (string) $driver;

        // Built-in providers (deepl, google, fallback) and custom extend()
        // creators win first; any other name is treated as an LLM provider.
        $resolved = isset($this->customCreators[$driver]) || method_exists($this, 'create' . Str::studly($driver) . 'Driver')
            ? parent::createDriver($driver)
            : $this->createLlmProvider($driver);

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
