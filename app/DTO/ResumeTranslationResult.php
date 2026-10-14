<?php

namespace App\DTO;

use InvalidArgumentException;

/**
 * Resultado estruturado e validado da tradução do currículo (T2).
 *
 * Falhas estruturais (tipo errado, vazio, HTML, tamanho) lançam
 * InvalidArgumentException — o ResumeTranslationService as converte em
 * erro legível para a UI, sem persistir nada.
 */
final class ResumeTranslationResult
{
    public const MAX_TRANSLATED_LENGTH = 20000;

    public function __construct(
        public readonly string $translatedResume,
    ) {}

    /**
     * Build a validated result from the agent's structured output.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromStructured(array $data): self
    {
        return new self(
            self::translatedResume($data['translated_resume'] ?? null),
        );
    }

    private static function translatedResume(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Campo "translated_resume" deve ser uma string não vazia.');
        }

        $text = trim($value);

        if (mb_strlen($text) > self::MAX_TRANSLATED_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'O currículo traduzido excede o limite de %d caracteres.',
                self::MAX_TRANSLATED_LENGTH,
            ));
        }

        if (preg_match('/<\/?[a-z][a-z0-9]*\b[^>]*>/i', $text)) {
            throw new InvalidArgumentException('O currículo traduzido não pode conter HTML.');
        }

        return $text;
    }
}
