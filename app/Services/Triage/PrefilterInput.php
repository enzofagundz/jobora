<?php

namespace App\Services\Triage;

/**
 * Publicação enviada ao pré-filtro: apenas os campos da captura, sem
 * preferências, rejeições ou truncamento.
 */
final readonly class PrefilterInput
{
    public function __construct(
        public ?string $title,
        public ?string $description,
        public ?string $company,
        public ?string $location,
    ) {}
}
