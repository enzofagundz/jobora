<?php

use App\Enums\ApplicationStatus;
use App\Mcp\Servers\JoboraServer;
use App\Mcp\Tools\Applications\GetApplicationTool;
use App\Mcp\Tools\Applications\ListApplicationsTool;
use App\Mcp\Tools\Applications\RecordApplicationUpdateTool;
use App\Mcp\Tools\Applications\StartApplicationTool;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\ApplicationTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Mcp\McpTestHelpers;

uses(RefreshDatabase::class);

it('inicia o acompanhamento de candidatura de uma vaga', function () {
    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();

    JoboraServer::tool(StartApplicationTool::class, ['job_posting_id' => $posting->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.application.status', 'planned')
            ->etc());
});

it('devolve a candidatura com um único evento após inícios repetidos', function () {
    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();

    JoboraServer::tool(StartApplicationTool::class, ['job_posting_id' => $posting->id])->assertOk();
    JoboraServer::tool(StartApplicationTool::class, ['job_posting_id' => $posting->id])->assertOk();

    JoboraServer::tool(GetApplicationTool::class, ['job_posting_id' => $posting->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.application.status', 'planned')
            ->has('data.events', 1)
            ->etc());
});

it('retorna not_found quando a vaga não tem candidatura', function () {
    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();

    JoboraServer::tool(GetApplicationTool::class, ['job_posting_id' => $posting->id])
        ->assertHasErrors(['not_found']);
});

it('lista candidaturas filtrando por status', function () {
    $user = McpTestHelpers::user();
    $first = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    $second = JobPosting::factory()->for($user, 'user')->confirmed()->create();

    JoboraServer::tool(StartApplicationTool::class, ['job_posting_id' => $first->id])->assertOk();
    JoboraServer::tool(StartApplicationTool::class, ['job_posting_id' => $second->id])->assertOk();

    app(ApplicationTrackingService::class)->recordUpdate(
        $user,
        $second->application()->first(),
        ApplicationStatus::Submitted,
        occurredAt: now(),
    );

    JoboraServer::tool(ListApplicationsTool::class, ['status' => 'submitted'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.total', 1)
            ->where('data.items.0.status', 'submitted')
            ->where('data.items.0.job_posting_id', $second->id)
            ->etc());
});

it('registra uma atualização de candidatura e cria o evento', function () {
    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    $application = app(ApplicationTrackingService::class)->start($user, $posting);

    JoboraServer::tool(RecordApplicationUpdateTool::class, [
        'application_id' => $application->id,
        'status' => 'interview',
        'title' => 'Entrevista técnica',
        'notes' => 'Marcada para sexta.',
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.application.status', 'interview')
            ->where('data.event.title', 'Entrevista técnica')
            ->etc());

    JoboraServer::tool(GetApplicationTool::class, ['job_posting_id' => $posting->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.application.status', 'interview')
            ->has('data.events', 2)
            ->etc());
});

it('não registra atualização em candidatura de outro usuário', function () {
    McpTestHelpers::user();
    $other = User::factory()->create();
    $posting = JobPosting::factory()->for($other, 'user')->confirmed()->create();
    $application = app(ApplicationTrackingService::class)->start($other, $posting);

    JoboraServer::tool(RecordApplicationUpdateTool::class, [
        'application_id' => $application->id,
        'status' => 'interview',
    ])->assertHasErrors(['not_found']);
});

it('é idempotente ao iniciar duas vezes', function () {
    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();

    JoboraServer::tool(StartApplicationTool::class, ['job_posting_id' => $posting->id])->assertOk();
    JoboraServer::tool(StartApplicationTool::class, ['job_posting_id' => $posting->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.application.status', 'planned')
            ->etc());
});
