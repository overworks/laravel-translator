<?php

declare(strict_types=1);

use DeepL\DeepLClient;
use DeepL\GlossaryEntries;
use DeepL\GlossaryInfo;
use Minhyung\LaravelTranslator\Drivers\DeeplDriver;
use Minhyung\LaravelTranslator\Support\Glossary;

function deeplGlossaryInfo(string $id = 'gloss-1'): GlossaryInfo
{
    return new GlossaryInfo($id, 'My Glossary', true, 'en', 'ko', new DateTime(), 2);
}

it('creates a glossary from an entry map', function () {
    $client = Mockery::mock(DeepLClient::class);
    $client->shouldReceive('createGlossary')
        ->once()
        ->with(
            'My Glossary',
            'en',
            'ko',
            Mockery::on(fn ($entries) => $entries instanceof GlossaryEntries
                && $entries->getEntries() === ['Hello' => '안녕']),
        )
        ->andReturn(deeplGlossaryInfo());

    $glossary = (new DeeplDriver($client))->createGlossary('My Glossary', 'en', 'ko', ['Hello' => '안녕']);

    expect($glossary)->toBeInstanceOf(Glossary::class)
        ->and($glossary->id)->toBe('gloss-1')
        ->and($glossary->name)->toBe('My Glossary')
        ->and($glossary->sourceLang)->toBe('en')
        ->and($glossary->targetLangs)->toBe(['ko'])
        ->and($glossary->translator)->toBe('deepl')
        ->and($glossary->entryCount)->toBe(2)
        ->and($glossary->ready)->toBeTrue();
});

it('lists glossaries', function () {
    $client = Mockery::mock(DeepLClient::class);
    $client->shouldReceive('listGlossaries')
        ->once()
        ->andReturn([deeplGlossaryInfo('a'), deeplGlossaryInfo('b')]);

    $glossaries = (new DeeplDriver($client))->glossaries();

    expect($glossaries)->toHaveCount(2)
        ->and($glossaries[0]->id)->toBe('a')
        ->and($glossaries[1]->id)->toBe('b');
});

it('fetches a single glossary', function () {
    $client = Mockery::mock(DeepLClient::class);
    $client->shouldReceive('getGlossary')
        ->once()
        ->with('gloss-1')
        ->andReturn(deeplGlossaryInfo());

    expect((new DeeplDriver($client))->glossary('gloss-1')->name)->toBe('My Glossary');
});

it('reads glossary entries as a source => target map', function () {
    $client = Mockery::mock(DeepLClient::class);
    $client->shouldReceive('getGlossaryEntries')
        ->once()
        ->with('gloss-1')
        ->andReturn(GlossaryEntries::fromEntries(['Hello' => '안녕', 'World' => '세계']));

    expect((new DeeplDriver($client))->glossaryEntries('gloss-1'))
        ->toBe(['Hello' => '안녕', 'World' => '세계']);
});

it('deletes a glossary', function () {
    $client = Mockery::mock(DeepLClient::class);
    $client->shouldReceive('deleteGlossary')->once()->with('gloss-1');

    (new DeeplDriver($client))->deleteGlossary('gloss-1');
});
