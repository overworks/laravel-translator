<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Drivers;

use Anthropic\Contracts\ClientContract;
use Anthropic\Responses\Messages\CreateResponse;
use Minhyung\LaravelTranslator\Contracts\Translator;
use Minhyung\LaravelTranslator\Support\TranslationResult;
use RuntimeException;

/**
 * Native Claude translation driver talking to the Anthropic Messages API
 * directly through mozex/anthropic-php.
 *
 * Single translations use a plain message; batch translations instruct the
 * model to return a JSON object so every input maps to exactly one output,
 * in order (the Messages API has no native JSON mode).
 */
class AnthropicTranslator implements Translator
{
    /**
     * Default token ceiling for a response when none is given in options.
     */
    protected const DEFAULT_MAX_TOKENS = 4096;

    /**
     * @param  ClientContract  $client  Configured with an Anthropic API key.
     * @param  string  $model    Claude model identifier.
     * @param  array<string, mixed>  $options  Defaults: temperature, max_tokens, system_prompt.
     * @param  string  $name     Driver name reported on results.
     */
    public function __construct(
        protected ClientContract $client,
        protected string $model,
        protected array $options = [],
        protected string $name = 'anthropic',
    ) {
    }

    public function translate(
        string $text,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): TranslationResult {
        $options = array_merge($this->options, $options);

        $response = $this->client->messages()->create($this->payload(
            system: $this->systemPrompt($targetLang, $sourceLang, $options),
            content: $text,
            options: $options,
        ));

        return new TranslationResult(
            text: trim($this->textFrom($response)),
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

        $response = $this->client->messages()->create($this->payload(
            system: $this->systemPrompt($targetLang, $sourceLang, $options),
            content: $this->batchPrompt($values),
            options: $options,
        ));

        $translations = $this->decodeBatch($this->textFrom($response), count($values));

        $results = array_map(
            fn (string $translation): TranslationResult => new TranslationResult(
                text: trim($translation),
                targetLang: $targetLang,
                driver: $this->name,
                detectedSourceLang: $sourceLang,
            ),
            $translations,
        );

        return array_combine($keys, $results);
    }

    /**
     * Build the Messages API request payload.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function payload(string $system, string $content, array $options): array
    {
        $payload = [
            'model' => $this->model,
            'max_tokens' => $options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS,
            'system' => $system,
            'messages' => [
                ['role' => 'user', 'content' => $content],
            ],
        ];

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }

        return $payload;
    }

    /**
     * Pull the concatenated text from the response's text content blocks.
     */
    protected function textFrom(CreateResponse $response): string
    {
        $text = '';

        foreach ($response->content as $block) {
            if (($block->type ?? null) === 'text') {
                $text .= $block->text ?? '';
            }
        }

        return $text;
    }

    /**
     * Decode the JSON batch response into an ordered list of translations.
     *
     * @return array<int, string>
     */
    protected function decodeBatch(string $content, int $expected): array
    {
        $decoded = json_decode($this->stripCodeFence($content), true);
        $translations = is_array($decoded) ? ($decoded['translations'] ?? null) : null;

        if (! is_array($translations) || count($translations) !== $expected) {
            throw new RuntimeException(sprintf(
                'Anthropic batch translation returned %s, expected %d items.',
                is_array($translations) ? count($translations) . ' items' : 'a non-array result',
                $expected,
            ));
        }

        return array_map(static fn ($t): string => (string) $t, array_values($translations));
    }

    /**
     * Strip a Markdown code fence the model may wrap JSON in.
     */
    protected function stripCodeFence(string $content): string
    {
        $content = trim($content);

        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;
        }

        return $content;
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
        $lines = ['Translate each of the following texts. Respond with ONLY a JSON object of the form '
            . '{"translations": [...]}, where the array holds one translation per text in the exact '
            . 'same order, and nothing else.', ''];

        foreach ($texts as $i => $text) {
            $lines[] = ($i + 1) . '. ' . $text;
        }

        return implode("\n", $lines);
    }
}
