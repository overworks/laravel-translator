<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Console;

use Illuminate\Console\Command;
use Minhyung\LaravelTranslator\TranslatorManager;
use Throwable;

class TranslateCommand extends Command
{
    protected $signature = 'translator:translate
                            {text : The text to translate}
                            {target : Target language code (e.g. ko, en-US)}
                            {--from= : Source language code (auto-detected when omitted)}
                            {--via= : Translator to use (the default when omitted)}
                            {--json : Output the full result as JSON}';

    protected $description = 'Translate a string from the command line';

    public function handle(TranslatorManager $manager): int
    {
        $translator = $manager->via($this->option('via') ?: null);

        try {
            $result = $translator->translate(
                $this->argument('text'),
                $this->argument('target'),
                $this->option('from') ?: null,
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line($this->option('json')
            ? (string) json_encode($result->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            : $result->text);

        return self::SUCCESS;
    }
}
