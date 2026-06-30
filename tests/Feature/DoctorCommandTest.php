<?php

declare(strict_types=1);

use Minhyung\LaravelTranslator\TranslatorManager;

beforeEach(function () {
    config()->set('translator.cache.enabled', false);
});

it('passes when every translator is configured correctly', function () {
    config()->set('translator.default', 'deepl');
    config()->set('translator.translators', [
        'deepl' => ['driver' => 'deepl', 'key' => 'k:fx'],
    ]);

    $this->artisan('translator:doctor')
        ->expectsOutputToContain('OK')
        ->assertSuccessful();
});

it('passes for a custom driver registered via extend', function () {
    config()->set('translator.default', 'papago');
    config()->set('translator.translators', ['papago' => ['driver' => 'papago']]);
    app(TranslatorManager::class)->extend('papago', fn () => stubDriver('papago'));

    $this->artisan('translator:doctor')->assertSuccessful();
});

it('fails when a required key is missing', function () {
    config()->set('translator.default', 'deepl');
    config()->set('translator.translators', [
        'deepl' => ['driver' => 'deepl', 'key' => null],
    ]);

    $this->artisan('translator:doctor')->assertFailed();
});

it('fails when a fallback references an unknown translator', function () {
    config()->set('translator.default', 'deepl');
    config()->set('translator.translators', [
        'deepl' => ['driver' => 'deepl', 'key' => 'k:fx'],
        'safe' => ['driver' => 'fallback', 'translators' => ['deepl', 'ghost']],
    ]);

    $this->artisan('translator:doctor')
        ->expectsOutputToContain('ghost')
        ->assertFailed();
});

it('fails when the default translator is not defined', function () {
    config()->set('translator.default', 'nope');
    config()->set('translator.translators', [
        'deepl' => ['driver' => 'deepl', 'key' => 'k:fx'],
    ]);

    $this->artisan('translator:doctor')->assertFailed();
});

it('fails when no default translator is set', function () {
    config()->set('translator.default', '');
    config()->set('translator.translators', [
        'deepl' => ['driver' => 'deepl', 'key' => 'k:fx'],
    ]);

    $this->artisan('translator:doctor')->assertFailed();
});
