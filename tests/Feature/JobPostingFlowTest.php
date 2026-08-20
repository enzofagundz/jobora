<?php

use App\Agents\JobExtractionAgent;
use App\Enums\ItemSource;
use App\Enums\JobPostingStatus;
use App\Filament\Resources\JobPostings\Pages\CreateJobPosting;
use App\Filament\Resources\JobPostings\Pages\ListJobPostings;
use App\Jobs\AnalyzeJobPosting;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\JobAnalysisService;
use App\Services\MarketAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// As páginas do painel exigem usuário autenticado (policy + middleware do painel).
beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

function flowDescription(): string
{
    return 'Vaga para desenvolvedor PHP com Laravel e PostgreSQL. Requisitos: Docker, AWS e banco relacional.'.str_repeat(' ', 20);
}

it('cria vaga como pending com collected_at = now e descrição preservada', function () {
    $posting = JobPosting::factory()->create([
        'description' => flowDescription(),
        'source_url' => 'https://exemplo.com/vaga-1',
    ]);

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::Pending)
        ->and($posting->description)->toBe(flowDescription())
        ->and($posting->source_url)->toBe('https://exemplo.com/vaga-1')
        ->and($posting->collected_at->isToday())->toBeTrue()
        ->and($posting->items()->count())->toBe(0);
});

it('cria vaga pela página e já despacha a análise de IA em segundo plano', function () {
    Queue::fake();

    Livewire::test(CreateJobPosting::class)
        ->fillForm([
            'description' => flowDescription(),
            'source_url' => 'https://exemplo.com/vaga-1',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $posting = JobPosting::query()->sole();

    expect($posting->status)->toBe(JobPostingStatus::Pending);

    Queue::assertPushed(AnalyzeJobPosting::class, fn (AnalyzeJobPosting $job) => $job->posting->is($posting));
});

it('analisa e confirma a vaga pela página de revisão', function () {
    JobExtractionAgent::fake([[
        'role' => 'Desenvolvedor PHP',
        'company' => 'Acme',
        'technologies' => ['Laravel', 'PostgreSQL'],
        'languages' => ['PHP'],
    ]]);

    $posting = JobPosting::factory()->create(['description' => flowDescription()]);
    app(JobAnalysisService::class)->analyze($posting);

    $posting->refresh();
    expect($posting->status)->toBe(JobPostingStatus::NeedsReview)
        ->and($posting->suggested_data['items'])->toHaveCount(3);

    // Mantém um item como veio da IA, edita outro e adiciona um novo.
    Livewire::test(ListJobPostings::class)->callTableAction('review', $posting, data: [
        'role' => 'Desenvolvedor PHP Sênior',
        'items' => [
            ['category' => 'technology', 'name' => 'Laravel'],
            ['category' => 'technology', 'name' => 'PostgreSQL'],
            ['category' => 'language', 'name' => 'PHP'],
            ['category' => 'methodology', 'name' => 'Scrum'],
        ],
    ]);

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::Confirmed)
        ->and($posting->role)->toBe('Desenvolvedor PHP Sênior')
        ->and($posting->company)->toBe('Acme')
        ->and($posting->suggested_data)->toBeNull()
        ->and($posting->error_message)->toBeNull()
        ->and($posting->items()->count())->toBe(4);

    $byName = $posting->items()->get()->keyBy(fn ($item) => $item->category->value.'|'.$item->name);

    expect($byName['language|PHP']->source)->toBe(ItemSource::Ai)
        ->and($byName['technology|Laravel']->source)->toBe(ItemSource::Ai)
        ->and($byName['technology|PostgreSQL']->source)->toBe(ItemSource::Ai)
        ->and($byName['methodology|Scrum']->source)->toBe(ItemSource::User);
});

it('persiste correção manual após recarregar e marca origem como user', function () {
    JobExtractionAgent::fake([[
        'role' => 'Dev',
        'technologies' => ['postgres'],
    ]]);

    $posting = JobPosting::factory()->create(['description' => flowDescription()]);
    app(JobAnalysisService::class)->analyze($posting);

    Livewire::test(ListJobPostings::class)->callTableAction('review', $posting, data: [
        'items' => [
            ['category' => 'database', 'name' => 'PostgreSQL'],
        ],
    ]);

    $item = $posting->refresh()->items()->first();

    // A IA sugeriu 'postgres' (normalizado para 'PostgreSQL'); como a sugestão
    // original foi em 'technology', a correção manual para 'database' marca user.
    expect($item->category->value)->toBe('database')
        ->and($item->name)->toBe('PostgreSQL')
        ->and($item->source)->toBe(ItemSource::User);
});

it('descarta a sugestão e volta para pending', function () {
    JobExtractionAgent::fake([[
        'role' => 'Dev',
        'technologies' => ['Laravel'],
    ]]);

    $posting = JobPosting::factory()->create(['description' => flowDescription()]);
    app(JobAnalysisService::class)->analyze($posting);

    Livewire::test(ListJobPostings::class)->mountTableAction('review', $posting)->callAction('discard');

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::Pending)
        ->and($posting->suggested_data)->toBeNull()
        ->and($posting->role)->toBeNull()
        ->and($posting->items()->count())->toBe(0);
});

it('falha, registra error_message e permite nova análise com sucesso', function () {
    $attempts = 0;

    JobExtractionAgent::fake(function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            throw new RuntimeException('Serviço indisponível');
        }

        return ['role' => 'Dev', 'technologies' => ['PHP']];
    });

    $posting = JobPosting::factory()->create(['description' => flowDescription()]);
    $service = app(JobAnalysisService::class);

    $service->analyze($posting);

    $posting->refresh();
    expect($posting->status)->toBe(JobPostingStatus::Failed)
        ->and($posting->error_message)->toContain('Serviço indisponível')
        ->and($posting->suggested_data)->toBeNull();

    $service->analyze($posting->refresh());

    $posting->refresh();
    expect($posting->status)->toBe(JobPostingStatus::NeedsReview)
        ->and($posting->error_message)->toBeNull()
        ->and($posting->suggested_data['items'])->toContain(['category' => 'technology', 'name' => 'PHP']);
});

it('vaga pendente/aguardando revisão não entra nas análises', function () {
    JobPosting::factory()->confirmed()->create(['description' => flowDescription()])
        ->items()->create(['category' => 'language', 'name' => 'PHP', 'source' => 'ai']);

    JobPosting::factory()->create(['description' => flowDescription()])
        ->items()->create(['category' => 'language', 'name' => 'Python', 'source' => 'ai']);

    $frequencies = app(MarketAnalysisService::class)->frequencies();

    expect($frequencies)->toHaveCount(1)
        ->and($frequencies[0]['name'])->toBe('PHP');
});

it('persiste a descrição rica como HTML vinda do editor', function () {
    Queue::fake();

    Livewire::test(CreateJobPosting::class)
        ->fillForm([
            'description' => '<p><strong>Vaga para desenvolvedor PHP</strong> com Laravel.</p>',
            'source_url' => null,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $stored = JobPosting::query()->sole()->description;

    expect($stored)->toContain('<strong>Vaga para desenvolvedor PHP</strong>')
        ->and($stored)->toContain('com Laravel');
});
