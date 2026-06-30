<?php

declare(strict_types=1);

use Aws\MockHandler;
use Aws\Result;
use Aws\Translate\TranslateClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Minhyung\LaravelTranslator\Drivers\AmazonTranslateDriver;
use Minhyung\LaravelTranslator\Support\Glossary;

/**
 * A TranslateClient that replays the given queued results (no network).
 *
 * @param  array<int, Result>  $results
 */
function amazonGlossaryClient(array $results): TranslateClient
{
    $handler = new MockHandler();

    foreach ($results as $result) {
        $handler->append($result);
    }

    return new TranslateClient([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => ['key' => 'k', 'secret' => 's'],
        'handler' => $handler,
    ]);
}

/**
 * A TranslateClient whose handler is the given callback, so a test can inspect
 * the outgoing command and return a synthetic Result.
 */
function amazonGlossaryCapture(callable $handler): TranslateClient
{
    $mock = new MockHandler();
    $mock->append($handler);

    return new TranslateClient([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => ['key' => 'k', 'secret' => 's'],
        'handler' => $mock,
    ]);
}

it('imports a terminology, sending a CSV with a language header', function () {
    $captured = null;

    $client = amazonGlossaryCapture(function ($command) use (&$captured) {
        $captured = $command->toArray();

        return new Result([
            'TerminologyProperties' => [
                'Name' => 'glossary-a',
                'SourceLanguageCode' => 'en',
                'TargetLanguageCodes' => ['ko'],
                'TermCount' => 2,
            ],
        ]);
    });

    $glossary = (new AmazonTranslateDriver($client))
        ->createGlossary('glossary-a', 'en', 'ko', ['Hello' => '안녕', 'World' => '세계']);

    expect($glossary)->toBeInstanceOf(Glossary::class)
        ->and($glossary->id)->toBe('glossary-a')
        ->and($glossary->sourceLang)->toBe('en')
        ->and($glossary->targetLangs)->toBe(['ko'])
        ->and($glossary->entryCount)->toBe(2)
        ->and($captured['Name'])->toBe('glossary-a')
        ->and($captured['MergeStrategy'])->toBe('OVERWRITE')
        ->and($captured['TerminologyData']['Format'])->toBe('CSV')
        ->and($captured['TerminologyData']['File'])->toContain("en,ko")
        ->and($captured['TerminologyData']['File'])->toContain('Hello,안녕')
        ->and($captured['TerminologyData']['File'])->toContain('World,세계');
});

it('lists terminologies, following pagination', function () {
    $client = amazonGlossaryClient([
        new Result([
            'TerminologyPropertiesList' => [
                ['Name' => 'a', 'SourceLanguageCode' => 'en', 'TargetLanguageCodes' => ['ko']],
            ],
            'NextToken' => 'page-2',
        ]),
        new Result([
            'TerminologyPropertiesList' => [
                ['Name' => 'b', 'SourceLanguageCode' => 'en', 'TargetLanguageCodes' => ['ja', 'fr']],
            ],
        ]),
    ]);

    $glossaries = (new AmazonTranslateDriver($client))->glossaries();

    expect($glossaries)->toHaveCount(2)
        ->and($glossaries[0]->id)->toBe('a')
        ->and($glossaries[1]->id)->toBe('b')
        ->and($glossaries[1]->targetLangs)->toBe(['ja', 'fr']);
});

it('fetches a single terminology', function () {
    $client = amazonGlossaryClient([
        new Result([
            'TerminologyProperties' => [
                'Name' => 'glossary-a',
                'SourceLanguageCode' => 'en',
                'TargetLanguageCodes' => ['ko'],
                'TermCount' => 5,
            ],
        ]),
    ]);

    $glossary = (new AmazonTranslateDriver($client))->glossary('glossary-a');

    expect($glossary->name)->toBe('glossary-a')
        ->and($glossary->entryCount)->toBe(5);
});

it('downloads and parses terminology entries from the presigned URL', function () {
    $client = amazonGlossaryClient([
        new Result([
            'TerminologyDataLocation' => [
                'RepositoryType' => 'S3',
                'Location' => 'https://s3.example/terminology.csv',
            ],
        ]),
    ]);

    $http = new HttpFactory();
    $http->fake([
        'https://s3.example/*' => HttpFactory::response("en,ko\nHello,안녕\nWorld,세계\n"),
    ]);

    $entries = (new AmazonTranslateDriver($client, 'amazon', $http))->glossaryEntries('glossary-a');

    expect($entries)->toBe(['Hello' => '안녕', 'World' => '세계']);
});

it('throws when reading entries without an HTTP client', function () {
    $client = amazonGlossaryClient([]);

    expect(fn () => (new AmazonTranslateDriver($client))->glossaryEntries('glossary-a'))
        ->toThrow(RuntimeException::class);
});

it('deletes a terminology', function () {
    $captured = null;

    $client = amazonGlossaryCapture(function ($command) use (&$captured) {
        $captured = $command->toArray();

        return new Result([]);
    });

    (new AmazonTranslateDriver($client))->deleteGlossary('glossary-a');

    expect($captured['Name'])->toBe('glossary-a');
});

it('applies the portable glossary option as a terminology name', function () {
    $captured = null;

    $client = amazonGlossaryCapture(function ($command) use (&$captured) {
        $captured = $command->toArray();

        return new Result(['TranslatedText' => '안녕', 'SourceLanguageCode' => 'en']);
    });

    (new AmazonTranslateDriver($client))->translate('Hello', 'ko', 'en', ['glossary' => 'glossary-a']);

    expect($captured['TerminologyNames'])->toBe(['glossary-a']);
});
