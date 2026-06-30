<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Contracts\ManagesGlossary;
use Minhyung\LaravelTranslator\Drivers\CachingDriver;
use Minhyung\LaravelTranslator\Support\Glossary;
use Minhyung\LaravelTranslator\Support\TranslationResult;
use Minhyung\LaravelTranslator\Translator;

/**
 * A driver that records glossary calls, for testing the wrapper/decorator path.
 */
function glossaryDriver(): Driver
{
    return new class implements Driver, ManagesGlossary
    {
        /** @var array<int, string> */
        public array $calls = [];

        public function translate(string $text, string $targetLang, ?string $sourceLang = null, array $options = []): TranslationResult
        {
            return new TranslationResult($text, $targetLang, 'gloss');
        }

        public function translateBatch(array $texts, string $targetLang, ?string $sourceLang = null, array $options = []): array
        {
            return [];
        }

        public function createGlossary(string $name, string $sourceLang, string $targetLang, array $entries, array $options = []): Glossary
        {
            $this->calls[] = 'create';

            return new Glossary('id-'.$name, $name, $sourceLang, [$targetLang], 'gloss', count($entries));
        }

        public function glossaries(): array
        {
            $this->calls[] = 'list';

            return [new Glossary('id-1', 'a', 'en', ['ko'], 'gloss')];
        }

        public function glossary(string $id): Glossary
        {
            $this->calls[] = 'get';

            return new Glossary($id, 'a', 'en', ['ko'], 'gloss');
        }

        public function glossaryEntries(string $id): array
        {
            $this->calls[] = 'entries';

            return ['Hello' => '안녕'];
        }

        public function deleteGlossary(string $id): void
        {
            $this->calls[] = 'delete';
        }
    };
}

it('throws a clear error when the driver cannot manage glossaries', function () {
    $translator = new Translator('stub', stubDriver('stub'));

    expect(fn () => $translator->glossaries())
        ->toThrow(RuntimeException::class, 'The [stub] translator does not support glossaries.')
        ->and(fn () => $translator->createGlossary('g', 'en', 'ko', ['Hello' => '안녕']))
        ->toThrow(RuntimeException::class)
        ->and(fn () => $translator->deleteGlossary('g'))
        ->toThrow(RuntimeException::class);
});

it('delegates glossary management to a capable driver', function () {
    $driver = glossaryDriver();
    $translator = new Translator('gloss', $driver);

    $created = $translator->createGlossary('terms', 'en', 'ko', ['Hello' => '안녕', 'World' => '세계']);

    expect($created)->toBeInstanceOf(Glossary::class)
        ->and($created->id)->toBe('id-terms')
        ->and($created->entryCount)->toBe(2)
        ->and($translator->glossaries())->toHaveCount(1)
        ->and($translator->glossary('id-1')->id)->toBe('id-1')
        ->and($translator->glossaryEntries('id-1'))->toBe(['Hello' => '안녕']);

    $translator->deleteGlossary('id-1');

    expect($driver->calls)->toBe(['create', 'list', 'get', 'entries', 'delete']);
});

it('forwards glossary management through the caching decorator', function () {
    $driver = glossaryDriver();
    $cached = new CachingDriver($driver, new Repository(new ArrayStore()), 'gloss');
    $translator = new Translator('gloss', $cached);

    $translator->createGlossary('terms', 'en', 'ko', ['Hello' => '안녕']);

    expect($driver->calls)->toBe(['create'])
        ->and($translator->glossary('id-1')->id)->toBe('id-1');
});

it('reports unsupported glossaries through the caching decorator', function () {
    $cached = new CachingDriver(stubDriver('stub'), new Repository(new ArrayStore()), 'stub');
    $translator = new Translator('stub', $cached);

    expect(fn () => $translator->glossaries())
        ->toThrow(RuntimeException::class, 'The [stub] translator does not support glossaries.');
});
