<?php

use App\Enums\JobCaptureTriageResult;
use App\Enums\JobCaptureTriageStatus;
use App\Enums\TriageOrigin;
use App\Jobs\AnalyzeJobPosting;
use App\Jobs\ProcessAiOperation;
use App\Mcp\Servers\JoboraServer;
use App\Mcp\Tools\Captures\DeleteJobCaptureTool;
use App\Mcp\Tools\Captures\GetJobCaptureTool;
use App\Mcp\Tools\Captures\ListJobCapturesTool;
use App\Mcp\Tools\Captures\RequestPublicationTriageTool;
use App\Mcp\Tools\Captures\TriageJobCapturesTool;
use App\Mcp\Tools\JobPostings\AnalyzeJobPostingTool;
use App\Mcp\Tools\JobPostings\GetJobPostingTool;
use App\Mcp\Tools\JobPostings\ListJobPostingsTool;
use App\Mcp\Tools\System\GetAiOperationTool;
use App\Models\AiOperation;
use App\Models\ApplicationMethod;
use App\Models\JobCapture;
use App\Models\JobPosting;
use App\Models\JobPostingItem;
use App\Models\OpportunitySource;
use App\Models\PageCapture;
use App\Models\Publication;
use App\Models\PublicationClefResult;
use App\Models\PublicationObservation;
use App\Models\PublicationTriageResult;
use App\Models\User;
use App\Services\AiOperationService;
use App\Services\JobReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Server\Attributes\Description;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as IntegrationHelpers;
use Tests\Feature\Mcp\McpTestHelpers;

uses(RefreshDatabase::class);

/** @param array<string, mixed> $arguments */
function assertRetiredCaptureTool(User $user, string $name, array $arguments = []): void
{
    $client = IntegrationHelpers::client();
    IntegrationHelpers::integration($user, $client);
    $token = IntegrationHelpers::accessToken($user, $client);
    app('auth')->forgetGuards();
    test()->withToken($token)->postJson('/mcp', IntegrationHelpers::toolCall($name, $arguments))
        ->assertOk()->assertJsonPath('error.code', -32602)->assertJsonPath('error.message', "Tool [{$name}] not found.");
}

it('aposenta captura direta sem converter o payload antigo em envelope', function () {
    $user = McpTestHelpers::user();

    assertRetiredCaptureTool($user, 'capture_job', [
        'source' => 'LinkedIn',
        'external_id' => 'urn:li:activity:123',
        'title' => 'Desenvolvedor PHP',
        'description' => 'Vaga para desenvolvedor PHP com Laravel.',
    ]);
    $this->assertDatabaseCount('job_captures', 0);
    $this->assertDatabaseCount('page_captures', 0);
});

it('rejeita repetições da captura direta aposentada sem criar dados', function () {
    $user = McpTestHelpers::user();

    $payload = ['source' => 'linkedin', 'external_id' => 'abc-1', 'description' => 'Vaga X'];

    assertRetiredCaptureTool($user, 'capture_job', $payload);
    assertRetiredCaptureTool($user, 'capture_job', $payload);
    $this->assertDatabaseCount('job_captures', 0);
});

it('não reativa captura aposentada para validar source ausente', function () {
    $user = McpTestHelpers::user();

    assertRetiredCaptureTool($user, 'capture_job', ['description' => 'Vaga sem origem']);
});

it('não reativa captura aposentada para validar identidade ausente', function () {
    $user = McpTestHelpers::user();

    assertRetiredCaptureTool($user, 'capture_job', ['source' => 'linkedin']);
});

it('devolve uma captura do usuário configurado', function () {
    $user = McpTestHelpers::user();
    $capture = JobCapture::factory()->for($user, 'user')->create(['title' => 'Vaga A']);

    JoboraServer::tool(GetJobCaptureTool::class, ['id' => $capture->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.capture.id', $capture->id)
            ->where('data.capture.title', 'Vaga A')
            ->etc());
});

it('não devolve captura de outro usuário', function () {
    McpTestHelpers::user();
    $capture = JobCapture::factory()->for(User::factory(), 'user')->create();

    JoboraServer::tool(GetJobCaptureTool::class, ['id' => $capture->id])
        ->assertHasErrors(['not_found']);
});

it('lista capturas do usuário com paginação', function () {
    $user = McpTestHelpers::user();
    JobCapture::factory()->for($user, 'user')->count(3)->create();
    JobCapture::factory()->for(User::factory(), 'user')->create();

    JoboraServer::tool(ListJobCapturesTool::class, ['limit' => 2])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.total', 3)
            ->where('data.next_offset', 2)
            ->has('data.items', 2)
            ->etc());
});

