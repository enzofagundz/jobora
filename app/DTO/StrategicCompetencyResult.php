<?php

namespace App\DTO;

use App\Enums\StrategicRole;
use App\Services\ConceptNormalizer;
use InvalidArgumentException;

/**
 * Resultado estruturado e validado da classificação estratégica da IA.
 *
 * Falhas estruturais (tipos errados, papel inválido) lançam
 * InvalidArgumentException — o StrategicCompetencyService as converte em
 * erro legível para a UI, sem persistir nada. Conteúdo ruidoso (nomes
 * vazios/longos, duplicados) é limpo silenciosamente.
 */
final class StrategicCompetencyResult
{
    public const MAX_ITEMS = 100;

    public const MAX_NAME_LENGTH = 100;

    /**
     * @param  array<int, array{concept: string, strategic_role: StrategicRole}>  $competencies
     */
    public function __construct(
        public readonly array $competencies,
    ) {}

    /**
     * Build a validated result from the agent's structured output.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromStructured(array $data, ConceptNormalizer $normalizer): self
    {
        $raw = $data['competencies'] ?? null;

        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new InvalidArgumentException('Campo "competencies" deve ser uma lista.');
        }

        $competencies = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException('Cada competência deve ser um objeto com "concept" e "strategic_role".');
            }

            $conceptValue = $entry['concept'] ?? null;

            if (! is_string($conceptValue)) {
                throw new InvalidArgumentException('Campo "concept" deve ser uma string.');
            }

            $roleValue = $entry['strategic_role'] ?? null;

            if (! is_string($roleValue) || StrategicRole::tryFrom($roleValue) === null) {
                throw new InvalidArgumentException('Campo "strategic_role" deve ser "core", "expansion" ou "differentiation".');
            }

            $concept = $normalizer->normalize($conceptValue);

            if ($concept === '' || strlen($concept) > self::MAX_NAME_LENGTH) {
                continue;
            }

            // Keep the first occurrence; duplicates collapse post-normalization.
            if (isset($competencies[$concept])) {
                continue;
            }

            $competencies[$concept] = [
                'concept' => $concept,
                'strategic_role' => StrategicRole::from($roleValue),
            ];
        }

        return new self(array_slice(array_values($competencies), 0, self::MAX_ITEMS));
    }
}
