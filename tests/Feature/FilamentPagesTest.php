<?php

use App\Agents\JobExtractionAgent;
use App\Agents\ResumeAdaptationAgent;
use App\Enums\JobPostingStatus;
use App\Filament\Pages\CaptureSetupPage;
use App\Filament\Pages\JobSearchPage;
use App\Filament\Pages\MarketComparisonPage;
use App\Filament\Pages\MarketTrendsPage;
use App\Filament\Pages\ProfessionalCompetenciesPage;
use App\Filament\Pages\ResumePage;
use App\Filament\Pages\ScreeningPreferencesPage;
use App\Filament\Resources\JobCaptures\JobCaptureResource;
use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Filament\Resources\JobPostings\Pages\ListJobPostings;
use App\Filament\Resources\JobPostings\Pages\ResumeAdaptationPage;
use App\Filament\Resources\JobPostings\Pages\ViewJobPosting;
use App\Filament\Widgets\ComparisonSummaryWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Filament\Widgets\TopConceptsWidget;
use App\Filament\Widgets\TrendsChartWidget;
use App\Jobs\AnalyzeJobPosting;
use App\Models\JobPosting;
use App\Models\ResumeAdaptation;
use App\Models\User;
use App\Services\JobAnalysisService;
use App\Services\ResumeService;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;
use Tiptap\Editor;

uses(RefreshDatabase::class);

// As páginas do painel exigem usuário autenticado (policy + middleware do painel).
beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

function longDescription(): string
{
    return 'Vaga para desenvolvedor Python com React. Requisitos: Docker e AWS.'.str_repeat(' ', 20);
}

it('renderiza a listagem de vagas', function () {
    JobPosting::factory()->create(['description' => longDescription()]);

    Livewire::test(ListJobPostings::class)
        ->assertSuccessful()
        ->assertSee('Vagas');
});

it('abre o modal de revisão com a sugestão', function () {
    JobExtractionAgent::fake([[
        'role' => 'Dev Python',
        'skills' => ['React', 'Python'],
    ]]);

    $posting = JobPosting::factory()->create(['description' => longDescription()]);
    app(JobAnalysisService::class)->analyze($posting);

    Livewire::test(ListJobPostings::class)
        ->mountTableAction('review', $posting)
        ->assertMountedActionModalSee('Descrição original')
        ->assertSet('mountedActions.0.data.role', 'Dev Python');
});

it('renderiza a descrição original como conteúdo rico na revisão', function () {
    JobExtractionAgent::fake([[
        'role' => 'Dev Python',
        'skills' => ['React', 'Python'],
    ]]);

    $posting = JobPosting::factory()->create([
        'description' => '<p><strong>Vaga</strong> para dev com <em>Laravel</em>.</p>',
    ]);
    app(JobAnalysisService::class)->analyze($posting);

    Livewire::test(ListJobPostings::class)
        ->mountTableAction('review', $posting)
        ->assertSet('mountedActions.0.data.description', fn (mixed $value): bool => is_array($value)
            && str_contains((new Editor)->setContent($value)->getHTML(), '<strong>Vaga</strong>'));
});

