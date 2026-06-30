<?php

declare(strict_types=1);

use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Drivers\RetryingDriver;
use Minhyung\LaravelTranslator\Support\TranslationResult;

/**
 * A driver that throws for its first $failures calls, then succeeds.
 */
function flakyDriver(int $failures): Driver
{
    return new class($failures) implements Driver
    {
        public int $calls = 0;

        public function __construct(private int $failures)
        {
        }

        public function translate(string $text, string $targetLang, ?string $sourceLang = null, array $options = []): TranslationResult
        {
            return $this->run(fn () => new TranslationResult(strtoupper($text), $targetLang, 'flaky'));
        }

        public function translateBatch(array $texts, string $targetLang, ?string $sourceLang = null, array $options = []): array
        {
            return $this->run(fn () => array_map(
                fn (string $t) => new TranslationResult(strtoupper($t), $targetLang, 'flaky'),
                $texts,
            ));
        }

        private function run(callable $success): mixed
        {
            $this->calls++;

            if ($this->calls <= $this->failures) {
                throw new RuntimeException('transient');
            }

            return $success();
        }
    };
}

it('retries until the inner driver succeeds', function () {
    $inner = flakyDriver(2); // fails twice, succeeds on the 3rd

    $result = (new RetryingDriver($inner, times: 3, sleepMs: 0))->translate('hi', 'ko');

    expect($result->text)->toBe('HI')
        ->and($inner->calls)->toBe(3);
});

it('rethrows after exhausting attempts', function () {
    $inner = flakyDriver(5);

    expect(fn () => (new RetryingDriver($inner, times: 3, sleepMs: 0))->translate('hi', 'ko'))
        ->toThrow(RuntimeException::class);

    expect($inner->calls)->toBe(3);
});

it('retries batch translations too', function () {
    $inner = flakyDriver(1);

    $results = (new RetryingDriver($inner, times: 3, sleepMs: 0))
        ->translateBatch(['a' => 'hi', 'b' => 'yo'], 'ko');

    expect($results['a']->text)->toBe('HI')
        ->and($results['b']->text)->toBe('YO')
        ->and($inner->calls)->toBe(2);
});
