<?php

use App\Enums\JobCaptureTriageResult;
use App\Enums\JobCaptureTriageStatus;
use App\Jobs\AnalyzeJobPosting;
use App\Jobs\TriageJobCaptures;
use App\Mcp\Servers\JoboraServer;
use App\Mcp\Tools\Captures\CaptureJobTool;
use App\Mcp\Tools\Captures\DeleteJobCaptureTool;
use App\Mcp\Tools\Captures\GetJobCaptureTool;
use App\Mcp\Tools\Captures\ListJobCapturesTool;
use App\Mcp\Tools\Captures\PromoteEligibleJobCapturesTool;
use App\Mcp\Tools\Captures\PromoteJobCaptureTool;
use App\Mcp\Tools\Captures\TriageJobCapturesTool;
use App\Models\JobCapture;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Mcp\McpTestHelpers;

uses(RefreshDatabase::class);

it('captura uma vaga para o usuário configurado', function () {
    McpTestHelpers::user();

    JoboraServer::tool(CaptureJobTool::class, [
        'source' => 'LinkedIn',
        'external_id' => 'urn:li:activity:123',
        'title' => 'Desenvolvedor PHP',
        'description' => 'Vaga para desenvolvedor PHP com Laravel.',
    ])
        ->assertOk()
        ->assertStructuredContent(function ($json) {
            $json->where('ok', true)
                ->where('data.duplicate', false)
                ->where('data.capture.source', 'linkedin')
                ->where('data.capture.external_id', 'urn:li:activity:123')
                ->where('data.capture.title', 'Desenvolvedor PHP')
                ->etc();
        });
});

it('não duplica a mesma captura', function () {
    McpTestHelpers::user();

    $payload = ['source' => 'linkedin', 'external_id' => 'abc-1', 'description' => 'Vaga X'];

    JoboraServer::tool(CaptureJobTool::class, $payload)->assertOk();

    JoboraServer::tool(CaptureJobTool::class, $payload)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('ok', true)->where('data.duplicate', true)->etc());
});

it('exige source na captura', function () {
    McpTestHelpers::user();

    JoboraServer::tool(CaptureJobTool::class, ['description' => 'Vaga sem origem'])
        ->assertHasErrors(['validation']);
});

it('exige external_id, url ou description na captura', function () {
    McpTestHelpers::user();

    JoboraServer::tool(CaptureJobTool::class, ['source' => 'linkedin'])
        ->assertHasErrors(['validation']);
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

it('dispara a triagem das capturas elegíveis e as marca como em processamento', function () {
    $user = McpTestHelpers::user();
    Queue::fake();

    $a = JobCapture::factory()->for($user, 'user')->create(['triage_status' => JobCaptureTriageStatus::Pending]);
    $b = JobCapture::factory()->for($user, 'user')->create(['triage_status' => JobCaptureTriageStatus::Pending]);
    JobCapture::factory()->for($user, 'user')->create([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::Relevant,
    ]);

    JoboraServer::tool(TriageJobCapturesTool::class, ['ids' => [$a->id, $b->id]])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.batch_size', 2)
            ->where('data.state', 'processing')
            ->etc());

    Queue::assertPushed(TriageJobCaptures::class);

    JoboraServer::tool(GetJobCaptureTool::class, ['id' => $a->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.capture.triage_status', 'processing')
            ->etc());
});

it('não dispara triagem quando não há capturas elegíveis', function () {
    $user = McpTestHelpers::user();
    Queue::fake();

    JobCapture::factory()->for($user, 'user')->create([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::Relevant,
    ]);

    JoboraServer::tool(TriageJobCapturesTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.batch_size', 0)
            ->where('data.state', 'idle')
            ->etc());

    Queue::assertNothingPushed();
});

it('promove uma captura elegível para vaga e a vincula', function () {
    $user = McpTestHelpers::user();
    Queue::fake();

    $capture = JobCapture::factory()->for($user, 'user')->create([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::Relevant,
        'description' => 'Vaga para desenvolvedor PHP com Laravel e testes.',
    ]);

    $response = JoboraServer::tool(PromoteJobCaptureTool::class, ['id' => $capture->id])
        ->assertOk();

    $response->assertStructuredContent(fn ($json) => $json
        ->where('ok', true)
        ->where('data.status', 'pending')
        ->etc());

    Queue::assertPushed(AnalyzeJobPosting::class);

    JoboraServer::tool(GetJobCaptureTool::class, ['id' => $capture->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->whereNot('data.capture.job_posting_id', null)
            ->etc());
});

it('recusa promover captura sem triagem relevante', function () {
    $user = McpTestHelpers::user();
    Queue::fake();

    $capture = JobCapture::factory()->for($user, 'user')->create([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::Rejected,
        'description' => 'Vaga descartada pela triagem com texto suficiente.',
    ]);

    JoboraServer::tool(PromoteJobCaptureTool::class, ['id' => $capture->id])
        ->assertHasErrors(['precondition']);

    Queue::assertNothingPushed();
});

it('promove em lote todas as capturas elegíveis', function () {
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

    JoboraServer::tool(PromoteEligibleJobCapturesTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.promoted_count', 2)
            ->etc());

    Queue::assertPushed(AnalyzeJobPosting::class, 2);
});