it('renderiza a página do currículo e salva o texto', function () {
    Livewire::test(ResumePage::class)
        ->assertSuccessful()
        ->fillForm([
            'content' => 'Currículo com Python e Docker.',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSet('data.content', fn (mixed $value): bool => is_string($value));

    expect(app(ResumeService::class)->content())->toBe('Currículo com Python e Docker.');
});

it('renderiza a comparação mercado × currículo com dados do PRD', function () {
    $p1 = JobPosting::factory()->confirmed()->create(['description' => longDescription()]);
    $p1->items()->createMany([
        ['category' => 'skill', 'name' => 'Python', 'source' => 'ai'],
        ['category' => 'skill', 'name' => 'React', 'source' => 'ai'],
    ]);
    $p2 = JobPosting::factory()->confirmed()->create(['description' => longDescription()]);
    $p2->items()->create(['category' => 'skill', 'name' => 'Python', 'source' => 'ai']);

    app(ResumeService::class)->save('Currículo com Python.');

    $component = Livewire::test(MarketComparisonPage::class);

    $component->assertSuccessful()
        ->assertSee('Python')
        ->assertSee('React');

    $rows = collect($component->instance()->getRows());

    expect($rows->firstWhere('name', 'Python')['present'])->toBeTrue()
        ->and($rows->firstWhere('name', 'React')['present'])->toBeFalse()
        ->and($rows->firstWhere('name', 'React')['is_gap'])->toBeTrue();
});

it('renderiza as tendências com série mensal', function () {
    $posting = JobPosting::factory()->confirmed()->create([
        'description' => longDescription(),
        'collected_at' => now()->subMonths(2),
    ]);
    $posting->items()->create(['category' => 'skill', 'name' => 'Python', 'source' => 'ai']);

    $posting2 = JobPosting::factory()->confirmed()->create([
        'description' => longDescription(),
        'collected_at' => now()->subMonth(),
    ]);
    $posting2->items()->create(['category' => 'skill', 'name' => 'Python', 'source' => 'ai']);

    Livewire::test(MarketTrendsPage::class)
        ->assertSuccessful()
        ->assertDontSee('Dados insuficientes');
});

it('widgets exibem dados corretos', function () {
    $posting = JobPosting::factory()->confirmed()->create(['description' => longDescription()]);
    $posting->items()->create(['category' => 'skill', 'name' => 'Python', 'source' => 'ai']);
    JobPosting::factory()->needsReview()->create(['description' => longDescription()]);

    Livewire::test(StatsOverviewWidget::class)
        ->assertSee('2')
        ->assertSee('Aguardando revisão');

    Livewire::test(TopConceptsWidget::class)
        ->assertSee('Python');

    app(ResumeService::class)->save('Currículo sem menção a nenhuma tecnologia.');

    Livewire::test(ComparisonSummaryWidget::class)
        ->assertSee('Python');

    $chart = Livewire::test(TrendsChartWidget::class);

    expect($chart->instance()->getData()['labels'])->toBeArray()
        ->and($chart->instance()->getData()['datasets'])->not->toBeEmpty();
});

it('ações da listagem mudam o estado da vaga', function () {
    Queue::fake();

    JobExtractionAgent::fake([[
        'role' => 'Dev',
        'skills' => ['Python'],
    ]]);

    $posting = JobPosting::factory()->create(['description' => longDescription()]);

    Livewire::test(ListJobPostings::class)
        ->callTableAction('analyze', $posting);

    Queue::assertPushed(AnalyzeJobPosting::class, fn (AnalyzeJobPosting $job) => $job->posting->is($posting) && $job->userId === auth()->id());

    // A análise roda em segundo plano; o estado só muda quando o job executa.
    $posting->refresh();
    expect($posting->status)->toBe(JobPostingStatus::Pending);

    (new AnalyzeJobPosting($posting, auth()->id()))->handle(app(JobAnalysisService::class));

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::NeedsReview)
        ->and($posting->suggested_data)->not->toBeNull();
});

it('mostra a análise com IA somente em vagas pendentes ou com falha', function () {
    $pending = JobPosting::factory()->create(['description' => longDescription()]);
    $confirmed = JobPosting::factory()->confirmed()->create(['description' => longDescription()]);
    $needsReview = JobPosting::factory()->needsReview()->create(['description' => longDescription()]);
    $failed = JobPosting::factory()->failed()->create(['description' => longDescription()]);

    Livewire::test(ListJobPostings::class)
        ->assertTableActionVisible('analyze', $pending)
        ->assertTableActionVisible('analyze', $failed)
        ->assertTableActionHidden('analyze', $confirmed)
        ->assertTableActionHidden('analyze', $needsReview);
});

it('widget de tendências lida com base vazia', function () {
    $chart = Livewire::test(TrendsChartWidget::class);

    expect($chart->instance()->getData()['labels'])->toBe([])
        ->and($chart->instance()->getData()['datasets'])->toBe([])
        ->and($chart->instance()->getDescription())->toContain('Sem dados suficientes');
});

it('esconde a ação revisar fora de vagas aguardando revisão', function () {
    $confirmed = JobPosting::factory()->confirmed()->create(['description' => longDescription()]);
    $pending = JobPosting::factory()->create(['description' => longDescription()]);
    $needsReview = JobPosting::factory()->needsReview()->create(['description' => longDescription()]);

    Livewire::test(ListJobPostings::class)
        ->assertTableActionHidden('review', $confirmed)
        ->assertTableActionHidden('review', $pending)
        ->assertTableActionVisible('review', $needsReview);
});

it('renderiza a página de adequação e gera a adaptação pela ação', function () {
    ResumeAdaptationAgent::fake([[
        'adapted_resume' => "Currículo adaptado.\nDestaque para Python.",
        'changes' => ['Resumo reescrito'],
        'covered_keywords' => ['Python'],
        'missing_requirements' => ['React'],
        'alerts' => [],
    ]]);

    $posting = JobPosting::factory()->confirmed()->create(['description' => longDescription()]);
    $posting->items()->createMany([
        ['category' => 'skill', 'name' => 'Python', 'source' => 'ai'],
        ['category' => 'skill', 'name' => 'React', 'source' => 'ai'],
    ]);
    app(ResumeService::class)->save('Currículo com Python.');

    Livewire::test(ResumeAdaptationPage::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertSee('Currículo original')
        ->callAction('adapt')
        ->assertSuccessful()
        ->assertSet('data.adapted_resume', "Currículo adaptado.\nDestaque para Python.")
        ->assertSee('Currículo adaptado');

    expect(ResumeAdaptation::where('job_posting_id', $posting->id)->exists())->toBeTrue();
});

it('renderiza o currículo adaptado como texto na adequação', function () {
    $posting = JobPosting::factory()->confirmed()->create(['description' => longDescription()]);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => "Currículo adaptado.\nDestaque para Python.",
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    Livewire::test(ResumeAdaptationPage::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertSet('data.adapted_resume', "Currículo adaptado.\nDestaque para Python.");
});

it('renderiza o currículo original como texto na adequação', function () {
    $posting = JobPosting::factory()->confirmed()->create(['description' => longDescription()]);
    app(ResumeService::class)->save('<p><strong>Currículo</strong> com <em>Laravel</em>.</p>');

    Livewire::test(ResumeAdaptationPage::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertSet('data.original_resume', 'Currículo com Laravel.');
});

it('expõe o link de download do PDF na página', function () {
    $posting = JobPosting::factory()->confirmed()->create(['description' => longDescription()]);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Currículo adaptado para a vaga.',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    Livewire::test(ResumeAdaptationPage::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertActionHasUrl('downloadPdf', route('filament.admin.resume-pdf.download', $posting));
});

it('baixa o PDF da adaptação pela rota dedicada', function () {
    Pdf::fake();

    $posting = JobPosting::factory()->confirmed()->create(['description' => longDescription()]);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Currículo adaptado para a vaga.',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('filament.admin.resume-pdf.download', $posting))
        ->assertOk();

    Pdf::assertRespondedWithPdf(fn (PdfBuilder $pdf) => $pdf->viewName === 'pdf.adapted-resume');
});

it('redireciona para a listagem ao abrir a adequação de vaga não confirmada', function () {
    $posting = JobPosting::factory()->create(['description' => longDescription()]);

    Livewire::test(ResumeAdaptationPage::class, ['record' => $posting->id])
        ->assertRedirect(ListJobPostings::getUrl());
});

it('renderiza a página de view com a descrição rica', function () {
    $posting = JobPosting::factory()->create([
        'description' => '<p><strong>Vaga</strong> para dev com <em>Laravel</em>.</p>',
    ]);

    Livewire::test(ViewJobPosting::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertSeeHtml('<strong>Vaga</strong>');
});

it('redireciona para a listagem ao abrir a view de vaga já enviada para análise', function () {
    $confirmed = JobPosting::factory()->confirmed()->create(['description' => longDescription()]);
    $failed = JobPosting::factory()->failed()->create(['description' => longDescription()]);

    Livewire::test(ViewJobPosting::class, ['record' => $confirmed->id])
        ->assertRedirect(ListJobPostings::getUrl());

    Livewire::test(ViewJobPosting::class, ['record' => $failed->id])
        ->assertRedirect(ListJobPostings::getUrl());
});

it('mostra a ação ver somente em vagas pendentes', function () {

    $pending = JobPosting::factory()->create(['description' => longDescription()]);
    $confirmed = JobPosting::factory()->confirmed()->create(['description' => longDescription()]);

    Livewire::test(ListJobPostings::class)
        ->assertTableActionVisible('view', $pending)
        ->assertTableActionHidden('view', $confirmed);
});

it('renderiza a página de onboarding da extensão com os quatro passos e o token', function () {
    config(['capture.api_token' => 'token-secreto-1234']);

    $page = Livewire::test(CaptureSetupPage::class)
        ->assertSuccessful()
        ->assertSee('Instale a extensão')
        ->assertSee('Copie o token')
        ->assertSee('Cole no popup da extensão')
        ->assertSee('Valide e conecte')
        ->assertSee('Jobora Job Capture');

    // Token mascarado visível por padrão; valor integral no bloco
    // x-show="revealed" e no handler de cópia (Js::from).
    expect($page->html())->toContain('••••1234')
        ->and(substr_count($page->html(), 'token-secreto-1234'))->toBe(2);
});
it('informa que a API não está configurada quando o token é vazio', function () {
    config(['capture.api_token' => '']);

    Livewire::test(CaptureSetupPage::class)
        ->assertSuccessful()
        ->assertSee('API não configurada')
        ->assertSee('VAGAS_API_TOKEN')
        ->assertDontSee('Copie o token');
});
it('exibe a marca Jobora no painel', function () {
    $this->get('/')->assertSuccessful()->assertSee('Jobora');
});

it('ordena a navegação lateral conforme o fluxo do produto', function () {
    $panel = Filament::getPanel('admin');

    expect($panel)->not->toBeNull();

    $keys = collect($panel->getNavigation())
        ->flatMap(fn ($group) => $group->getItems())
        ->map(fn ($item) => $item->getKey())
        ->values()
        ->all();

    expect($keys)->toBe([
        Dashboard::class,
        JobSearchPage::class,
        CaptureSetupPage::class,
        JobCaptureResource::class,
        JobPostingResource::class,
        ResumePage::class,
        ScreeningPreferencesPage::class,
        MarketComparisonPage::class,
        MarketTrendsPage::class,
        ProfessionalCompetenciesPage::class,
    ]);
});
