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
use App\Services\JobReviewService;
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
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->create([
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

    Queue::assertPushed(AnalyzeJobPosting::class, fn (AnalyzeJobPosting $job) => $job->posting->is($posting) && $job->userId === auth()->id());
});

it('analisa e confirma a vaga pela página de revisão', function () {
    JobExtractionAgent::fake([[
        'role' => 'Desenvolvedor PHP',
        'company' => 'Acme',
        'skills' => ['Laravel', 'PostgreSQL', 'PHP'],
    ]]);

    $posting = JobPosting::factory()->for(auth()->user(), 'user')->create(['description' => flowDescription()]);
    app(JobAnalysisService::class)->analyze(auth()->user(), $posting);

    $posting->refresh();
    expect($posting->status)->toBe(JobPostingStatus::NeedsReview)
        ->and($posting->suggested_data['items'])->toHaveCount(3);

    // Mantém um item como veio da IA, edita outro e adiciona um novo.
    Livewire::test(ListJobPostings::class)->callTableAction('review', $posting, data: [
        'role' => 'Desenvolvedor PHP Sênior',
        'items' => [
            ['category' => 'skill', 'name' => 'Laravel'],
            ['category' => 'skill', 'name' => 'PostgreSQL'],
            ['category' => 'skill', 'name' => 'PHP'],
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

    expect($byName['skill|PHP']->source)->toBe(ItemSource::Ai)
        ->and($byName['skill|Laravel']->source)->toBe(ItemSource::Ai)
        ->and($byName['skill|PostgreSQL']->source)->toBe(ItemSource::Ai)
        ->and($byName['methodology|Scrum']->source)->toBe(ItemSource::User);
});

it('persiste correção manual após recarregar e marca origem como user', function () {
    JobExtractionAgent::fake([[
        'role' => 'Dev',
        'skills' => ['postgres'],
    ]]);

    $posting = JobPosting::factory()->for(auth()->user(), 'user')->create(['description' => flowDescription()]);
    app(JobAnalysisService::class)->analyze(auth()->user(), $posting);

    Livewire::test(ListJobPostings::class)->callTableAction('review', $posting, data: [
        'items' => [
            ['category' => 'tool', 'name' => 'PostgreSQL'],
        ],
    ]);

    $item = $posting->refresh()->items()->first();

    // A IA sugeriu 'postgres' (normalizado para 'PostgreSQL') em 'skill'; como a
    // correção manual para 'tool' difere da sugestão, a origem vira user.
    expect($item->category->value)->toBe('tool')
        ->and($item->name)->toBe('PostgreSQL')
        ->and($item->source)->toBe(ItemSource::User);
});

it('confirma formas de candidatura com origem ai/user, idempotência e reanálise substitutiva', function () {
    JobExtractionAgent::fake([
        [
            'role' => 'Dev',
            'application_methods' => [
                ['type' => 'email', 'email' => 'recrutamento@empresa.com'],
            ],
        ],
        [
            'role' => 'Dev',
            'application_methods' => [
                ['type' => 'external_url', 'url' => 'https://empresa.gupy.io/jobs/123', 'platform' => 'Gupy'],
            ],
        ],
    ]);

    $posting = JobPosting::factory()->for(auth()->user(), 'user')->create(['description' => flowDescription(), 'source_url' => 'https://www.linkedin.com/jobs/view/999']);

    app(JobAnalysisService::class)->analyze(auth()->user(), $posting);
    $posting->refresh();

    // Mantém o método da IA e adiciona um novo via revisão.
    Livewire::test(ListJobPostings::class)->callTableAction('review', $posting, data: [
        'items' => [['category' => 'skill', 'name' => 'PHP']],
        'application_methods' => [
            ['type' => 'email', 'email' => 'recrutamento@empresa.com'],
            ['type' => 'phone', 'phone' => '(11) 3000-0000'],
        ],
    ]);

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::Confirmed)
        ->and($posting->suggested_data)->toBeNull()
        ->and($posting->source_url)->toBe('https://www.linkedin.com/jobs/view/999')
        ->and($posting->applicationMethods()->count())->toBe(2);

    $methods = $posting->applicationMethods()->get()->keyBy(fn ($m) => $m->type->value);

    expect($methods['email']->email)->toBe('recrutamento@empresa.com')
        ->and($methods['email']->source)->toBe(ItemSource::Ai)
        ->and($methods['phone']->source)->toBe(ItemSource::User);

    $keys = $posting->applicationMethods()->pluck('dedup_key')->all();

    // Confirmação repetida não duplica.
    app(JobReviewService::class)->confirm(auth()->user(), $posting, [], [
        ['type' => 'email', 'email' => 'recrutamento@empresa.com'],
        ['type' => 'phone', 'phone' => '(11) 3000-0000'],
    ], null, null);

    expect($posting->refresh()->applicationMethods()->count())->toBe(2)
        ->and($posting->applicationMethods()->pluck('dedup_key')->sort()->values()->all())
        ->toBe(collect($keys)->sort()->values()->all());

    // Descarte zera tudo e volta a pending.
    app(JobReviewService::class)->discard(auth()->user(), $posting);

    expect($posting->refresh()->status)->toBe(JobPostingStatus::Pending)
        ->and($posting->suggested_data)->toBeNull()
        ->and($posting->applicationMethods()->count())->toBe(0);

    // Reanálise substitui o array sugerido inteiro (e-mail → URL).
    app(JobAnalysisService::class)->analyze(auth()->user(), $posting);
    $posting->refresh();

    expect($posting->suggested_data['application_methods'])->toHaveCount(1)
        ->and($posting->suggested_data['application_methods'][0]['type'])->toBe('external_url')
        ->and($posting->suggested_data['application_methods'][0]['platform'])->toBe('Gupy');
});

it('descarta a sugestão e volta para pending', function () {
    JobExtractionAgent::fake([[
        'role' => 'Dev',
        'skills' => ['Laravel'],
    ]]);

    $posting = JobPosting::factory()->for(auth()->user(), 'user')->create(['description' => flowDescription()]);
    app(JobAnalysisService::class)->analyze(auth()->user(), $posting);

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

        return ['role' => 'Dev', 'skills' => ['PHP']];
    });

    $posting = JobPosting::factory()->for(auth()->user(), 'user')->create(['description' => flowDescription()]);
    $service = app(JobAnalysisService::class);
    $user = auth()->user();

    $service->analyze($user, $posting);

    $posting->refresh();
    expect($posting->status)->toBe(JobPostingStatus::Failed)
        ->and($posting->error_message)->toBe('Não foi possível concluir a análise com IA. Tente novamente.')
        ->and($posting->suggested_data)->toBeNull()
        ->and($posting->attempts()->first()?->error_message)->toContain('Serviço indisponível');

    $service->analyze($user, $posting->refresh());

    $posting->refresh();
    expect($posting->status)->toBe(JobPostingStatus::NeedsReview)
        ->and($posting->error_message)->toBeNull()
        ->and($posting->suggested_data['items'])->toContain(['category' => 'skill', 'name' => 'PHP']);
});

it('vaga pendente/aguardando revisão não entra nas análises', function () {
    $user = auth()->user();
    JobPosting::factory()->for($user, 'user')->confirmed()->create(['description' => flowDescription()])
        ->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'ai']);

    JobPosting::factory()->for($user, 'user')->create(['description' => flowDescription()])
        ->items()->create(['category' => 'skill', 'name' => 'Python', 'source' => 'ai']);

    $frequencies = app(MarketAnalysisService::class)->frequencies($user);

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
