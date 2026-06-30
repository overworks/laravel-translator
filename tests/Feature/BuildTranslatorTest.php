<?php

declare(strict_types=1);

use Minhyung\LaravelTranslator\Contracts\Translator as TranslatorContract;
use Minhyung\LaravelTranslator\Drivers\DeeplDriver;
use Minhyung\LaravelTranslator\Drivers\OpenAiDriver;
use Minhyung\LaravelTranslator\Facades\Translator;
use Minhyung\LaravelTranslator\TranslatorManager;

it('builds a translator at runtime from an inline config, no config entry needed', function () {
    $translator = app(TranslatorManager::class)->build([
        'driver' => 'openai',
        'base_url' => 'https://api.deepseek.com/v1',
        'key' => 'sk-test',
        'model' => 'deepseek-v4-flash',
    ]);

    expect($translator)->toBeInstanceOf(TranslatorContract::class)
        ->and($translator->driver())->toBeInstanceOf(OpenAiDriver::class)
        ->and($translator->name())->toBe('openai');
});

it('uses an explicit name when given', function () {
    $translator = app(TranslatorManager::class)->build(
        ['driver' => 'deepl', 'key' => 'k:fx'],
        'primary',
    );

    expect($translator->name())->toBe('primary')
        ->and($translator->driver())->toBeInstanceOf(DeeplDriver::class);
});

it('builds and translates through a custom driver', function () {
    app(TranslatorManager::class)->extend('memory', fn () => stubDriver('memory', '안녕'));

    $result = app(TranslatorManager::class)
        ->build(['driver' => 'memory'])
        ->translate('Hello', 'ko');

    expect($result->text)->toBe('안녕');
});

it('is reachable through the facade', function () {
    Translator::build(['driver' => 'deepl', 'key' => 'k:fx']);

    expect(Translator::build(['driver' => 'deepl', 'key' => 'k:fx'])->driver())
        ->toBeInstanceOf(DeeplDriver::class);
});

it('throws when the config has no driver', function () {
    expect(fn () => app(TranslatorManager::class)->build(['key' => 'x']))
        ->toThrow(InvalidArgumentException::class);
});

it('throws for an unsupported driver', function () {
    expect(fn () => app(TranslatorManager::class)->build(['driver' => 'nope', 'model' => 'x']))
        ->toThrow(InvalidArgumentException::class);
});
