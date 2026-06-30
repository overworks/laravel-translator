<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Events\BatchTranslationCompleted;
use Minhyung\LaravelTranslator\Events\TranslationCompleted;
use Minhyung\LaravelTranslator\Events\TranslationFailed;
use Minhyung\LaravelTranslator\Facades\Translator;
use Minhyung\LaravelTranslator\Jobs\TranslateJob;
use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * A driver that always throws, for the queued failure-path tests.
 */
function queueThrowingDriver(): Driver
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

/**
 * Run a job's handle() with the manager Laravel would inject on the worker.
 */
function handleTranslateJob(TranslateJob $job): void
{
    $job->handle(app(\Minhyung\LaravelTranslator\TranslatorManager::class));
}

beforeEach(function () {
    config()->set('translator.default', 'stub');
    config()->set('translator.cache.enabled', false);
    config()->set('translator.translators.stub', ['driver' => 'stub']);

    app(\Minhyung\LaravelTranslator\TranslatorManager::class)
        ->extend('stub', fn () => stubDriver('stub', '안녕하세요'));
});

it('pushes a translate job onto the queue', function () {
    Queue::fake();

    Translator::queue('Hello', 'ko', 'en');

    Queue::assertPushed(TranslateJob::class, function (TranslateJob $job) {
        return $job->translator === 'stub'
            && $job->text === 'Hello'
            && $job->targetLang === 'ko'
            && $job->sourceLang === 'en';
    });
});

it('pushes a batch translate job carrying the texts array', function () {
    Queue::fake();

    Translator::via('stub')->queueBatch(['x' => 'Hello', 'y' => 'World'], 'ko');

    Queue::assertPushed(TranslateJob::class, function (TranslateJob $job) {
        return $job->text === ['x' => 'Hello', 'y' => 'World']
            && $job->translator === 'stub';
    });
});

it('applies the configured connection and queue defaults', function () {
    config()->set('translator.queue.connection', 'redis');
    config()->set('translator.queue.queue', 'translations');

    Queue::fake();

    Translator::queue('Hello', 'ko');

    Queue::assertPushedOn('translations', TranslateJob::class);
    Queue::assertPushed(TranslateJob::class, fn (TranslateJob $job) => $job->connection === 'redis');
});

it('records the configured tries on the job', function () {
    config()->set('translator.queue.tries', 5);

    Queue::fake();

    Translator::queue('Hello', 'ko');

    Queue::assertPushed(TranslateJob::class, fn (TranslateJob $job) => $job->tries === 5);
});

it('runs the translation and dispatches the completed event when handled', function () {
    Event::fake([TranslationCompleted::class]);

    handleTranslateJob(new TranslateJob('stub', 'Hello', 'ko', 'en'));

    Event::assertDispatched(
        TranslationCompleted::class,
        fn (TranslationCompleted $event) => $event->translator === 'stub'
            && $event->result->text === '안녕하세요',
    );
});

it('runs a batch translation and dispatches the batch event when handled', function () {
    Event::fake([BatchTranslationCompleted::class]);

    handleTranslateJob(new TranslateJob('stub', ['a' => 'Hello', 'b' => 'World'], 'ko'));

    Event::assertDispatched(
        BatchTranslationCompleted::class,
        fn (BatchTranslationCompleted $event) => $event->translator === 'stub'
            && $event->texts === ['a' => 'Hello', 'b' => 'World'],
    );
});

it('stays quiet on failure during handle(), leaving the event to failed()', function () {
    config()->set('translator.translators.boom', ['driver' => 'boom']);
    app(\Minhyung\LaravelTranslator\TranslatorManager::class)
        ->extend('boom', fn () => queueThrowingDriver());

    Event::fake([TranslationFailed::class]);

    // handle() rethrows so the worker can retry/fail the job; it must not emit
    // a failure event itself (the worker, not the job, owns retry finality).
    expect(fn () => handleTranslateJob(new TranslateJob('boom', 'Hello', 'ko')))
        ->toThrow(RuntimeException::class);

    Event::assertNotDispatched(TranslationFailed::class);
});

it('dispatches TranslationFailed exactly once from failed()', function () {
    Event::fake([TranslationFailed::class]);

    // The queue calls failed() only after retries are exhausted, regardless of
    // whether the limit came from the job or the worker's --tries.
    (new TranslateJob('boom', 'Hello', 'ko', 'en'))
        ->failed(new RuntimeException('provider down'));

    Event::assertDispatchedTimes(TranslationFailed::class, 1);
    Event::assertDispatched(
        TranslationFailed::class,
        fn (TranslationFailed $event) => $event->translator === 'boom'
            && $event->texts === ['Hello']
            && $event->exception->getMessage() === 'provider down',
    );
});

it('carries the resolved translator name into the failure event', function () {
    Queue::fake();
    Event::fake([TranslationFailed::class]);

    // The default translator ("stub") is resolved when the job is queued, so its
    // name travels with the job to failed().
    $job = Translator::queue('Hello', 'ko', 'en');

    $job->failed(new RuntimeException('provider down'));

    Event::assertDispatched(
        TranslationFailed::class,
        fn (TranslationFailed $event) => $event->translator === 'stub'
            && $event->texts === ['Hello'],
    );
});

it('resolves the default translator name when the job was built with none', function () {
    Event::fake([TranslationFailed::class]);

    // A job constructed with a null translator (the "default") must still report
    // a meaningful name — the default ("stub") — never an empty string.
    (new TranslateJob(null, ['a' => 'Hello'], 'ko'))
        ->failed(new RuntimeException('provider down'));

    Event::assertDispatched(
        TranslationFailed::class,
        fn (TranslationFailed $event) => $event->translator === 'stub'
            && $event->texts === ['a' => 'Hello'],
    );
});
