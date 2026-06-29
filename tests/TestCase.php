<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Tests;

use Minhyung\LaravelTranslator\TranslatorServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Prism\Prism\PrismServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            PrismServiceProvider::class,
            TranslatorServiceProvider::class,
        ];
    }
}
