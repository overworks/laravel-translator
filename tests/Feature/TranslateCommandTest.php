<?php

declare(strict_types=1);

use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Support\TranslationResult;
use Minhyung\LaravelTranslator\TranslatorManager;

beforeEach(function () {
    config()->set('translator.cache.enabled', false);
    config()->set('translator.default', 'stub');
    config()->set('translator.translators.stub', ['driver' => 'stub']);
    config()->set('translator.translators.other', ['driver' => 'other']);

    app(TranslatorManager::class)
        ->extend('stub', fn () => stubDriver('stub', '안녕하세요'))
        ->extend('other', fn () => stubDriver('other', '여보세요'));
});

it('translates text and prints the result', function () {
    $this->artisan('translator:translate', ['text' => 'Hello', 'target' => 'ko'])
        ->expectsOutput('안녕하세요')
        ->assertSuccessful();
});

it('uses the translator named by --via', function () {
    $this->artisan('translator:translate', ['text' => 'Hello', 'target' => 'ko', '--via' => 'other'])
        ->expectsOutput('여보세요')
        ->assertSuccessful();
});

it('outputs JSON with --json', function () {
    $this->artisan('translator:translate', ['text' => 'Hello', 'target' => 'ko', '--json' => true])
        ->assertSuccessful();
});

it('fails gracefully when the translator throws', function () {
    config()->set('translator.translators.boom', ['driver' => 'boom']);
    app(TranslatorManager::class)->extend('boom', fn () => new class implements Driver
    {
        public function translate(string $text, string $targetLang, ?string $sourceLang = null, array $options = []): TranslationResult
        {
            throw new RuntimeException('provider down');
        }

        public function translateBatch(array $texts, string $targetLang, ?string $sourceLang = null, array $options = []): array
        {
            return [];
        }
    });

    $this->artisan('translator:translate', ['text' => 'Hello', 'target' => 'ko', '--via' => 'boom'])
        ->expectsOutputToContain('provider down')
        ->assertFailed();
});
