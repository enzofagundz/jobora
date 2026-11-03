<?php

use App\Services\Triage\PrefilterPolicy;
use App\Services\Triage\PrefilterVerdict;

function prefilterLabels(): array
{
    return [
        'candidate_offering' => 'Pessoa se oferecendo para trabalhar / buscando oportunidade',
        'job_curation' => 'Lista ou agregação de vagas de terceiros, com link externo',
        'editorial_content' => 'Artigo, dica, tutorial, curso, evento ou novidade',
        'promotion' => 'Anúncio comercial, promocional ou de produto',
        'other' => 'Outro conteúdo que não anuncia vaga',
    ];
}

it('bars a capture only when the job probability is below the threshold', function (float $probability, bool $bars): void {
    $policy = new PrefilterPolicy(0.50, prefilterLabels());

    expect($policy->bars(new PrefilterVerdict($probability, 'editorial_content')))->toBe($bars);
})->with([
    'zero' => [0.0, true],
    'just below' => [0.4999, true],
    'exactly at threshold' => [0.50, false],
    'just above' => [0.5001, false],
    'certain job' => [1.0, false],
]);

it('formats the categorical reason with a comma decimal probability', function (): void {
    $policy = new PrefilterPolicy(0.50, prefilterLabels());

    expect($policy->reason(new PrefilterVerdict(0.1234, 'job_curation')))
        ->toBe('Pré-filtro: Lista ou agregação de vagas de terceiros, com link externo (p=0,12)');
});

it('falls back to the other category when the kind is missing or unknown', function (): void {
    $policy = new PrefilterPolicy(0.50, prefilterLabels());

    expect($policy->reason(new PrefilterVerdict(0.90, null)))
        ->toBe('Pré-filtro: Outro conteúdo que não anuncia vaga (p=0,90)')
        ->and($policy->reason(new PrefilterVerdict(0.90, 'unknown_kind')))
        ->toBe('Pré-filtro: Outro conteúdo que não anuncia vaga (p=0,90)');
});
