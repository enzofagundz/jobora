<?php

use App\Enums\ResumeLanguage;
use App\Mcp\Resources\JobPostingResource;
use App\Mcp\Resources\ResumeResource;
use App\Mcp\Servers\JoboraServer;
use App\Models\JobPosting;
use App\Services\ResumeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Mcp\McpTestHelpers;

uses(RefreshDatabase::class);

it('expõe o currículo atual como resource', function () {
    $user = McpTestHelpers::user();
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);

    JoboraServer::resource(ResumeResource::class)
        ->assertOk()
        ->assertSee(['Currículo com PHP.']);
});

it('expõe o currículo do idioma pedido como resource', function () {
    $user = McpTestHelpers::user();
    app(ResumeService::class)->save($user, 'Currículo em português.', ResumeLanguage::PtBr);
    app(ResumeService::class)->save($user, 'Resume in English.', ResumeLanguage::En);

    JoboraServer::resource(ResumeResource::class)
        ->assertOk()
        ->assertSee(['Currículo em português.']);

    JoboraServer::resource(ResumeResource::class, ['language' => 'en'])
        ->assertOk()
        ->assertSee(['Resume in English.']);
});

it('lê o currículo EN pelo protocolo real de resources/read', function () {
    $user = McpTestHelpers::user();
    app(ResumeService::class)->save($user, 'Currículo em português.', ResumeLanguage::PtBr);
    app(ResumeService::class)->save($user, 'Resume in English.', ResumeLanguage::En);

    $response = McpTestHelpers::readResource('jobora://resume?language=en');

    expect($response['ok'] ?? null)->toBeTrue()
        ->and($response['data']['content'] ?? null)->toBe('Resume in English.');
});

it('lê o currículo pt-BR padrão pela URI sem query', function () {
    $user = McpTestHelpers::user();
    app(ResumeService::class)->save($user, 'Currículo em português.', ResumeLanguage::PtBr);

    $response = McpTestHelpers::readResource('jobora://resume');

    expect($response['ok'] ?? null)->toBeTrue()
        ->and($response['data']['content'] ?? null)->toBe('Currículo em português.');
});

it('lê a adaptação do idioma pedido pelo protocolo real de resources/read', function () {
    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::adaptation($user, $posting, ['adapted_content' => 'Currículo PT.']);
    McpTestHelpers::adaptation($user, $posting, ['language' => 'en', 'adapted_content' => 'Resume EN.']);

    $response = McpTestHelpers::readResource("jobora://resume-adaptations/{$posting->id}?language=en");

    expect($response['ok'] ?? null)->toBeTrue()
        ->and($response['data']['adaptation']['adapted_content'] ?? null)->toBe('Resume EN.');
});

it('expõe o detalhe de uma vaga como resource', function () {
    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create(['role' => 'Dev PHP']);
    McpTestHelpers::item($posting, 'PHP');

    JoboraServer::resource(JobPostingResource::class, ['id' => $posting->id])
        ->assertOk()
        ->assertSee(['Dev PHP', 'PHP']);
});
