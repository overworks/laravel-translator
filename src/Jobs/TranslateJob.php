<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Minhyung\LaravelTranslator\Events\TranslationFailed;
use Minhyung\LaravelTranslator\Translator;
use Minhyung\LaravelTranslator\TranslatorManager;
use Throwable;

/**
 * Queued translation. Holds the translator name (not the instance, which can't
 * be serialized) plus the request, re-resolves the translator on the worker,
 * and runs it. Results are delivered through the usual lifecycle events
 * (TranslationCompleted / BatchTranslationCompleted), which fire as the
 * translation runs on the queue.
 *
 * handle() uses the translator's "quiet" methods, which skip the
 * TranslationFailed event, and rethrows on error so the queue can retry. The
 * failure event is emitted from failed(), which the queue calls only once the
 * job has permanently failed — after the worker exhausts its retries, whether
 * the limit comes from this job's $tries or the worker's --tries option. That
 * keeps a retried job from reporting a failure on every attempt.
 */
class TranslateJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Max attempts. Read by the queue worker; null defers to the worker default.
     */
    public ?int $tries = null;

    /**
     * Seconds to wait before retrying — an int, or an array of per-attempt
     * seconds. Read by the queue worker; null means no backoff.
     *
     * @var int|array<int, int>|null
     */
    public int|array|null $backoff = null;

    /**
     * @param  string|null  $translator  Translator name, or null for the default.
     * @param  string|array<array-key, string>  $text  One text, or many for a batch.
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public ?string $translator,
        public string|array $text,
        public string $targetLang,
        public ?string $sourceLang = null,
        public array $options = [],
    ) {
    }

    public function handle(TranslatorManager $manager): void
    {
        /** @var Translator $translator */
        $translator = $manager->via($this->translator);

        if (is_array($this->text)) {
            $translator->translateBatchQuietly($this->text, $this->targetLang, $this->sourceLang, $this->options);

            return;
        }

        $translator->translateQuietly($this->text, $this->targetLang, $this->sourceLang, $this->options);
    }

    /**
     * Called by the queue once the job has permanently failed (retries
     * exhausted). Emit the failure event here — and only here — so it reflects
     * the ultimate failure and never a retried attempt.
     */
    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }

        $texts = is_array($this->text) ? $this->text : [$this->text];

        // queue() always stores the resolved translator name; a job built with a
        // null name (the "default" translator) resolves it here for the event.
        $name = $this->translator ?? App::make(TranslatorManager::class)->getDefaultTranslator();

        Event::dispatch(new TranslationFailed(
            $name,
            $exception,
            $texts,
            $this->targetLang,
            $this->sourceLang,
            $this->options,
        ));
    }
}
