<?php

use App\Agents\JobExtractionAgent;

it('instructions enforce pt-BR, taxonomy, casing and REST API', function () {
    $instructions = app(JobExtractionAgent::class)->instructions();

    expect($instructions)->toContain('pt-BR')
        ->and($instructions)->toContain('"skills": código')
        ->and($instructions)->toContain('"tools": infra dev')
        ->and($instructions)->toContain('PHP')
        ->and($instructions)->toContain('Laravel')
        ->and($instructions)->toContain('Docker')
        ->and($instructions)->toContain('REST API')
        ->and($instructions)->toContain('api rest')
        ->and($instructions)->toContain('Vendas Consultivas');
});