it('filtra capturas por status de triagem', function () {
    $user = McpTestHelpers::user();
    JobCapture::factory()->for($user, 'user')->create([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::Relevant,
    ]);
    JobCapture::factory()->for($user, 'user')->create(['triage_status' => JobCaptureTriageStatus::Pending]);

    JoboraServer::tool(ListJobCapturesTool::class, ['triage_status' => 'completed'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.total', 1)
            ->etc());
});

it('exige confirmação para excluir uma captura', function () {
    $user = McpTestHelpers::user();
    $capture = JobCapture::factory()->for($user, 'user')->create();

    JoboraServer::tool(DeleteJobCaptureTool::class, ['id' => $capture->id])
        ->assertHasErrors(['conflict']);
});

it('exclui a captura com confirmação e ela deixa de aparecer', function () {
    $user = McpTestHelpers::user();
    $capture = JobCapture::factory()->for($user, 'user')->create();

    JoboraServer::tool(DeleteJobCaptureTool::class, ['id' => $capture->id, 'confirm' => true])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.deleted', true)
            ->etc());

    JoboraServer::tool(GetJobCaptureTool::class, ['id' => $capture->id])
        ->assertHasErrors(['not_found']);
});

it('não dispara triagem V1 aposentada nem altera capturas pendentes', function () {
    $user = McpTestHelpers::user();
    Queue::fake();

    $a = JobCapture::factory()->for($user, 'user')->create(['triage_status' => JobCaptureTriageStatus::Pending]);
    $b = JobCapture::factory()->for($user, 'user')->create(['triage_status' => JobCaptureTriageStatus::Pending]);
    JobCapture::factory()->for($user, 'user')->create([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::Relevant,
    ]);

    assertRetiredCaptureTool($user, 'triage_job_captures', ['ids' => [$a->id, $b->id]]);
    Queue::assertNothingPushed();
    expect($b->fresh()->triage_status)->toBe(JobCaptureTriageStatus::Pending);

    JoboraServer::tool(GetJobCaptureTool::class, ['id' => $a->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.capture.triage_status', 'pending')
            ->etc());
});

it('também rejeita triagem V1 aposentada sem capturas elegíveis', function () {
    $user = McpTestHelpers::user();
    Queue::fake();

    JobCapture::factory()->for($user, 'user')->create([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::Relevant,
    ]);

    assertRetiredCaptureTool($user, 'triage_job_captures');

    Queue::assertNothingPushed();
});

it('não solicita triagem de publicação barrada pelo Clef', function () {
    $user = McpTestHelpers::user();
    Queue::fake();
    $publication = Publication::factory()->for($user)->create([
        'content' => 'Descrição pública da oportunidade.',
        'content_hash' => hash('sha256', 'Descrição pública da oportunidade.'),
        'content_version' => 3,
    ]);
    PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 3,
        'attempt' => 1,
        'status' => 'eligible',
    ]);
    PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 3,
        'attempt' => 2,
        'status' => 'barred',
    ]);

    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 3,
    ])->assertHasErrors(['precondition']);

    Queue::assertNothingPushed();
    expect(AiOperation::query()->count())->toBe(0);
});

it('recusa snapshot de publicação cujo hash não corresponde ao conteúdo atual', function () {
    $user = McpTestHelpers::user();
    Queue::fake();
    $publication = Publication::factory()->for($user)->create([
        'content' => 'Conteúdo atual da publicação.',
        'content_hash' => hash('sha256', 'Conteúdo anterior da publicação.'),
        'content_version' => 1,
    ]);
    PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 1,
        'attempt' => 1,
        'status' => 'eligible',
    ]);

    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
    ])->assertHasErrors(['precondition']);

    Queue::assertNothingPushed();
    expect(AiOperation::query()->count())->toBe(0);
});

