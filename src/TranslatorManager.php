<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator;

use Anthropic\Factory as AnthropicFactory;
use Closure;
use DeepL\DeepLClient;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Minhyung\LaravelTranslator\Contracts\Translator;
use Minhyung\LaravelTranslator\Drivers\CachingTranslator;
use Minhyung\LaravelTranslator\Drivers\ClaudeDriver;
use Minhyung\LaravelTranslator\Drivers\DeeplDriver;
use Minhyung\LaravelTranslator\Drivers\FallbackDriver;
use Minhyung\LaravelTranslator\Drivers\GoogleDriver;
use Minhyung\LaravelTranslator\Drivers\OpenAiDriver;
use OpenAI\Factory as OpenAiFactory;
use Psr\Log\LoggerInterface;

/**
 * Resolves named translators from config and (optionally) wraps them with
 * caching.
 *
 * Modeled on Laravel's FilesystemManager: each entry under
 * `translator.translators` is a named instance selected with translator(), and
 * its "driver" key picks the implementation (deepl, google, claude, openai,
 * fallback, or a custom one registered via extend()).
 *
 * @mixin \Minhyung\LaravelTranslator\Contracts\Translator
 */
class TranslatorManager
{
    /**
     * Resolved translators, memoized by name.
     *
     * @var array<string, Translator>
     */
    protected array $translators = [];

    /**
     * Custom driver creators registered via extend(), keyed by driver name.
     *
     * @var array<string, Closure>
     */
    protected array $customCreators = [];

    public function __construct(protected Container $container)
    {
    }

    /**
     * Get a translator instance by name (the default when omitted).
     */
    public function translator(?string $name = null): Translator
    {
        $name ??= $this->getDefaultTranslator();

        return $this->translators[$name] ??= $this->resolve($name);
    }

    /**
     * The default translator name.
     */
    public function getDefaultTranslator(): string
    {
        return $this->config()->get('translator.default', 'deepl');
    }

    /**
     * Set the default translator name at runtime.
     */
    public function setDefaultTranslator(string $name): void
    {
        $this->config()->set('translator.default', $name);
    }

    /**
     * Register a custom driver creator.
     *
     * The callback receives ($container, $name, $config) and must return a
     * Translator. Reference it from config with `'driver' => '<driver>'`.
     */
    public function extend(string $driver, Closure $callback): static
    {
        $this->customCreators[$driver] = $callback;

        return $this;
    }

    /**
     * Resolve a fresh translator from its config entry.
     */
    protected function resolve(string $name): Translator
    {
        $config = $this->getConfig($name);

        if ($config === null) {
            throw new InvalidArgumentException("Translator [{$name}] is not defined.");
        }

        if (! isset($config['driver'])) {
            throw new InvalidArgumentException("Translator [{$name}] does not specify a driver.");
        }

        $driver = $config['driver'];

        $translator = isset($this->customCreators[$driver])
            ? ($this->customCreators[$driver])($this->container, $name, $config)
            : $this->callBuiltinCreator($name, $driver, $config);

        return $this->wrapWithCache($name, $translator);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function callBuiltinCreator(string $name, string $driver, array $config): Translator
    {
        $method = 'create' . Str::studly($driver) . 'Driver';

        if (! method_exists($this, $method)) {
            throw new InvalidArgumentException(
                "Driver [{$driver}] for translator [{$name}] is not supported."
            );
        }

        return $this->{$method}($name, $config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createDeeplDriver(string $name, array $config): Translator
    {
        if (empty($config['key'])) {
            throw new InvalidArgumentException(
                "The [{$name}] translator requires a DeepL auth key. Set translator.translators.{$name}.key."
            );
        }

        return new DeeplDriver(new DeepLClient($config['key']), $name);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createGoogleDriver(string $name, array $config): Translator
    {
        if (empty($config['key'])) {
            throw new InvalidArgumentException(
                "The [{$name}] translator requires a Google API key. Set translator.translators.{$name}.key."
            );
        }

        return new GoogleDriver($this->container->make(HttpFactory::class), $config['key'], $name);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createClaudeDriver(string $name, array $config): Translator
    {
        if (empty($config['key'])) {
            throw new InvalidArgumentException(
                "The [{$name}] translator requires an Anthropic API key. Set translator.translators.{$name}.key."
            );
        }

        if (empty($config['model'])) {
            throw new InvalidArgumentException(
                "The [{$name}] translator requires a model. Set translator.translators.{$name}.model."
            );
        }

        $client = (new AnthropicFactory())->withApiKey($config['key'])->make();

        return new ClaudeDriver($client, $config['model'], $config['options'] ?? [], $name);
    }

    /**
     * OpenAI and any OpenAI-compatible endpoint. The base URI defaults to the
     * OpenAI API and can be overridden with "base_url" for compatible providers
     * (DeepSeek, Gemini, Groq, self-hosted gateways, ...).
     *
     * @param  array<string, mixed>  $config
     */
    protected function createOpenaiDriver(string $name, array $config): Translator
    {
        if (empty($config['model'])) {
            throw new InvalidArgumentException(
                "The [{$name}] translator requires a model. Set translator.translators.{$name}.model."
            );
        }

        $factory = (new OpenAiFactory())
            ->withBaseUri($config['base_url'] ?? 'https://api.openai.com/v1')
            ->withApiKey((string) ($config['key'] ?? ''));

        foreach ($config['headers'] ?? [] as $header => $value) {
            $factory->withHttpHeader($header, $value);
        }

        return new OpenAiDriver($factory->make(), $config['model'], $config['options'] ?? [], $name);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createFallbackDriver(string $name, array $config): Translator
    {
        $names = $config['translators'] ?? [];

        if (! is_array($names) || $names === []) {
            throw new InvalidArgumentException(
                "The [{$name}] translator requires a non-empty translator.translators.{$name}.translators list."
            );
        }

        $factories = [];

        foreach ($names as $child) {
            if ($child === $name) {
                throw new InvalidArgumentException("The [{$name}] fallback translator cannot reference itself.");
            }

            // Resolve each child lazily through translator() (so it gets its own
            // caching) only when it is actually reached. This prevents a child
            // that cannot be constructed from breaking the whole chain.
            $factories[$child] = fn (): Translator => $this->translator($child);
        }

        return new FallbackDriver($factories, $this->container->make(LoggerInterface::class));
    }

    protected function wrapWithCache(string $name, Translator $translator): Translator
    {
        // A fallback's children are already cached individually; caching the
        // composite again would mask provider recovery.
        if ($translator instanceof FallbackDriver) {
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
            translator: $name,
            ttl: $cache['ttl'] ?? null,
            prefix: $cache['prefix'] ?? 'translator',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function getConfig(string $name): ?array
    {
        return $this->config()->get("translator.translators.{$name}");
    }

    protected function config(): Config
    {
        return $this->container->make(Config::class);
    }

    /**
     * Forward facade-style calls (translate, translateBatch) to the default translator.
     *
     * @param  array<int, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->translator()->{$method}(...$parameters);
    }
}
