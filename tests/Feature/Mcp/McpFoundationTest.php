<?php

use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Mcp\Servers\JoboraServer;
use App\Mcp\Tools\System\GetAiOperationTool;
use App\Models\AiOperation;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Mcp\Facades\Mcp;

uses(RefreshDatabase::class);

function mcpUser(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    config(['jobora.mcp.user' => $user->email]);

    return $user;
}

function mcpOperation(User $user, array $attributes = []): AiOperation
{
    return AiOperation::create(array_merge([
        'user_id' => $user->id,
        'operation_key' => 'resume_adaptation:job_posting:'.Str::random(8),
        'operation_type' => AiOperationType::ResumeAdaptation,
        'target_type' => 'job_posting',
        'target_id' => 1,
        'run_token' => (string) Str::uuid(),
        'status' => AiOperationStatus::Queued,
        'queued_at' => now(),
        'expires_at' => now()->addMinutes(5),
        'input_fingerprint' => str_repeat('a', 64),
    ], $attributes));
}

it('registra o servidor MCP jobora', function () {
    expect(Mcp::getLocalServer('jobora'))->not->toBeNull();
});

it('falha com código configuration quando JOBORA_MCP_USER não está definido', function () {
    config(['jobora.mcp.user' => null]);

    JoboraServer::tool(GetAiOperationTool::class, ['id' => 1])
        ->assertHasErrors(['configuration']);
});

it('falha com código configuration quando o usuário configurado não existe', function () {
    config(['jobora.mcp.user' => 'fantasma@example.com']);

    JoboraServer::tool(GetAiOperationTool::class, ['id' => 1])
        ->assertHasErrors(['configuration']);
});

it('falha com código configuration quando o usuário configurado está desabilitado', function () {
    $user = User::factory()->create(['disabled_at' => now()]);
    config(['jobora.mcp.user' => $user->email]);

    JoboraServer::tool(GetAiOperationTool::class, ['id' => 1])
        ->assertHasErrors(['configuration']);
});

it('resolve o usuário configurado por e-mail e devolve a própria operação', function () {
    $user = mcpUser();
    $operation = mcpOperation($user);

    JoboraServer::tool(GetAiOperationTool::class, ['id' => $operation->id])
        ->assertOk()
        ->assertStructuredContent(function ($json) use ($operation) {
            $json->where('ok', true)
                ->where('data.id', $operation->id)
                ->where('data.type', 'resume_adaptation')
                ->where('data.status', 'queued');
        });
});

it('resolve o usuário configurado por id numérico', function () {
    $user = mcpUser();
    config(['jobora.mcp.user' => (string) $user->id]);
    $operation = mcpOperation($user);

    JoboraServer::tool(GetAiOperationTool::class, ['id' => $operation->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('ok', true)->where('data.id', $operation->id));
});

it('não devolve operação de outro usuário', function () {
    mcpUser();
    $other = User::factory()->create();
    $operation = mcpOperation($other);

    JoboraServer::tool(GetAiOperationTool::class, ['id' => $operation->id])
        ->assertHasErrors(['not_found']);
});

it('retorna not_found para operação inexistente', function () {
    mcpUser();

    JoboraServer::tool(GetAiOperationTool::class, ['id' => 999999])
        ->assertHasErrors(['not_found']);
});

it('encontra a operação por vaga e tipo', function () {
    $user = mcpUser();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    $operation = mcpOperation($user, [
        'job_posting_id' => $posting->id,
        'operation_type' => AiOperationType::AtsScore,
    ]);

    JoboraServer::tool(GetAiOperationTool::class, [
        'job_posting_id' => $posting->id,
        'type' => 'ats_score',
    ])->assertOk();

    expect($operation->fresh())->not->toBeNull();
});
