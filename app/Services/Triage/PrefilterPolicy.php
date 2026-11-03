<?php

namespace App\Services\Triage;

/**
 * Regra determinística do corte: o modelo fornece a probabilidade e a
 * categoria; a aplicação decide se a captura é barrada e como registrar
 * o motivo.
 */
final readonly class PrefilterPolicy
{
    /**
     * @param  array<string, string>  $nonJobKindLabels
     */
    public function __construct(
        private float $minJobProbability,
        private array $nonJobKindLabels,
    ) {}

    public function bars(PrefilterVerdict $verdict): bool
    {
        return $verdict->isJobProbability < $this->minJobProbability;
    }

    public function reason(PrefilterVerdict $verdict): string
    {
        $label = $this->nonJobKindLabels[$verdict->nonJobKind ?? ''] ?? $this->nonJobKindLabels['other'] ?? 'Outro conteúdo que não anuncia vaga';
        $probability = number_format($verdict->isJobProbability, 2, ',', '');

        return mb_substr("Pré-filtro: {$label} (p={$probability})", 0, 200);
    }
}
