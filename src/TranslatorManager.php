<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator;

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

    /**
     * Resolve a translation provider by name (or the default when omitted).
     */
    public function provider(?string $name = null): Translator
    {
        return $this->driver($name);
    }

    protected function createDeeplDriver(): Translator
    {
        return $this->buildDeeplDriver($this->config()->get('translator.providers.deepl', []));
    }

    protected function createGoogleDriver(): Translator
    {
        return $this->buildGoogleDriver($this->config()->get('translator.providers.google', []));
    }

    protected function createLlmDriver(): Translator
    {
        return $this->buildLlmDriver($this->config()->get('translator.providers.llm', []), 'llm');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function buildDeeplDriver(array $config): Translator
    {
        $key = $config['key'] ?? null;

        if (empty($key)) {
            throw new InvalidArgumentException(
                'The DeepL driver requires an auth key. Set DEEPL_AUTH_KEY or translator.providers.deepl.key.'
            );
        }

        return new DeeplTranslator(new DeepLClient($key));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function buildGoogleDriver(array $config): Translator
    {
        $projectId = $config['project_id'] ?? null;

        if (empty($projectId)) {
            throw new InvalidArgumentException(
                'The Google driver requires a project id. Set GOOGLE_CLOUD_PROJECT or translator.providers.google.project_id.'
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
     * @param  array<string, mixed>  $config
     */
    protected function buildLlmDriver(array $config, string $name): Translator
    {
        $provider = $config['provider'] ?? null;
        $model = $config['model'] ?? null;

        if (empty($provider) || empty($model)) {
            throw new InvalidArgumentException(
                "The [{$name}] driver requires a provider and model (translator.providers.{$name}.provider and .model)."
            );
        }

        return new LlmTranslator($provider, $model, $config['options'] ?? [], $name);
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
     * Build a named driver defined entirely in config via its "driver" type,
     * e.g. ['driver' => 'llm', 'provider' => 'anthropic', 'model' => '...'].
     * This lets several providers (multiple LLMs, DeepL accounts, ...) be
     * registered under their own names and referenced directly.
     */
    protected function createConfiguredDriver(string $name): Translator
    {
        $config = $this->config()->get("translator.providers.{$name}");

        if (! is_array($config) || empty($config['driver'])) {
            throw new InvalidArgumentException(
                "Translation driver [{$name}] is not configured. Define translator.providers.{$name} with a 'driver' key."
            );
        }

        return match ($config['driver']) {
            'deepl' => $this->buildDeeplDriver($config),
            'google' => $this->buildGoogleDriver($config),
            'llm' => $this->buildLlmDriver($config, $name),
            default => throw new InvalidArgumentException(
                "Unsupported driver type [{$config['driver']}] for [{$name}]."
            ),
        };
    }

    /**
     * Wrap every resolved driver with caching when enabled in config.
     */
    protected function createDriver($driver)
    {
        $driver = (string) $driver;

        // Built-in driver methods (deepl, google, llm, fallback) and custom
        // extend() creators win first; otherwise treat the name as a config-
        // defined connection.
        $resolved = isset($this->customCreators[$driver]) || method_exists($this, 'create' . Str::studly($driver) . 'Driver')
            ? parent::createDriver($driver)
            : $this->createConfiguredDriver($driver);

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
