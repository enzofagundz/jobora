<?php

namespace App\Services\Triage;

/**
 * Resultado do pré-filtro para uma captura: probabilidade de ser vaga e,
 * quando não é, a categoria do conteúdo.
 */
final readonly class PrefilterVerdict
{
    public function __construct(
        public float $isJobProbability,
        public ?string $nonJobKind = null,
    ) {}
}