it('não solicita triagem de publicação pertencente a outro usuário', function () {
    $user = McpTestHelpers::user();
    Queue::fake();
    $owner = User::factory()->create();
    $content = 'Private publication content owned by another user.';
    $publication = Publication::factory()->for($owner)->create([
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
    ]);
    PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 1,
        'attempt' => 1,
        'status' => 'eligible',
    ]);

    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
    ])->assertHasErrors(['precondition']);

    Queue::assertNothingPushed();
    expect(AiOperation::query()->count())->toBe(0)
        ->and($user->id)->not->toBe($owner->id);
});

it('recusa versão antiga de publicação mesmo com Clef elegível na versão atual', function () {
    $user = McpTestHelpers::user();
    Queue::fake();
    $content = 'Current publication version content.';
    $publication = Publication::factory()->for($user)->create([
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 2,
    ]);
    PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 2,
        'attempt' => 1,
        'status' => 'eligible',
    ]);

    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
    ])->assertHasErrors(['precondition']);

    Queue::assertNothingPushed();
    expect(AiOperation::query()->count())->toBe(0);
});

it('reutiliza operação de triagem para publicação e versão já solicitadas', function () {
    $user = McpTestHelpers::user();
    Queue::fake();
    $content = 'Publicação com descrição específica da oportunidade de trabalho.';
    $publication = Publication::factory()->for($user)->create([
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 3,
    ]);
    PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 3,
        'attempt' => 1,
        'status' => 'eligible',
    ]);

    $arguments = ['publication_id' => $publication->id, 'content_version' => 3];
    $first = JoboraServer::tool(RequestPublicationTriageTool::class, $arguments)->assertOk();
    $second = JoboraServer::tool(RequestPublicationTriageTool::class, $arguments)->assertOk();
    $operation = AiOperation::query()->sole();

    $first->assertStructuredContent(fn ($json) => $json
        ->where('ok', true)
        ->where('data.operation_id', $operation->id)
        ->where('data.type', 'publication_triage')
        ->etc());
    $second->assertStructuredContent(fn ($json) => $json
        ->where('ok', true)
        ->where('data.operation_id', $operation->id)
        ->etc());

    expect($operation->operation_key)->toBe("user:{$user->id}:publication_triage:publication:{$publication->id}:version:3")
        ->and(array_keys($operation->input_snapshot))->toBe([
            'publication_id', 'content_version', 'content_hash', 'url', 'content',
            'evidence_block_ids', 'provider', 'model', 'schema_version', 'clef_result_id',
            'clef_status', 'clef_bypassed', 'clef_bypass_reason', 'requested_by',
        ])
        ->and($operation->input_snapshot)->toMatchArray([
            'publication_id' => $publication->id,
            'content_version' => 3,
            'content_hash' => hash('sha256', $content),
            'url' => $publication->url,
            'content' => $content,
            'provider' => 'opencode_go',
            'model' => 'deepseek-v4.1-flash',
            'schema_version' => 'publication-triage-v1',
        ]);
    Queue::assertPushed(ProcessAiOperation::class, 1);
});

it('exige retry explícito para reiniciar operação de triagem com falha', function () {
    $user = McpTestHelpers::user();
    Queue::fake();
    $content = 'Anúncio público de vaga para pessoa desenvolvedora.';
    $publication = Publication::factory()->for($user)->create([
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
    ]);
    PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 1,
        'attempt' => 1,
        'status' => 'eligible',
    ]);
    $arguments = ['publication_id' => $publication->id, 'content_version' => 1];

    JoboraServer::tool(RequestPublicationTriageTool::class, $arguments)->assertOk();
    $operation = AiOperation::query()->sole();
    $originalRunToken = $operation->run_token;
    $operation->forceFill(['status' => 'failed', 'failure_code' => 'processing_failed'])->save();

    JoboraServer::tool(RequestPublicationTriageTool::class, $arguments)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.status', 'failed')
            ->etc());
    expect($operation->fresh()->run_token)->toBe($originalRunToken);

    JoboraServer::tool(RequestPublicationTriageTool::class, [...$arguments, 'retry' => true])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.status', 'queued')
            ->etc());

    expect($operation->fresh()->run_token)->not->toBe($originalRunToken);
    Queue::assertPushed(ProcessAiOperation::class, 2);
});

