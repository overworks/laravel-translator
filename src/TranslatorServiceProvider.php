<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Minhyung\LaravelTranslator\Contracts\Translator;

class TranslatorServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/translator.php', 'translator');

        // NOTE: do not use the 'translator' container key — Laravel reserves it
        // for its own localization translator (the Lang facade).
        $this->app->singleton(TranslatorManager::class, fn ($app) => new TranslatorManager($app));

        // Resolve the contract to the default translator for clean dependency injection.
        $this->app->bind(Translator::class, fn ($app) => $app->make(TranslatorManager::class)->translator());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/translator.php' => $this->app->configPath('translator.php'),
            ], 'translator-config');
        }
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [TranslatorManager::class, Translator::class];
    }
}
