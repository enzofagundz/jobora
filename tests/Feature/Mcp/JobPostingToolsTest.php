<?php

use App\Enums\ConceptCategory;
use App\Jobs\AnalyzeJobPosting;
use App\Mcp\Servers\JoboraServer;
use App\Mcp\Tools\JobPostings\AnalyzeJobPostingTool;
use App\Mcp\Tools\JobPostings\ConfirmJobPostingTool;
use App\Mcp\Tools\JobPostings\CreateJobPostingTool;
use App\Mcp\Tools\JobPostings\DeleteJobPostingTool;
use App\Mcp\Tools\JobPostings\DiscardJobPostingSuggestionTool;
use App\Mcp\Tools\JobPostings\GetJobPostingTool;
use App\Mcp\Tools\JobPostings\ListJobPostingsTool;
use App\Models\JobPosting;
use App\Models\JobPostingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Mcp\McpTestHelpers;

uses(RefreshDatabase::class);

it('cria uma vaga pendente a partir de uma descrição', function () {
    McpTestHelpers::user();

    JoboraServer::tool(CreateJobPostingTool::class, [
        'description' => 'Vaga para desenvolvedor PHP com Laravel e SQL.',
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.job_posting.status', 'pending')
            ->where('data.job_posting.items_count', 0)
            ->etc());
});

it('exige descrição com tamanho mínimo ao criar vaga', function () {
    McpTestHelpers::user();

    JoboraServer::tool(CreateJobPostingTool::class, ['description' => 'curta'])
        ->assertHasErrors(['validation']);
});

it('aceita link de origem opcional na criação', function () {
    McpTestHelpers::user();

    JoboraServer::tool(CreateJobPostingTool::class, [
        'description' => 'Vaga para desenvolvedor PHP com Laravel e SQL.',
        'source_url' => 'https://example.com/vaga/1',
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.job_posting.source_url', 'https://example.com/vaga/1')
            ->etc());
});

it('devolve o detalhe de uma vaga com seus itens', function () {
    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'role' => 'Dev PHP',
        'company' => 'Acme',
    ]);
    JobPostingItem::factory()->for($posting, 'jobPosting')->create([
        'category' => ConceptCategory::Skill,
        'name' => 'PHP',
    ]);

    JoboraServer::tool(GetJobPostingTool::class, ['id' => $posting->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.job_posting.id', $posting->id)
            ->where('data.job_posting.role', 'Dev PHP')
            ->where('data.job_posting.status', 'confirmed')
            ->where('data.job_posting.items.0.name', 'PHP')
            ->etc());
});

it('não devolve vaga de outro usuário', function () {
    McpTestHelpers::user();
    $posting = JobPosting::factory()->for(User::factory(), 'user')->create();

    JoboraServer::tool(GetJobPostingTool::class, ['id' => $posting->id])
        ->assertHasErrors(['not_found']);
});

it('lista as vagas do usuário com filtro por status e paginação', function () {
    $user = McpTestHelpers::user();
    JobPosting::factory()->for($user, 'user')->confirmed()->count(2)->create();
    JobPosting::factory()->for($user, 'user')->create();
    JobPosting::factory()->for(User::factory(), 'user')->confirmed()->create();

    JoboraServer::tool(ListJobPostingsTool::class, ['status' => 'confirmed', 'limit' => 1])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.total', 2)
            ->where('data.next_offset', 1)
            ->has('data.items', 1)
            ->etc());
});

it('reporta has_adaptation quando só existe a versão EN da adaptação', function () {
    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::adaptation($user, $posting, ['language' => 'en']);

    JoboraServer::tool(ListJobPostingsTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.items.0.has_adaptation', true)
            ->etc());
});

it('exige confirmação para excluir uma vaga', function () {
    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->create();

    JoboraServer::tool(DeleteJobPostingTool::class, ['id' => $posting->id])
        ->assertHasErrors(['conflict']);
});

it('exclui a vaga com confirmação e ela deixa de aparecer', function () {
    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->create();

    JoboraServer::tool(DeleteJobPostingTool::class, ['id' => $posting->id, 'confirm' => true])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.deleted', true)
            ->etc());

    JoboraServer::tool(GetJobPostingTool::class, ['id' => $posting->id])
        ->assertHasErrors(['not_found']);
});

it('dispara a análise por IA de uma vaga pendente', function () {
    $user = McpTestHelpers::user();
    Queue::fake();

    $posting = JobPosting::factory()->for($user, 'user')->create([
        'description' => 'Vaga para desenvolvedor PHP com Laravel e SQL.',
    ]);

    JoboraServer::tool(AnalyzeJobPostingTool::class, ['id' => $posting->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.job_posting_id', $posting->id)
            ->where('data.status', 'pending')
            ->etc());

    Queue::assertPushed(AnalyzeJobPosting::class);
});

it('recusa analisar uma vaga já confirmada', function () {
    $user = McpTestHelpers::user();
    Queue::fake();

    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();

    JoboraServer::tool(AnalyzeJobPostingTool::class, ['id' => $posting->id])
        ->assertHasErrors(['conflict']);

    Queue::assertNothingPushed();
});

it('confirma uma vaga em revisão e persiste os itens com a origem correta', function () {
    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->needsReview()->create([
        'suggested_data' => [
            'role' => 'Dev',
            'company' => 'Acme',
            'items' => [['category' => 'skill', 'name' => 'PHP']],
            'application_methods' => [],
        ],
    ]);

    JoboraServer::tool(ConfirmJobPostingTool::class, [
        'id' => $posting->id,
        'items' => [
            ['category' => 'skill', 'name' => 'PHP'],
            ['category' => 'tool', 'name' => 'Docker'],
        ],
        'role' => 'Dev PHP',
        'company' => 'Acme',
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.job_posting.status', 'confirmed')
            ->where('data.job_posting.items_count', 2)
            ->etc());

    JoboraServer::tool(GetJobPostingTool::class, ['id' => $posting->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.job_posting.suggested_data', null)
            ->where('data.job_posting.items.0.name', 'PHP')
            ->where('data.job_posting.items.0.source', 'ai')
            ->where('data.job_posting.items.1.name', 'Docker')
            ->where('data.job_posting.items.1.source', 'user')
            ->etc());
});

it('recusa confirmar uma vaga que não está aguardando revisão', function () {
    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->create();

    JoboraServer::tool(ConfirmJobPostingTool::class, [
        'id' => $posting->id,
        'items' => [],
    ])->assertHasErrors(['conflict']);
});

it('exige confirmação para descartar a sugestão de uma vaga', function () {
    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->needsReview()->create([
        'suggested_data' => ['role' => 'Dev', 'items' => []],
    ]);

    JoboraServer::tool(DiscardJobPostingSuggestionTool::class, ['id' => $posting->id])
        ->assertHasErrors(['conflict']);
});

it('descarta a sugestão e volta a vaga para pendente', function () {
    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->needsReview()->create([
        'suggested_data' => ['role' => 'Dev', 'items' => [['category' => 'skill', 'name' => 'PHP']]],
    ]);

    JoboraServer::tool(DiscardJobPostingSuggestionTool::class, ['id' => $posting->id, 'confirm' => true])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.job_posting.status', 'pending')
            ->etc());

    JoboraServer::tool(GetJobPostingTool::class, ['id' => $posting->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.job_posting.status', 'pending')
            ->where('data.job_posting.suggested_data', null)
            ->etc());
});