it('conclui triagem válida sem oportunidades e mantém saída consultável', function () {
    $user = McpTestHelpers::user();
    Queue::fake();
    $content = 'Texto delimitado da publicação sem oferta de trabalho.';
    $publication = Publication::factory()->for($user)->create([
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
    ]);
    PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 1,
        'attempt' => 1,
        'status' => 'eligible',
    ]);
    $operationResponse = JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
    ])->assertOk();
    $operation = AiOperation::query()->sole();
    Http::fake([
        '*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode(['opportunities' => []], JSON_THROW_ON_ERROR)],
                'finish_reason' => 'stop',
            ]],
            'model' => 'deepseek-v4.1-flash-free',
            'usage' => ['prompt_tokens' => 15, 'completion_tokens' => 3],
        ]),
    ]);

    (new ProcessAiOperation($operation->id, $operation->run_token, $operation->attempt_token))
        ->handle(app(AiOperationService::class));

    expect($operation->fresh()->status->value)->toBe('completed')
        ->and(PublicationTriageResult::query()->sole()->output)->toBe([
            'outcome' => 'not_a_job',
            'opportunities' => [],
        ]);
    Http::assertSentCount(1);
    expect(PublicationTriageResult::query()->sole()->prompt_tokens)->toBe(15);

    JoboraServer::tool(GetAiOperationTool::class, ['id' => $operation->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.publication_triage_result.output.outcome', 'not_a_job')
            ->where('data.publication_triage_result.output.opportunities', [])
            ->etc());
    JoboraServer::tool(GetAiOperationTool::class, [
        'publication_id' => $publication->id,
        'type' => 'publication_triage',
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.id', $operation->id)
            ->etc());
    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.operation_id', $operation->id)
            ->where('data.status', 'completed')
            ->etc());
    Queue::assertPushed(ProcessAiOperation::class, 1);
});

it('falha em resposta estruturalmente inválida sem persistir oportunidades', function () {
    $user = McpTestHelpers::user();
    Queue::fake();
    $content = 'Publicação com conteúdo válido para triagem.';
    $publication = Publication::factory()->for($user)->create([
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
    ]);
    PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 1,
        'attempt' => 1,
        'status' => 'eligible',
    ]);
    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
    ])->assertOk();
    $operation = AiOperation::query()->sole();
    Http::fakeSequence()->push([
        'choices' => [[
            'message' => ['content' => json_encode(['opportunities' => [['role' => 123]]], JSON_THROW_ON_ERROR)],
            'finish_reason' => 'stop',
        ]],
        'model' => 'deepseek-v4.1-flash-free',
        'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 4],
    ])->push([
        'choices' => [[
            'message' => ['content' => json_encode(['opportunities' => []], JSON_THROW_ON_ERROR)],
            'finish_reason' => 'stop',
        ]],
        'model' => 'deepseek-v4.1-flash-free',
        'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 2],
    ]);

    (new ProcessAiOperation($operation->id, $operation->run_token, $operation->attempt_token))
        ->handle(app(AiOperationService::class));

    $result = PublicationTriageResult::query()->sole();
    expect($operation->fresh()->status->value)->toBe('failed')
        ->and($operation->fresh()->failure_code)->toBe('invalid_output')
        ->and($result->status)->toBe('failed')
        ->and($result->output)->toBeNull()
        ->and($result->error_code)->toBe('invalid_output')
        ->and($result->prompt_tokens)->toBe(8)
        ->and($result->completion_tokens)->toBe(4)
        ->and(JobPosting::query()->count())->toBe(0);

    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
        'retry' => true,
    ])->assertOk();
    $retried = $operation->fresh();

    (new ProcessAiOperation($retried->id, $retried->run_token, $retried->attempt_token))
        ->handle(app(AiOperationService::class));

    expect($retried->fresh()->status->value)->toBe('completed')
        ->and(PublicationTriageResult::query()->where('ai_operation_id', $operation->id)->count())->toBe(2)
        ->and(PublicationTriageResult::query()->where('ai_operation_id', $operation->id)->where('status', 'failed')->count())->toBe(1)
        ->and(PublicationTriageResult::query()->where('ai_operation_id', $operation->id)->where('status', 'completed')->count())->toBe(1)
        ->and($result->fresh()->run_token)->not->toBe($retried->run_token);
});

