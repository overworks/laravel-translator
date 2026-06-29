<?php

declare(strict_types=1);

use Minhyung\LaravelTranslator\Contracts\Translator;
use Minhyung\LaravelTranslator\Drivers\FallbackTranslator;
use Minhyung\LaravelTranslator\Exceptions\AllTranslationDriversFailedException;
use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * A driver that always throws, recording whether it was called.
 */
function failingDriver(string $message = 'boom'): Translator
{
    return new class($message) implements Translator {
        public int $calls = 0;

        public function __construct(private string $message)
        {
        }

        public function translate(string $text, string $targetLang, ?string $sourceLang = null, array $options = []): TranslationResult
        {
            $this->calls++;
            throw new RuntimeException($this->message);
        }

        public function translateBatch(array $texts, string $targetLang, ?string $sourceLang = null, array $options = []): array
        {
            $this->calls++;
            throw new RuntimeException($this->message);
        }
    };
}

/**
 * A driver that succeeds, tagging results with the given driver name.
 */
function succeedingDriver(string $name): Translator
{
    return new class($name) implements Translator {
        public int $calls = 0;

        public function __construct(private string $name)
        {
        }

        public function translate(string $text, string $targetLang, ?string $sourceLang = null, array $options = []): TranslationResult
        {
            $this->calls++;

            return new TranslationResult(strtoupper($text), $targetLang, $this->name);
        }

        public function translateBatch(array $texts, string $targetLang, ?string $sourceLang = null, array $options = []): array
        {
            $this->calls++;

            return array_map(fn (string $t) => new TranslationResult(strtoupper($t), $targetLang, $this->name), $texts);
        }
    };
}

it('returns the first successful driver result', function () {
    $primary = succeedingDriver('deepl');
    $secondary = succeedingDriver('google');

    $result = (new FallbackTranslator(['deepl' => $primary, 'google' => $secondary]))
        ->translate('hello', 'ko');

    expect($result->text)->toBe('HELLO')
        ->and($result->driver)->toBe('deepl')
        ->and($primary->calls)->toBe(1)
        ->and($secondary->calls)->toBe(0); // never reached
});

it('falls back to the next driver when one fails', function () {
    $primary = failingDriver('deepl down');
    $secondary = succeedingDriver('google');

    $result = (new FallbackTranslator(['deepl' => $primary, 'google' => $secondary]))
        ->translate('hello', 'ko');

    expect($result->driver)->toBe('google')
        ->and($primary->calls)->toBe(1)
        ->and($secondary->calls)->toBe(1);
});

it('falls back for batch translations too', function () {
    $primary = failingDriver();
    $secondary = succeedingDriver('google');

    $results = (new FallbackTranslator(['deepl' => $primary, 'google' => $secondary]))
        ->translateBatch(['a' => 'hello', 'b' => 'world'], 'ko');

    expect($results)->toHaveKeys(['a', 'b'])
        ->and($results['a']->text)->toBe('HELLO')
        ->and($results['b']->driver)->toBe('google');
});

it('throws an aggregate exception when every driver fails', function () {
    $fallback = new FallbackTranslator([
        'deepl' => failingDriver('deepl down'),
        'google' => failingDriver('google down'),
    ]);

    try {
        $fallback->translate('hello', 'ko');
        $this->fail('Expected AllTranslationDriversFailedException');
    } catch (AllTranslationDriversFailedException $e) {
        expect($e->getErrors())->toHaveKeys(['deepl', 'google'])
            ->and($e->getMessage())->toContain('deepl down')
            ->and($e->getMessage())->toContain('google down')
            ->and($e->getPrevious()->getMessage())->toBe('google down');
    }
});
