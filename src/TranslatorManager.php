<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator;

use DeepL\DeepLClient;
use Google\Cloud\Translate\V3\Client\TranslationServiceClient;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Manager;
use InvalidArgumentException;
use Minhyung\LaravelTranslator\Contracts\Translator;
use Minhyung\LaravelTranslator\Drivers\CachingTranslator;
use Minhyung\LaravelTranslator\Drivers\DeeplTranslator;
use Minhyung\LaravelTranslator\Drivers\GoogleTranslator;

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
        $config = $this->config()->get('translator.drivers.google', []);

        $projectId = $config['project_id'] ?? null;

        if (empty($projectId)) {
            throw new InvalidArgumentException(
                'The Google driver requires a project id. Set GOOGLE_CLOUD_PROJECT or translator.drivers.google.project_id.'
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
     * Wrap every resolved driver with caching when enabled in config.
     */
    protected function createDriver($driver)
    {
        return $this->wrapWithCache((string) $driver, parent::createDriver($driver));
    }

    protected function wrapWithCache(string $driver, Translator $translator): Translator
    {
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