it('persiste oportunidades estruturadas com readiness separado e proveniência por versão', function () {
    $user = McpTestHelpers::user();
    Queue::fake();
    $content = 'Senior PHP developer at Example Ltd. Build PHP services. Location: Remote. Work mode: remote. Employment type: full_time. Salary: USD 120000-150000 per year. Seniority: senior. Requirements: PHP 8.4. Technologies: Laravel. Apply at https://jobs.example.test/apply through Example.';
    $publication = Publication::factory()->for($user)->create([
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 4,
        'url' => 'https://www.linkedin.com/feed/update/urn:li:activity:987654321',
    ]);
    $capture = PageCapture::factory()->for($user, 'user')->create();
    PublicationObservation::factory()->for($capture, 'pageCapture')->for($publication, 'publication')->create([
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 4,
        'evidence_block_ids' => ['block-42'],
    ]);
    PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 4,
        'attempt' => 1,
        'status' => 'eligible',
    ]);
    $opportunities = [
        [
            'role' => 'Senior PHP developer',
            'company' => 'Example Ltd',
            'description' => 'Build PHP services.',
            'location' => 'Remote',
            'work_mode' => 'remote',
            'employment_type' => 'full_time',
            'salary_min' => 120000,
            'salary_max' => 150000,
            'salary_currency' => 'USD',
            'salary_period' => 'year',
            'seniority' => 'senior',
            'requirements' => ['PHP 8.4'],
            'technologies' => ['Laravel'],
            'application_methods' => [[
                'type' => 'external_url',
                'platform' => 'Example',
                'url' => 'https://jobs.example.test/apply',
                'email' => null,
                'phone' => null,
                'instructions' => null,
            ]],
            'evidence' => [[
                'quote' => $content,
                'block_ids' => ['block-42'],
                'fields' => ['role', 'company', 'description', 'location', 'work_mode', 'employment_type', 'salary_min', 'salary_max', 'salary_currency', 'salary_period', 'seniority', 'requirements', 'technologies', 'application_methods'],
            ]],
        ],
        [
            'role' => null,
            'company' => 'Review Ltd',
            'description' => null,
            'location' => null,
            'work_mode' => null,
            'employment_type' => null,
            'salary_min' => 200,
            'salary_max' => 100,
            'salary_currency' => null,
            'salary_period' => null,
            'seniority' => null,
            'requirements' => [],
            'technologies' => [],
            'application_methods' => [],
            'evidence' => [[
                'quote' => 'Invented evidence quote.',
                'block_ids' => ['unknown-block'],
                'fields' => ['company'],
            ]],
        ],
    ];
    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 4,
    ])->assertOk();
    $operation = AiOperation::query()->sole();
    Http::fake(['*' => Http::response([
        'choices' => [[
            'message' => ['content' => json_encode(['opportunities' => $opportunities], JSON_THROW_ON_ERROR)],
            'finish_reason' => 'stop',
        ]],
        'model' => 'deepseek-v4.1-flash-free',
        'usage' => ['prompt_tokens' => 25, 'completion_tokens' => 30],
    ])]);

    (new ProcessAiOperation($operation->id, $operation->run_token, $operation->attempt_token))
        ->handle(app(AiOperationService::class));

    $postings = JobPosting::query()->where('user_id', $user->id)->orderBy('id')->get();
    expect($postings)->toHaveCount(2)
        ->and($postings[0]->readiness)->toBe('ready')
        ->and($postings[0]->status->value)->toBe('pending')
        ->and($postings[0]->opportunity_version)->toBe(1)
        ->and($postings[0]->salary_currency)->toBe('USD')
        ->and($postings[0]->requirements)->toBe(['PHP 8.4'])
        ->and($postings[1]->readiness)->toBe('needs_review')
        ->and($postings[1]->review_reasons)->toContain('missing_role')
        ->and($postings[1]->description)->toBeNull();
    expect(OpportunitySource::query()->where('publication_content_version', 4)->count())->toBe(2)
        ->and(OpportunitySource::query()->where('publication_triage_result_id', PublicationTriageResult::query()->sole()->id)->count())->toBe(2)
        ->and(ApplicationMethod::query()->where('job_posting_id', $postings[0]->id)->value('url'))->toBe('https://jobs.example.test/apply')
        ->and(JobPostingItem::query()->where('job_posting_id', $postings[0]->id)->count())->toBe(2);
    Queue::assertNotPushed(AnalyzeJobPosting::class);

    JoboraServer::tool(GetJobPostingTool::class, ['id' => $postings[0]->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.job_posting.status', 'pending')
            ->where('data.job_posting.readiness', 'ready')
            ->where('data.job_posting.salary_currency', 'USD')
            ->etc());
    JoboraServer::tool(ListJobPostingsTool::class, ['readiness' => 'needs_review'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.total', 1)
            ->where('data.items.0.readiness', 'needs_review')
            ->etc());
});

