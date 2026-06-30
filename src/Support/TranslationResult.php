<?php

declare(strict_types=1);

namespace Minhyung\LaravelTranslator\Support;

use Stringable;

/**
 * Normalized, immutable result of a single text translation.
 *
 * Each driver maps its provider-specific response into this shape so callers
 * get a consistent object regardless of which translator was used.
 */
final readonly class TranslationResult implements Stringable
{
    public function __construct(
        public string $text,
        public string $targetLang,
        public string $translator,
        public ?string $detectedSourceLang = null,
        public ?int $billedCharacters = null,
    ) {
    }

    /**
     * @param array{
     *     text: string,
     *     targetLang: string,
     *     translator: string,
     *     detectedSourceLang?: string|null,
     *     billedCharacters?: int|null
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            text: $data['text'],
            targetLang: $data['targetLang'],
            translator: $data['translator'],
            detectedSourceLang: $data['detectedSourceLang'] ?? null,
            billedCharacters: $data['billedCharacters'] ?? null,
        );
    }

    /**
     * @return array{
     *     text: string,
     *     targetLang: string,
     *     translator: string,
     *     detectedSourceLang: string|null,
     *     billedCharacters: int|null
     * }
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'targetLang' => $this->targetLang,
            'translator' => $this->translator,
            'detectedSourceLang' => $this->detectedSourceLang,
            'billedCharacters' => $this->billedCharacters,
        ];
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
