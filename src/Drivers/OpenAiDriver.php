<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Drivers;

use Minhyung\LaravelTranslator\Contracts\Translator;
use Minhyung\LaravelTranslator\Support\TranslationResult;
use OpenAI\Contracts\ClientContract;
use RuntimeException;

/**
 * Translation driver for any OpenAI-compatible chat completions API, talking to
 * the endpoint directly through openai-php/client.
 *
 * This powers both well-known providers (OpenAI, DeepSeek, Gemini, Groq, ...)
 * and arbitrary endpoints — self-hosted gateways, proxies, or vendors that
 * expose the OpenAI schema. Point it at a base URI and an API key in config.
 *
 * Single translations use a plain chat completion; batch translations request a
 * JSON object so every input maps to exactly one output, in order.
 */
class OpenAiDriver implements Translator
{
    /**
     * @param  ClientContract  $client  Configured with the endpoint's base URI and key.
     * @param  string  $model    Model identifier the endpoint exposes.
     * @param  array<string, mixed>  $options  Defaults: temperature, max_tokens, system_prompt.
     * @param  string  $name     Translator name reported on results.
     */
    public function __construct(
        protected ClientContract $client,
        protected string $model,
        protected array $options = [],
        protected string $name = 'openai',
    ) {
    }

    public function translate(
        string $text,
        string $targetLang,
        ?string $sourceLang = null,
        array $options = []
    ): TranslationResult {
        $options = array_merge($this->options, $options);

        $response = $this->client->chat()->create($this->payload(
            messages: [
                ['role' => 'system', 'content' => $this->systemPrompt($targetLang, $sourceLang, $options)],
                ['role' => 'user', 'content' => $text],
            ],
            options: $options,
        ));

        return new TranslationResult(
            text: trim((string) ($response->choices[0]->message->content ?? '')),
            targetLang: $targetLang,
            translator: $this->name,
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

        $response = $this->client->chat()->create($this->payload(
            messages: [
                ['role' => 'system', 'content' => $this->systemPrompt($targetLang, $sourceLang, $options)],
                ['role' => 'user', 'content' => $this->batchPrompt($values)],
            ],
            options: $options,
            json: true,
        ));

        $translations = $this->decodeBatch(
            (string) ($response->choices[0]->message->content ?? ''),
            count($values),
        );

        $results = array_map(
            fn (string $translation): TranslationResult => new TranslationResult(
                text: trim($translation),
                targetLang: $targetLang,
                translator: $this->name,
                detectedSourceLang: $sourceLang,
            ),
            $translations,
        );

        return array_combine($keys, $results);
    }

    /**
     * Build the chat completion request payload.
     *
     * @param  array<int, array<string, string>>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function payload(array $messages, array $options, bool $json = false): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
        ];

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }

        if (isset($options['max_tokens'])) {
            $payload['max_tokens'] = $options['max_tokens'];
        }

        if ($json) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        return $payload;
    }

    /**
     * Decode the JSON batch response into an ordered list of translations.
     *
     * @return array<int, string>
     */
    protected function decodeBatch(string $content, int $expected): array
    {
        $decoded = json_decode($content, true);
        $translations = is_array($decoded) ? ($decoded['translations'] ?? null) : null;

        if (! is_array($translations) || count($translations) !== $expected) {
            throw new RuntimeException(sprintf(
                'OpenAI-compatible batch translation returned %s, expected %d items.',
                is_array($translations) ? count($translations) . ' items' : 'a non-array result',
                $expected,
            ));
        }

        return array_map(static fn ($t): string => (string) $t, array_values($translations));
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
        $lines = ['Translate each of the following texts. Respond with a JSON object of the form '
            . '{"translations": [...]}, where the array holds one translation per text in the exact '
            . 'same order, and nothing else.', ''];

        foreach ($texts as $i => $text) {
            $lines[] = ($i + 1) . '. ' . $text;
        }

        return implode("\n", $lines);
    }
}
