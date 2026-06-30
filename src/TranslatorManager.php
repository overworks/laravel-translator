<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator;

use Anthropic\Factory as AnthropicFactory;
use DeepL\DeepLClient;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Manager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Minhyung\LaravelTranslator\Contracts\Translator;
use Minhyung\LaravelTranslator\Drivers\AnthropicTranslator;
use Minhyung\LaravelTranslator\Drivers\CachingTranslator;
use Minhyung\LaravelTranslator\Drivers\DeeplTranslator;
use Minhyung\LaravelTranslator\Drivers\FallbackTranslator;
use Minhyung\LaravelTranslator\Drivers\GoogleTranslator;
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
    /**
     * Well-known providers that speak the OpenAI chat-completions schema,
     * mapped to their default base URI. A driver entry may override the URI
     * with its own "base_uri"; an unlisted name needs an explicit "base_uri".
     *
     * @var array<string, string>
     */
    protected const OPENAI_COMPATIBLE_PRESETS = [
        'openai' => 'https://api.openai.com/v1',
        'deepseek' => 'https://api.deepseek.com/v1',
        'groq' => 'https://api.groq.com/openai/v1',
        'mistral' => 'https://api.mistral.ai/v1',
        'xai' => 'https://api.x.ai/v1',
        'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai',
        'openrouter' => 'https://openrouter.ai/api/v1',
        'ollama' => 'http://localhost:11434/v1',
    ];

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
     * Native Claude driver talking to the Anthropic Messages API directly.
     */
    protected function createAnthropicDriver(): Translator
    {
        $config = $this->config()->get('translator.drivers.anthropic', []);
        $key = $config['key'] ?? null;
        $model = $config['model'] ?? null;

        if (empty($key)) {
            throw new InvalidArgumentException(
                'The Anthropic driver requires an API key. Set ANTHROPIC_API_KEY or translator.drivers.anthropic.key.'
            );
        }

        if (empty($model)) {
            throw new InvalidArgumentException(
                'The Anthropic driver requires a model. Set translator.drivers.anthropic.model.'
            );
        }

        $client = (new AnthropicFactory())->withApiKey($key)->make();

        return new AnthropicTranslator($client, $model, $config['options'] ?? [], 'anthropic');
    }

    /**
     * Build a driver for any OpenAI-compatible chat-completions endpoint,
     * talking to it directly through openai-php/client. The base URI comes
     * from the entry's "base_uri", falling back to a built-in preset for
     * well-known providers; "key" and optional "headers" configure the client.
     */
    protected function buildOpenAiCompatibleDriver(string $name): Translator
    {
        $config = $this->config()->get("translator.drivers.{$name}", []);

        if (! is_array($config)) {
            $config = [];
        }

        $model = $config['model'] ?? null;

        if (empty($model)) {
            throw new InvalidArgumentException(
                "The [{$name}] driver requires a model. Set translator.drivers.{$name}.model."
            );
        }

        $baseUri = $config['base_uri'] ?? static::OPENAI_COMPATIBLE_PRESETS[$name] ?? null;

        if (empty($baseUri)) {
            throw new InvalidArgumentException(
                "The [{$name}] driver is not a known provider. Set translator.drivers.{$name}.base_uri "
                . 'to its OpenAI-compatible endpoint.'
            );
        }

        $factory = (new OpenAiFactory())
            ->withBaseUri($baseUri)
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

        // Built-in drivers (deepl, google, anthropic, fallback) and custom
        // extend() creators win first; any other name is treated as an
        // OpenAI-compatible endpoint (preset or explicit "base_uri").
        $resolved = isset($this->customCreators[$driver]) || method_exists($this, 'create' . Str::studly($driver) . 'Driver')
            ? parent::createDriver($driver)
            : $this->buildOpenAiCompatibleDriver($driver);

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
