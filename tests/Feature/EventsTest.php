<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Events\BatchTranslationCompleted;
use Minhyung\LaravelTranslator\Events\TranslationCompleted;
use Minhyung\LaravelTranslator\Events\TranslationFailed;
use Minhyung\LaravelTranslator\Events\TranslationFellBack;
use Minhyung\LaravelTranslator\Facades\Translator;
use Minhyung\LaravelTranslator\Support\TranslationResult;
use Minhyung\LaravelTranslator\TranslatorManager;

beforeEach(function () {
    config()->set('translator.cache.enabled', false);
});

/**
 * A driver that always throws, for failure-path tests.
 */
function throwingDriver(): Driver
{
    return new class implements Driver
    {
        public function translate(string $text, string $targetLang, ?string $sourceLang = null, array $options = []): TranslationResult
        {
            throw new RuntimeException('provider down');
        }

        public function translateBatch(array $texts, string $targetLang, ?string $sourceLang = null, array $options = []): array
        {
            throw new RuntimeException('provider down');
        }
    };
}

it('dispatches TranslationCompleted on a successful single translation', function () {
    config()->set('translator.default', 'stub');
    config()->set('translator.translators.stub', ['driver' => 'stub']);
    app(TranslatorManager::class)->extend('stub', fn () => stubDriver('stub', '안녕하세요'));

    Event::fake();

    Translator::translate('Hello', 'ko', 'en');

    Event::assertDispatched(TranslationCompleted::class, function (TranslationCompleted $e) {
        return $e->translator === 'stub'
            && $e->text === 'Hello'
            && $e->sourceLang === 'en'
            && $e->result->text === '안녕하세요';
    });
});

it('dispatches BatchTranslationCompleted on a successful batch', function () {
    config()->set('translator.default', 'stub');
    config()->set('translator.translators.stub', ['driver' => 'stub']);
    app(TranslatorManager::class)->extend('stub', fn () => stubDriver('stub'));

    Event::fake();

    Translator::translateBatch(['greeting' => 'Hello'], 'ko');

    Event::assertDispatched(BatchTranslationCompleted::class, function (BatchTranslationCompleted $e) {
        return $e->translator === 'stub'
            && $e->texts === ['greeting' => 'Hello']
            && $e->targetLang === 'ko';
    });
});

it('dispatches TranslationFailed when the driver throws', function () {
    config()->set('translator.default', 'boom');
    config()->set('translator.translators.boom', ['driver' => 'boom']);
    app(TranslatorManager::class)->extend('boom', fn () => throwingDriver());

    Event::fake();

    try {
        Translator::translate('Hello', 'ko');
    } catch (Throwable) {
        // expected
    }

    Event::assertDispatched(TranslationFailed::class, function (TranslationFailed $e) {
        return $e->translator === 'boom'
            && $e->texts === ['Hello']
            && $e->exception->getMessage() === 'provider down';
    });
});

it('dispatches TranslationFellBack when a fallback child fails', function () {
    config()->set('translator.default', 'safe');
    config()->set('translator.translators.boom', ['driver' => 'boom']);
    config()->set('translator.translators.ok', ['driver' => 'ok']);
    config()->set('translator.translators.safe', [
        'driver' => 'fallback',
        'translators' => ['boom', 'ok'],
    ]);

    app(TranslatorManager::class)
        ->extend('boom', fn () => throwingDriver())
        ->extend('ok', fn () => stubDriver('ok', '안녕'));

    Event::fake();

    expect(Translator::translate('Hello', 'ko')->text)->toBe('안녕');

    Event::assertDispatched(TranslationFellBack::class, function (TranslationFellBack $e) {
        return $e->translator === 'boom' && $e->exception->getMessage() === 'provider down';
    });
});
