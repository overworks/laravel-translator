<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Console;

use Illuminate\Console\Command;
use Minhyung\LaravelTranslator\TranslatorManager;
use Throwable;

class DoctorCommand extends Command
{
    protected $signature = 'translator:doctor
                            {--ping : Attempt a live translation through each translator}';

    protected $description = 'Check that the configured translators are set up correctly';

    public function handle(TranslatorManager $manager): int
    {
        /** @var array<string, mixed> $translators */
        $translators = (array) config('translator.translators', []);
        $default = config('translator.default');

        $healthy = true;

        $this->line('Default translator: <comment>' . ($default ?: '(none)') . '</comment>');
        $this->line('Cache: ' . (config('translator.cache.enabled') ? 'enabled' : 'disabled'));
        $this->newLine();

        if (empty($default)) {
            $this->components->warn('No default translator is set (translator.default).');
            $healthy = false;
        } elseif (! isset($translators[$default])) {
            $this->components->warn("Default translator [{$default}] is not defined.");
            $healthy = false;
        }

        if ($translators === []) {
            $this->components->warn('No translators are configured.');

            return self::FAILURE;
        }

        $rows = [];

        foreach ($translators as $name => $config) {
            [$ok, $details] = $this->check($manager, (string) $name, $config, $translators);

            $healthy = $healthy && $ok;

            $rows[] = [
                $name,
                is_array($config) ? ($config['driver'] ?? '-') : '-',
                $ok ? 'OK' : 'ERROR',
                $details,
            ];
        }

        $this->table(['Translator', 'Driver', 'Status', 'Details'], $rows);

        if ($healthy) {
            $this->components->info('All translators look healthy.');

            return self::SUCCESS;
        }

        $this->components->error('Some translators are misconfigured.');

        return self::FAILURE;
    }

    /**
     * @param  mixed  $config
     * @param  array<string, mixed>  $all
     * @return array{0: bool, 1: string}
     */
    protected function check(TranslatorManager $manager, string $name, mixed $config, array $all): array
    {
        if (! is_array($config)) {
            return [false, 'config entry is not an array'];
        }

        $driver = $config['driver'] ?? null;

        if (empty($driver)) {
            return [false, 'missing "driver" key'];
        }

        if ($driver === 'fallback') {
            $children = is_array($config['translators'] ?? null) ? $config['translators'] : [];
            $unknown = array_diff($children, array_keys($all));

            if ($unknown !== []) {
                return [false, 'references unknown translators: ' . implode(', ', $unknown)];
            }
        }

        try {
            $translator = $manager->via($name);
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }

        if ($this->option('ping')) {
            try {
                $translator->translate('ping', 'en');
            } catch (Throwable $e) {
                return [false, 'ping failed: ' . $e->getMessage()];
            }
        }

        return [true, $this->summarize($driver, $config)];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function summarize(string $driver, array $config): string
    {
        return match ($driver) {
            'deepl', 'google' => $this->keyState($config),
            'claude' => "model={$config['model']}, " . $this->keyState($config),
            'openai' => "model={$config['model']}, base_url="
                . ($config['base_url'] ?? 'https://api.openai.com/v1') . ', ' . $this->keyState($config),
            'fallback' => '→ ' . implode(', ', $config['translators'] ?? []),
            default => 'custom driver',
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function keyState(array $config): string
    {
        return empty($config['key']) ? 'key: missing' : 'key: set';
    }
}
