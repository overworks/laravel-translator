<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Drivers;

use Minhyung\LaravelTranslator\Contracts\Translator;
use Minhyung\LaravelTranslator\Support\TranslationResult;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\PendingRequest as PendingStructuredRequest;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;
use RuntimeException;

/**
 * LLM-backed translation driver using Prism, so any Prism-supported provider
 * (OpenAI, Anthropic, Gemini, ...) can be used through one configuration.
 *
 * Single translations use a plain text completion; batch translations use
 * structured output so every input maps to exactly one output, in order.
 */
class LlmTranslator implements Translator
{
    /**
     * @param  string  $provider  Prism provider name (e.g. "openai", "anthropic").
     * @param  string  $model     Model identifier for that provider.
     * @param  array<string, mixed>  $options  Defaults: temperature, max_tokens, system_prompt.
     * @param  string  $name      Driver name reported on results (e.g. "llm", "llm:anthropic").
     */
    public function __construct(
        protected string $provider,
        protected string $model,
        protected array $options = [],
        protected string $name = 'llm',
    ) {
    }

    public function translate(
        string $text,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): TranslationResult {
        $options = array_merge($this->options, $options);

        $response = $this->applyOptions(Prism::text(), $options)
            ->using($this->provider, $this->model)
            ->withSystemPrompt($this->systemPrompt($targetLang, $sourceLang, $options))
            ->withPrompt($text)
            ->asText();

        return new TranslationResult(
            text: trim($response->text),
            targetLang: $targetLang,
            driver: $this->name,
            detectedSourceLang: $sourceLang,
        );
    }

    public function translateBatch(
        array $texts,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): array {
        if ($texts === []) {
            return [];
        }

        $options = array_merge($this->options, $options);
        $keys = array_keys($texts);
        $values = array_values($texts);

        $schema = new ObjectSchema(
            name: 'translations',
            description: 'Translations of the provided texts.',
            properties: [
                new ArraySchema(
                    name: 'translations',
                    description: 'The translated texts, one per input, in the exact same order as given.',
                    items: new StringSchema('translation', 'A single translated text'),
                ),
            ],
            requiredFields: ['translations'],
        );

        $response = $this->applyOptions(Prism::structured(), $options)
            ->using($this->provider, $this->model)
            ->withSystemPrompt($this->systemPrompt($targetLang, $sourceLang, $options))
            ->withSchema($schema)
            ->withPrompt($this->batchPrompt($values))
            ->asStructured();

        $translations = $response->structured['translations'] ?? null;

        if (! is_array($translations) || count($translations) !== count($values)) {
            throw new RuntimeException(sprintf(
                'LLM batch translation returned %s, expected %d items.',
                is_array($translations) ? count($translations) . ' items' : 'a non-array result',
                count($values),
            ));
        }

        $results = array_map(
            fn (string $translation): TranslationResult => new TranslationResult(
                text: trim($translation),
                targetLang: $targetLang,
                driver: $this->name,
                detectedSourceLang: $sourceLang,
            ),
            array_values($translations),
        );

        return array_combine($keys, $results);
    }

    /**
     * @param  PendingTextRequest|PendingStructuredRequest  $request
     * @param  array<string, mixed>  $options
     * @return PendingTextRequest|PendingStructuredRequest
     */
    protected function applyOptions(object $request, array $options): object
    {
        if (isset($options['temperature'])) {
            $request->usingTemperature($options['temperature']);
        }

        if (isset($options['max_tokens'])) {
            $request->withMaxTokens($options['max_tokens']);
        }

        if (! empty($options['provider_options']) && is_array($options['provider_options'])) {
            $request->withProviderOptions($options['provider_options']);
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function systemPrompt(string $targetLang, ?string $sourceLang, array $options): string
    {
        if (! empty($options['system_prompt'])) {
            return strtr($options['system_prompt'], [
                '{target}' => $targetLang,
                '{source}' => $sourceLang ?? 'auto-detected',
            ]);
        }

        $source = $sourceLang !== null
            ? "from {$sourceLang} "
            : '';

        return "You are a professional translator. Translate the user's text {$source}into {$targetLang}. "
            . 'Preserve the original meaning, tone, and formatting. '
            . 'Respond with ONLY the translation, without quotes, explanations, or extra text.';
    }

    /**
     * @param  array<int, string>  $texts
     */
    protected function batchPrompt(array $texts): string
    {
        $lines = ['Translate each of the following texts. '
            . 'Return them in the "translations" array in the exact same order, one entry per text.', ''];

        foreach ($texts as $i => $text) {
            $lines[] = ($i + 1) . '. ' . $text;
        }

        return implode("\n", $lines);
    }
}
