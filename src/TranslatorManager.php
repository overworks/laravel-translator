<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator;

use Anthropic\Factory as AnthropicFactory;
use Closure;
use DeepL\DeepLClient;
use Google\Auth\ApplicationDefaultCredentials;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Contracts\Translator as TranslatorContract;
use Minhyung\LaravelTranslator\Drivers\CachingDriver;
use Minhyung\LaravelTranslator\Drivers\ClaudeDriver;
use Minhyung\LaravelTranslator\Drivers\DeeplDriver;
use Minhyung\LaravelTranslator\Drivers\FallbackDriver;
use Minhyung\LaravelTranslator\Drivers\GoogleDriver;
use Minhyung\LaravelTranslator\Drivers\GoogleV3Driver;
use Minhyung\LaravelTranslator\Drivers\OpenAiDriver;
use OpenAI\Factory as OpenAiFactory;
use Psr\Log\LoggerInterface;

/**
 * Resolves named translators from config and (optionally) wraps them with
 * caching.
 *
 * Each entry under `translator.translators` is a named instance selected with
 * via(), and its "driver" key picks the implementation (deepl, google, claude,
 * openai, fallback, or a custom one registered via extend()). A {@see Translator}
 * is the public object handed back; the {@see Driver} is the implementation
 * behind it.
 *
 * @mixin \Minhyung\LaravelTranslator\Contracts\Translator
 */
class TranslatorManager
{
    /**
     * Resolved translators, memoized by name.
     *
     * @var array<string, TranslatorContract>
     */
    protected array $translators = [];

    /**
     * Resolved drivers (cache-wrapped), memoized by name.
     *
     * @var array<string, Driver>
     */
    protected array $drivers = [];

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
     * Get a translator by name (the default when omitted).
     */
    public function via(?string $name = null): TranslatorContract
    {
        $name ??= $this->getDefaultTranslator();

        return $this->translators[$name] ??= new Translator(
            $name,
            $this->resolveDriver($name),
            $this->container->make(Dispatcher::class),
        );
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
     * {@see Driver}. Reference it from config with `'driver' => '<driver>'`.
     */
    public function extend(string $driver, Closure $callback): static
    {
        $this->customCreators[$driver] = $callback;

        return $this;
    }

    /**
     * Build a one-off translator from an inline config array, without a
     * `translator.translators` entry. The config takes the same shape as a
     * config entry (`driver` plus its keys); the result is uncached.
     *
     * @param  array<string, mixed>  $config
     */
    public function build(array $config, ?string $name = null): Translator
    {
        $driver = $config['driver'] ?? null;

        if (empty($driver)) {
            throw new InvalidArgumentException('The translator config must specify a "driver".');
        }

        $name ??= $driver;

        return new Translator(
            $name,
            $this->makeDriver($name, $config),
            $this->container->make(Dispatcher::class),
        );
    }

    /**
     * Resolve a translator's driver from its config entry, wrapping it with
     * caching when enabled. Memoized by name.
     */
    protected function resolveDriver(string $name): Driver
    {
        $config = $this->getConfig($name);

        if ($config === null) {
            throw new InvalidArgumentException("Translator [{$name}] is not defined.");
        }

        return $this->drivers[$name] ??= $this->wrapWithCache($name, $this->makeDriver($name, $config));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function makeDriver(string $name, array $config): Driver
    {
        if (! isset($config['driver'])) {
            throw new InvalidArgumentException("Translator [{$name}] does not specify a driver.");
        }

        $driver = $config['driver'];

        return isset($this->customCreators[$driver])
            ? ($this->customCreators[$driver])($this->container, $name, $config)
            : $this->callBuiltinCreator($name, $driver, $config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function callBuiltinCreator(string $name, string $driver, array $config): Driver
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
    protected function createDeeplDriver(string $name, array $config): Driver
    {
        if (empty($config['key'])) {
            throw new InvalidArgumentException(
                "The [{$name}] translator requires a DeepL auth key. Set translator.translators.{$name}.key."
            );
        }

        return new DeeplDriver(new DeepLClient($config['key']), $name);
    }

    /**
     * Google Cloud Translation. Defaults to v2 (API key); set "version" => 3 for
     * the Advanced API, which authenticates with a service account / ADC.
     *
     * @param  array<string, mixed>  $config
     */
    protected function createGoogleDriver(string $name, array $config): Driver
    {
        if ((int) ($config['version'] ?? 2) === 3) {
            return $this->createGoogleV3Driver($name, $config);
        }

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
    protected function createGoogleV3Driver(string $name, array $config): Driver
    {
        if (empty($config['project_id'])) {
            throw new InvalidArgumentException(
                "The [{$name}] translator (Google v3) requires a project id. Set translator.translators.{$name}.project_id."
            );
        }

        $scope = 'https://www.googleapis.com/auth/cloud-translation';
        $credentialsPath = $config['credentials'] ?? null;

        // Build the credentials and fetch the token lazily (and memoize it until
        // it nears expiry), so constructing the driver never touches the network.
        $credentials = null;
        $token = null;
        $expiresAt = 0;
        $tokenProvider = function () use ($scope, $credentialsPath, &$credentials, &$token, &$expiresAt): string {
            if ($token !== null && time() < $expiresAt - 60) {
                return $token;
            }

            $credentials ??= empty($credentialsPath)
                ? ApplicationDefaultCredentials::getCredentials($scope)
                : new ServiceAccountCredentials($scope, $credentialsPath);

            $fetched = $credentials->fetchAuthToken();
            $token = (string) ($fetched['access_token'] ?? '');
            $expiresAt = time() + (int) ($fetched['expires_in'] ?? 3600);

            return $token;
        };

        return new GoogleV3Driver(
            $this->container->make(HttpFactory::class),
            $tokenProvider,
            (string) $config['project_id'],
            (string) ($config['location'] ?? 'global'),
            $name,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createClaudeDriver(string $name, array $config): Driver
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
    protected function createOpenaiDriver(string $name, array $config): Driver
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
    protected function createFallbackDriver(string $name, array $config): Driver
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

            // Resolve each child driver lazily (so it gets its own caching) only
            // when it is actually reached. This prevents a child that cannot be
            // constructed from breaking the whole chain.
            $factories[$child] = fn (): Driver => $this->resolveDriver($child);
        }

        return new FallbackDriver(
            $factories,
            $this->container->make(LoggerInterface::class),
            $this->container->make(Dispatcher::class),
        );
    }

    protected function wrapWithCache(string $name, Driver $driver): Driver
    {
        // A fallback's children are already cached individually; caching the
        // composite again would mask provider recovery.
        if ($driver instanceof FallbackDriver) {
            return $driver;
        }

        $cache = $this->config()->get('translator.cache', []);

        if (empty($cache['enabled'])) {
            return $driver;
        }

        $store = $this->container->make(CacheFactory::class)->store($cache['store'] ?? null);

        return new CachingDriver(
            inner: $driver,
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
        return $this->via()->{$method}(...$parameters);
    }
}
