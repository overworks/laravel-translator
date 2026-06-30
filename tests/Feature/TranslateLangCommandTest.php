<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Support\TranslationResult;
use Minhyung\LaravelTranslator\TranslatorManager;

/**
 * A driver that wraps each input in brackets (preserving placeholder tokens).
 */
function bracketDriver(): Driver
{
    return new class implements Driver
    {
        public function translate(string $text, string $targetLang, ?string $sourceLang = null, array $options = []): TranslationResult
        {
            return new TranslationResult('[' . $text . ']', $targetLang, 'wrap');
        }

        public function translateBatch(array $texts, string $targetLang, ?string $sourceLang = null, array $options = []): array
        {
            return array_map(fn (string $t) => new TranslationResult('[' . $t . ']', $targetLang, 'wrap'), $texts);
        }
    };
}

beforeEach(function () {
    $this->lang = sys_get_temp_dir() . '/lt-lang-' . uniqid();
    File::ensureDirectoryExists($this->lang . '/en');
    File::put($this->lang . '/en/auth.php', "<?php\n\nreturn ['failed' => 'Bad creds :name', 'nested' => ['hi' => 'Hello']];\n");
    File::put($this->lang . '/en.json', json_encode(['Welcome' => 'Welcome']));

    $this->app->useLangPath($this->lang);

    config()->set('translator.cache.enabled', false);
    config()->set('translator.default', 'wrap');
    config()->set('translator.translators.wrap', ['driver' => 'wrap']);
    app(TranslatorManager::class)->extend('wrap', fn () => bracketDriver());
});

afterEach(function () {
    File::deleteDirectory($this->lang);
});

it('translates php groups (nested + placeholders) and the json file', function () {
    $this->artisan('translator:lang', ['locale' => ['ko']])->assertSuccessful();

    $auth = require $this->lang . '/ko/auth.php';
    expect($auth['failed'])->toBe('[Bad creds :name]')
        ->and($auth['nested']['hi'])->toBe('[Hello]');

    $json = json_decode(File::get($this->lang . '/ko.json'), true);
    expect($json['Welcome'])->toBe('[Welcome]');
});

it('only fills missing keys by default', function () {
    File::ensureDirectoryExists($this->lang . '/ko');
    File::put($this->lang . '/ko/auth.php', "<?php\n\nreturn ['failed' => '이미 번역됨'];\n");

    $this->artisan('translator:lang', ['locale' => ['ko']])->assertSuccessful();

    $auth = require $this->lang . '/ko/auth.php';
    expect($auth['failed'])->toBe('이미 번역됨')        // kept
        ->and($auth['nested']['hi'])->toBe('[Hello]');  // added
});

it('re-translates everything with --overwrite', function () {
    File::ensureDirectoryExists($this->lang . '/ko');
    File::put($this->lang . '/ko/auth.php', "<?php\n\nreturn ['failed' => '이미 번역됨'];\n");

    $this->artisan('translator:lang', ['locale' => ['ko'], '--overwrite' => true])->assertSuccessful();

    $auth = require $this->lang . '/ko/auth.php';
    expect($auth['failed'])->toBe('[Bad creds :name]');
});

it('fails when the source locale is missing', function () {
    $this->artisan('translator:lang', ['locale' => ['ko'], '--source' => 'zz'])->assertFailed();
});