it('bloqueia análise V1 para oportunidade estruturada', function () {
    $user = McpTestHelpers::user();
    Queue::fake();
    $posting = $user->jobPostings()->create([
        'description' => 'Descrição estruturada suficientemente longa para a análise antiga.',
        'collected_at' => now(),
        'status' => 'pending',
        'readiness' => 'ready',
        'opportunity_version' => 1,
    ]);

    JoboraServer::tool(AnalyzeJobPostingTool::class, ['id' => $posting->id])
        ->assertHasErrors(['precondition']);

    Queue::assertNothingPushed();
});

it('bloqueia confirmação e descarte V1 para oportunidade estruturada', function () {
    $user = McpTestHelpers::user();
    $posting = $user->jobPostings()->create([
        'description' => 'Structured opportunity description.',
        'collected_at' => now(),
        'status' => 'needs_review',
        'readiness' => 'needs_review',
        'opportunity_version' => 1,
        'suggested_data' => ['items' => []],
    ]);
    $review = app(JobReviewService::class);

    expect(fn () => $review->confirm($user, $posting, [], [], null, null))
        ->toThrow(LogicException::class)
        ->and(fn () => $review->discard($user, $posting))->toThrow(LogicException::class);
    expect($posting->fresh()->status->value)->toBe('needs_review')
        ->and($posting->fresh()->suggested_data)->toBe(['items' => []]);
});

it('não promove capturas históricas pela ferramenta V1 aposentada', function () {
    $user = McpTestHelpers::user();
    Queue::fake();

    $capture = JobCapture::factory()->for($user, 'user')->create([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::Relevant,
        'description' => 'Vaga para desenvolvedor PHP com Laravel e testes.',
    ]);

    assertRetiredCaptureTool($user, 'promote_job_capture', ['id' => $capture->id]);
    Queue::assertNothingPushed();
    $this->assertDatabaseCount('job_postings', 0);

    JoboraServer::tool(GetJobCaptureTool::class, ['id' => $capture->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.capture.job_posting_id', null)
            ->etc());
});

it('rejeita promoção aposentada também para captura sem triagem relevante', function () {
    $user = McpTestHelpers::user();
    Queue::fake();

    $capture = JobCapture::factory()->for($user, 'user')->create([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::Rejected,
        'description' => 'Vaga descartada pela triagem com texto suficiente.',
    ]);

    assertRetiredCaptureTool($user, 'promote_job_capture', ['id' => $capture->id]);

    Queue::assertNothingPushed();
});

it('não promove lotes históricos pela ferramenta V1 aposentada', function () {
    $user = McpTestHelpers::user();
    Queue::fake();

    JobCapture::factory()->for($user, 'user')->count(2)->create([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::Relevant,
        'description' => 'Vaga elegível com descrição suficiente para promoção.',
    ]);
    JobCapture::factory()->for($user, 'user')->create([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::Rejected,
        'description' => 'Vaga rejeitada com descrição suficiente para promoção.',
    ]);

    assertRetiredCaptureTool($user, 'promote_eligible_job_captures');
    Queue::assertNothingPushed();
    $this->assertDatabaseCount('job_captures', 3);
    $this->assertDatabaseCount('job_postings', 0);
});

it('describes the real prefilter and triage cost in the tool description', function () {
    $description = (new ReflectionClass(TriageJobCapturesTool::class))
        ->getAttributes(Description::class)[0]
        ->newInstance()
        ->value;

    expect($description)->toContain('1 chamada por captura')
        ->and($description)->toContain('1 chamada por lote');
});

it('devolve a origem da triagem na captura', function () {
    $user = McpTestHelpers::user();

    $capture = JobCapture::factory()->for($user, 'user')->create([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::NotAJob,
        'triage_origin' => TriageOrigin::Prefilter,
        'triage_reason' => 'Pré-filtro: Outro conteúdo que não anuncia vaga (p=0,10)',
    ]);

    JoboraServer::tool(GetJobCaptureTool::class, ['id' => $capture->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.capture.triage_origin', 'prefilter')
            ->etc());
});
