<?php

namespace App\DTO;

use InvalidArgumentException;

/**
 * Resultado estruturado e validado da adequação de currículo (F6).
 *
 * Falhas estruturais (tipos errados, limites, HTML) lançam
 * InvalidArgumentException — o ResumeAdaptationService as converte em
 * erro legível para a UI, sem persistir nada.
 */
final class ResumeAdaptationResult
{
    public const MAX_ADAPTED_LENGTH = 20000;

    public const MAX_ITEMS_PER_LIST = 50;

    public const MAX_ITEM_LENGTH = 200;

    /**
     * @param  array<int, string>  $changes
     * @param  array<int, string>  $coveredKeywords
     * @param  array<int, string>  $missingRequirements
     * @param  array<int, string>  $alerts
     */
    public function __construct(
        public readonly string $adaptedResume,
        public readonly array $changes,
        public readonly array $coveredKeywords,
        public readonly array $missingRequirements,
        public readonly array $alerts,
    ) {}

    /**
     * Build a validated result from the agent's structured output.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromStructured(array $data): self
    {
        return new self(
            self::adaptedResume($data['adapted_resume'] ?? null),
            self::stringList($data['changes'] ?? [], 'changes'),
            self::stringList($data['covered_keywords'] ?? [], 'covered_keywords'),
            self::stringList($data['missing_requirements'] ?? [], 'missing_requirements'),
            self::stringList($data['alerts'] ?? [], 'alerts'),
        );
    }

    /**
     * Array shape stored in resume_adaptations.
     *
     * @return array{adapted_resume: string, changes: array<int, string>, covered_keywords: array<int, string>, missing_requirements: array<int, string>, alerts: array<int, string>}
     */
    public function toArray(): array
    {
        return [
            'adapted_resume' => $this->adaptedResume,
            'changes' => $this->changes,
            'covered_keywords' => $this->coveredKeywords,
            'missing_requirements' => $this->missingRequirements,
            'alerts' => $this->alerts,
        ];
    }

    private static function adaptedResume(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Campo "adapted_resume" deve ser uma string não vazia.');
        }

        $text = trim($value);

        if (mb_strlen($text) > self::MAX_ADAPTED_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'O currículo adaptado excede o limite de %d caracteres.',
                self::MAX_ADAPTED_LENGTH,
            ));
        }

        if (preg_match('/<\/?[a-z][a-z0-9]*\b[^>]*>/i', $text)) {
            throw new InvalidArgumentException('O currículo adaptado não pode conter HTML.');
        }

        return $text;
    }

    /**
     * @return array<int, string>
     */
    private static function stringList(mixed $value, string $field): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("Campo \"{$field}\" deve ser uma lista de strings.");
        }

        $items = [];

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                throw new InvalidArgumentException("Campo \"{$field}\" deve conter apenas strings.");
            }

            $item = trim($entry);

            if ($item === '' || mb_strlen($item) > self::MAX_ITEM_LENGTH) {
                continue;
            }

            if (in_array($item, $items, true)) {
                continue;
            }

            if (count($items) >= self::MAX_ITEMS_PER_LIST) {
                break;
            }

            $items[] = $item;
        }

        return $items;
    }
}
