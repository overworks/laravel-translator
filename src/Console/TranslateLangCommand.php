<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Minhyung\LaravelTranslator\Localization\LangFileTranslator;
use Minhyung\LaravelTranslator\TranslatorManager;
use Throwable;

class TranslateLangCommand extends Command
{
    protected $signature = 'translator:lang
                            {locale* : Target locale(s) to generate}
                            {--source=en : Source locale to translate from}
                            {--via= : Translator to use (the default when omitted)}
                            {--overwrite : Re-translate keys that already exist in the target}';

    protected $description = 'Translate Laravel localization files into other locales';

    public function handle(TranslatorManager $manager): int
    {
        $service = new LangFileTranslator($manager->via($this->option('via') ?: null));

        $source = (string) $this->option('source');
        $overwrite = (bool) $this->option('overwrite');
        $langPath = $this->laravel->langPath();

        if (! is_dir("{$langPath}/{$source}") && ! is_file("{$langPath}/{$source}.json")) {
            $this->components->error("No source locale [{$source}] found in {$langPath}.");

            return self::FAILURE;
        }

        foreach ($this->argument('locale') as $target) {
            if ($target === $source) {
                continue;
            }

            try {
                $count = $this->translateLocale($service, $langPath, $source, (string) $target, $overwrite);
            } catch (Throwable $e) {
                $this->components->error("[{$target}] {$e->getMessage()}");

                return self::FAILURE;
            }

            $this->components->info("[{$target}] translated {$count} string(s).");
        }

        return self::SUCCESS;
    }

    protected function translateLocale(
        LangFileTranslator $service,
        string $langPath,
        string $source,
        string $target,
        bool $overwrite,
    ): int {
        $count = 0;

        foreach (glob("{$langPath}/{$source}/*.php") ?: [] as $file) {
            $group = basename($file, '.php');
            $sourceLines = Arr::dot((array) require $file);
            $targetFile = "{$langPath}/{$target}/{$group}.php";
            $targetArray = is_file($targetFile) ? (array) require $targetFile : [];

            $needed = $this->missingLines($sourceLines, $targetArray, $overwrite);

            if ($needed === []) {
                continue;
            }

            foreach ($service->translateLines($needed, $target, $source) as $key => $value) {
                Arr::set($targetArray, $key, $value);
            }

            $this->ensureDirectory(dirname($targetFile));
            file_put_contents($targetFile, $this->toPhp($targetArray));
            $count += count($needed);
        }

        $count += $this->translateJson($service, $langPath, $source, $target, $overwrite);

        return $count;
    }

    protected function translateJson(
        LangFileTranslator $service,
        string $langPath,
        string $source,
        string $target,
        bool $overwrite,
    ): int {
        $sourceFile = "{$langPath}/{$source}.json";

        if (! is_file($sourceFile)) {
            return 0;
        }

        $sourceLines = (array) json_decode((string) file_get_contents($sourceFile), true);
        $targetFile = "{$langPath}/{$target}.json";
        $targetLines = is_file($targetFile)
            ? (array) json_decode((string) file_get_contents($targetFile), true)
            : [];

        $needed = $this->missingLines($sourceLines, $targetLines, $overwrite, flatKeys: true);

        if ($needed === []) {
            return 0;
        }

        $targetLines = array_merge($targetLines, $service->translateLines($needed, $target, $source));

        $this->ensureDirectory(dirname($targetFile));
        file_put_contents(
            $targetFile,
            json_encode($targetLines, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        );

        return count($needed);
    }

    /**
     * Keep only string source lines that are missing (or all, when overwriting).
     *
     * @param  array<array-key, mixed>  $sourceLines  Flat source lines (dotted for PHP groups).
     * @param  array<array-key, mixed>  $target       Existing target (nested for PHP, flat for JSON).
     * @return array<array-key, string>
     */
    protected function missingLines(array $sourceLines, array $target, bool $overwrite, bool $flatKeys = false): array
    {
        $needed = [];

        foreach ($sourceLines as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            $existing = $flatKeys ? ($target[$key] ?? null) : Arr::get($target, $key);

            if (! $overwrite && filled($existing)) {
                continue;
            }

            $needed[$key] = $value;
        }

        return $needed;
    }

    protected function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function toPhp(array $data): string
    {
        return "<?php\n\nreturn [\n" . $this->export($data) . "];\n";
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function export(array $data, int $depth = 1): string
    {
        $pad = str_repeat('    ', $depth);
        $isList = array_is_list($data);
        $out = '';

        foreach ($data as $key => $value) {
            $keyPart = $isList ? '' : var_export((string) $key, true) . ' => ';

            $out .= is_array($value)
                ? "{$pad}{$keyPart}[\n" . $this->export($value, $depth + 1) . "{$pad}],\n"
                : "{$pad}{$keyPart}" . var_export($value, true) . ",\n";
        }

        return $out;
    }
}
