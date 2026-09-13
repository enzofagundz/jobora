<?php

use App\Mcp\Servers\JoboraServer;
use App\Mcp\Tools\Resume\GetResumeTool;
use App\Mcp\Tools\Resume\SaveResumeTool;
use App\Mcp\Tools\Screening\GetScreeningPreferencesTool;
use App\Mcp\Tools\Screening\SaveScreeningPreferencesTool;
use App\Mcp\Tools\Search\GenerateSearchUrlTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Mcp\McpTestHelpers;

uses(RefreshDatabase::class);

it('devolve vazio quando não há currículo cadastrado', function () {
    McpTestHelpers::user();

    JoboraServer::tool(GetResumeTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.content', null)
            ->where('data.updated_at', null)
            ->etc());
});

it('exige confirmação para salvar o currículo', function () {
    McpTestHelpers::user();

    JoboraServer::tool(SaveResumeTool::class, ['content' => 'Currículo com PHP.'])
        ->assertHasErrors(['conflict']);
});

it('salva o currículo e substitui a versão anterior', function () {
    McpTestHelpers::user();

    JoboraServer::tool(SaveResumeTool::class, ['content' => 'Currículo com PHP e Laravel.', 'confirm' => true])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.content', 'Currículo com PHP e Laravel.')
            ->etc());

    JoboraServer::tool(SaveResumeTool::class, ['content' => 'Currículo só com Python.', 'confirm' => true])
        ->assertOk();

    JoboraServer::tool(GetResumeTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.content', 'Currículo só com Python.')
            ->etc());
});

it('recusa salvar currículo em branco', function () {
    McpTestHelpers::user();

    JoboraServer::tool(SaveResumeTool::class, ['content' => '   ', 'confirm' => true])
        ->assertHasErrors(['validation']);
});

it('devolve preferências de triagem vazias quando ainda não há', function () {
    McpTestHelpers::user();

    JoboraServer::tool(GetScreeningPreferencesTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.preferences', [])
            ->where('data.rejections', [])
            ->etc());
});

it('salva e devolve as preferências de triagem', function () {
    McpTestHelpers::user();

    JoboraServer::tool(SaveScreeningPreferencesTool::class, [
        'preferences' => ['remoto', 'PHP'],
        'rejections' => ['presencial'],
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.preferences', ['remoto', 'PHP'])
            ->where('data.rejections', ['presencial'])
            ->etc());

    JoboraServer::tool(GetScreeningPreferencesTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.preferences', ['remoto', 'PHP'])
            ->where('data.rejections', ['presencial'])
            ->etc());
});

it('gera a URL de busca de vagas do LinkedIn', function () {
    McpTestHelpers::user();

    JoboraServer::tool(GenerateSearchUrlTool::class, [
        'platform' => 'linkedin',
        'keywords' => ['PHP', 'Laravel'],
    ])
        ->assertOk()
        ->assertSee(['linkedin.com/jobs/search', 'PHP']);
});

it('exige ao menos uma palavra-chave na busca', function () {
    McpTestHelpers::user();

    JoboraServer::tool(GenerateSearchUrlTool::class, [
        'platform' => 'linkedin',
        'keywords' => [],
    ])->assertHasErrors(['validation']);
});

it('recusa modalidade não suportada pelo Indeed', function () {
    McpTestHelpers::user();

    JoboraServer::tool(GenerateSearchUrlTool::class, [
        'platform' => 'indeed',
        'keywords' => ['PHP'],
        'work_mode' => 'onsite',
    ])->assertHasErrors(['validation']);
});
