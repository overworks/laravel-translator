<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Drivers;

use Aws\Translate\TranslateClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Minhyung\LaravelTranslator\Contracts\Driver;
use Minhyung\LaravelTranslator\Contracts\ListsLanguages;
use Minhyung\LaravelTranslator\Contracts\ManagesGlossary;
use Minhyung\LaravelTranslator\Support\Glossary;
use Minhyung\LaravelTranslator\Support\Language;
use Minhyung\LaravelTranslator\Support\TranslationResult;
use RuntimeException;

/**
 * Amazon Translate driver backed by the official aws/aws-sdk-php client.
 *
 * The real-time TranslateText operation handles one text per call, so batches
 * are translated by looping (preserving the caller's keys and order). Source
 * language defaults to "auto", in which case Amazon returns the detected source
 * on each result. Supported languages come from the ListLanguages operation.
 *
 * Custom terminologies are managed through the {@see ManagesGlossary} methods
 * and applied to a translate() call via the "glossary" option (or the
 * Amazon-native "terminology_names").
 */
class AmazonTranslateDriver implements Driver, ListsLanguages, ManagesGlossary
{
    /**
     * @param  HttpFactory|null  $http  HTTP client used to download terminology
     *                                  entries from the presigned URL Amazon
     *                                  returns; required only by glossaryEntries().
     */
    public function __construct(
        protected TranslateClient $client,
        protected string $name = 'amazon',
        protected ?HttpFactory $http = null,
    ) {
    }

    public function translate(
        string $text,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): TranslationResult {
        $payload = array_filter([
            'Text' => $text,
            'SourceLanguageCode' => $sourceLang ?? 'auto',
            'TargetLanguageCode' => $targetLang,
            'Settings' => $this->settings($options),
            'TerminologyNames' => $this->terminologyNames($options),
        ], fn ($value): bool => $value !== null && $value !== []);

        $result = $this->client->translateText($payload);

        return new TranslationResult(
            text: (string) $result->get('TranslatedText'),
            targetLang: $targetLang,
            translator: $this->name,
            detectedSourceLang: $sourceLang ?? $result->get('SourceLanguageCode'),
        );
    }

    public function translateBatch(
        array $texts,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): array {
        return array_map(
            fn (string $text): TranslationResult => $this->translate($text, $targetLang, $sourceLang, $options),
            $texts,
        );
    }

    public function languages(): array
    {
        $languages = [];
        $token = null;

        // ListLanguages paginates; follow NextToken until the list is exhausted.
        do {
            $result = $this->client->listLanguages(array_filter([
                'DisplayLanguageCode' => 'en',
                'NextToken' => $token,
            ], fn ($value): bool => $value !== null));

            foreach ($result->get('Languages') ?? [] as $language) {
                $languages[] = new Language(
                    code: $language['LanguageCode'] ?? '',
                    name: $language['LanguageName'] ?? null,
                );
            }

            $token = $result->get('NextToken');
        } while (! empty($token));

        return $languages;
    }

    public function createGlossary(
        string $name,
        string $sourceLang,
        string $targetLang,
        array $entries,
        array $options = []
    ): Glossary {
        $result = $this->client->importTerminology(array_filter([
            'Name' => $name,
            'MergeStrategy' => $options['merge_strategy'] ?? 'OVERWRITE',
            'Description' => $options['description'] ?? null,
            'TerminologyData' => [
                'File' => $this->toCsv($sourceLang, $targetLang, $entries),
                'Format' => 'CSV',
                'Directionality' => $options['directionality'] ?? 'UNI',
            ],
        ], fn ($value): bool => $value !== null));

        return $this->toGlossary($result->get('TerminologyProperties') ?? []);
    }

    public function glossaries(): array
    {
        $glossaries = [];
        $token = null;

        // ListTerminologies paginates; follow NextToken until the list is exhausted.
        do {
            $result = $this->client->listTerminologies(array_filter([
                'NextToken' => $token,
            ], fn ($value): bool => $value !== null));

            foreach ($result->get('TerminologyPropertiesList') ?? [] as $properties) {
                $glossaries[] = $this->toGlossary($properties);
            }

            $token = $result->get('NextToken');
        } while (! empty($token));

        return $glossaries;
    }

    public function glossary(string $id): Glossary
    {
        $result = $this->client->getTerminology(['Name' => $id]);

        return $this->toGlossary($result->get('TerminologyProperties') ?? []);
    }

    public function glossaryEntries(string $id): array
    {
        if ($this->http === null) {
            throw new RuntimeException(
                'Reading Amazon terminology entries requires an HTTP client; none was provided to the driver.'
            );
        }

        // GetTerminology hands back a presigned URL to the term file rather than
        // the entries inline; download and parse it into a source => target map.
        $result = $this->client->getTerminology(['Name' => $id, 'TerminologyDataFormat' => 'CSV']);
        $location = $result->get('TerminologyDataLocation')['Location'] ?? null;

        if (empty($location)) {
            return [];
        }

        return $this->parseCsv($this->http->get($location)->body());
    }

    public function deleteGlossary(string $id): void
    {
        $this->client->deleteTerminology(['Name' => $id]);
    }

    /**
     * Resolve the terminology names to apply, accepting either the portable
     * "glossary" option or the Amazon-native "terminology_names".
     *
     * @param  array<string, mixed>  $options
     * @return array<int, string>|null
     */
    protected function terminologyNames(array $options): ?array
    {
        $names = $options['terminology_names'] ?? $options['glossary'] ?? null;

        if ($names === null) {
            return null;
        }

        return array_values(array_map('strval', (array) $names));
    }

    /**
     * Map an Amazon TerminologyProperties shape onto our Glossary DTO.
     *
     * @param  array<string, mixed>  $properties
     */
    protected function toGlossary(array $properties): Glossary
    {
        return new Glossary(
            id: (string) ($properties['Name'] ?? ''),
            name: (string) ($properties['Name'] ?? ''),
            sourceLang: (string) ($properties['SourceLanguageCode'] ?? ''),
            targetLangs: array_values($properties['TargetLanguageCodes'] ?? []),
            translator: $this->name,
            entryCount: isset($properties['TermCount']) ? (int) $properties['TermCount'] : null,
        );
    }

    /**
     * Build an Amazon CSV terminology file: a header row of language codes
     * followed by one source,target row per entry.
     *
     * @param  array<string, string>  $entries
     */
    protected function toCsv(string $sourceLang, string $targetLang, array $entries): string
    {
        $stream = fopen('php://temp', 'r+');

        fputcsv($stream, [$sourceLang, $targetLang]);

        foreach ($entries as $source => $target) {
            fputcsv($stream, [(string) $source, $target]);
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /**
     * Parse an Amazon CSV terminology file (language-code header, then
     * source,target rows) into a source => target map.
     *
     * @return array<string, string>
     */
    protected function parseCsv(string $csv): array
    {
        $rows = array_map('str_getcsv', preg_split('/\r\n|\r|\n/', trim($csv)) ?: []);

        // Drop the language-code header row.
        array_shift($rows);

        $entries = [];

        foreach ($rows as $row) {
            if (isset($row[0], $row[1]) && $row[0] !== '') {
                $entries[$row[0]] = $row[1];
            }
        }

        return $entries;
    }

    /**
     * Map our generic options onto Amazon Translate's Settings shape.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function settings(array $options): array
    {
        return array_filter([
            'Formality' => $options['formality'] ?? null,
            'Profanity' => $options['profanity'] ?? null,
        ], fn ($value): bool => $value !== null);
    }
}
