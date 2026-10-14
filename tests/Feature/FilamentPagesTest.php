<?php

use App\Agents\JobExtractionAgent;
use App\Agents\ResumeAdaptationAgent;
use App\Enums\JobPostingStatus;
use App\Enums\ResumeLanguage;
use App\Filament\Pages\CaptureSetupPage;
use App\Filament\Pages\JobSearchPage;
use App\Filament\Pages\MarketComparisonPage;
use App\Filament\Pages\MarketTrendsPage;
use App\Filament\Pages\ProfessionalCompetenciesPage;
use App\Filament\Pages\ResumePage;
use App\Filament\Pages\ScreeningPreferencesPage;
use App\Filament\Resources\JobCaptures\JobCaptureResource;
use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Filament\Resources\JobPostings\Pages\ApplicationPage;
use App\Filament\Resources\JobPostings\Pages\ListJobPostings;
use App\Filament\Resources\JobPostings\Pages\ViewJobPosting;
use App\Filament\Widgets\ComparisonSummaryWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Filament\Widgets\TopConceptsWidget;
use App\Filament\Widgets\TrendsChartWidget;
use App\Jobs\AnalyzeJobPosting;
use App\Jobs\ProcessAiOperation;
use App\Models\AiOperation;
use App\Models\JobPosting;
use App\Models\ResumeAdaptation;
use App\Models\User;
use App\Services\AiInputSnapshotService;
use App\Services\CaptureTokenService;
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
    JobPosting::factory()->for(auth()->user(), 'user')->create(['description' => longDescription()]);

    Livewire::test(ListJobPostings::class)
        ->assertSuccessful()
        ->assertSee('Vagas');
});

it('abre o modal de revisão com a sugestão', function () {
    JobExtractionAgent::fake([[
        'role' => 'Dev Python',
        'skills' => ['React', 'Python'],
    ]]);

    $posting = JobPosting::factory()->for(auth()->user(), 'user')->create(['description' => longDescription()]);
    app(JobAnalysisService::class)->analyze(auth()->user(), $posting);

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

    $posting = JobPosting::factory()->for(auth()->user(), 'user')->create([
        'description' => '<p><strong>Vaga</strong> para dev com <em>Laravel</em>.</p>',
    ]);
    app(JobAnalysisService::class)->analyze(auth()->user(), $posting);

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

    expect(app(ResumeService::class)->content(auth()->user(), ResumeLanguage::PtBr))->toBe('Currículo com Python e Docker.');
});

it('renderiza a comparação mercado × currículo com dados do PRD', function () {
    $p1 = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);
    $p1->items()->createMany([
        ['category' => 'skill', 'name' => 'Python', 'source' => 'ai'],
        ['category' => 'skill', 'name' => 'React', 'source' => 'ai'],
    ]);
    $p2 = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);
    $p2->items()->create(['category' => 'skill', 'name' => 'Python', 'source' => 'ai']);

    app(ResumeService::class)->save(auth()->user(), 'Currículo com Python.', ResumeLanguage::PtBr);

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
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create([
        'description' => longDescription(),
        'collected_at' => now()->subMonths(2),
    ]);
    $posting->items()->create(['category' => 'skill', 'name' => 'Python', 'source' => 'ai']);

    $posting2 = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create([
        'description' => longDescription(),
        'collected_at' => now()->subMonth(),
    ]);
    $posting2->items()->create(['category' => 'skill', 'name' => 'Python', 'source' => 'ai']);

    Livewire::test(MarketTrendsPage::class)
        ->assertSuccessful()
        ->assertDontSee('Dados insuficientes');
});

it('widgets exibem dados corretos', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);
    $posting->items()->create(['category' => 'skill', 'name' => 'Python', 'source' => 'ai']);
    JobPosting::factory()->for(auth()->user(), 'user')->needsReview()->create(['description' => longDescription()]);

    Livewire::test(StatsOverviewWidget::class)
        ->assertSee('2')
        ->assertSee('Aguardando revisão');

    Livewire::test(TopConceptsWidget::class)
        ->assertSee('Python');

    app(ResumeService::class)->save(auth()->user(), 'Currículo sem menção a nenhuma tecnologia.', ResumeLanguage::PtBr);

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

    $posting = JobPosting::factory()->for(auth()->user(), 'user')->failed()->create(['description' => longDescription()]);

    Livewire::test(ListJobPostings::class)
        ->callTableAction('analyze', $posting);

    Queue::assertPushed(AnalyzeJobPosting::class, fn (AnalyzeJobPosting $job) => $job->posting->is($posting) && $job->userId === auth()->id() && $job->trigger === 'manual_retry');

    // A análise roda em segundo plano; o estado só muda quando o job executa.
    $posting->refresh();
    expect($posting->status)->toBe(JobPostingStatus::Failed);

    (new AnalyzeJobPosting($posting, auth()->id(), 'manual_retry'))->handle(app(JobAnalysisService::class));

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::NeedsReview)
        ->and($posting->suggested_data)->not->toBeNull();
});

it('mostra a tentativa novamente somente em vagas com falha', function () {
    $pending = JobPosting::factory()->for(auth()->user(), 'user')->create(['description' => longDescription()]);
    $confirmed = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);
    $needsReview = JobPosting::factory()->for(auth()->user(), 'user')->needsReview()->create(['description' => longDescription()]);
    $failed = JobPosting::factory()->for(auth()->user(), 'user')->failed()->create(['description' => longDescription()]);

    Livewire::test(ListJobPostings::class)
        ->assertTableActionHidden('analyze', $pending)
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
    $confirmed = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);
    $pending = JobPosting::factory()->for(auth()->user(), 'user')->create(['description' => longDescription()]);
    $needsReview = JobPosting::factory()->for(auth()->user(), 'user')->needsReview()->create(['description' => longDescription()]);

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

    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);
    $posting->items()->createMany([
        ['category' => 'skill', 'name' => 'Python', 'source' => 'ai'],
        ['category' => 'skill', 'name' => 'React', 'source' => 'ai'],
    ]);
    app(ResumeService::class)->save(auth()->user(), 'Currículo com Python.', ResumeLanguage::PtBr);

    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertSee('Currículo original')
        ->callAction('adaptPtBr')
        ->assertSuccessful()
        ->assertSet('data.adapted_resume', "Currículo adaptado.\nDestaque para Python.")
        ->assertSee('Currículo adaptado');

    expect(ResumeAdaptation::where('job_posting_id', $posting->id)->exists())->toBeTrue();
});

it('renderiza o currículo adaptado como texto na adequação', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => "Currículo adaptado.\nDestaque para Python.",
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertSet('data.adapted_resume', "Currículo adaptado.\nDestaque para Python.");
});

it('renderiza o currículo original como texto na adequação', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);
    app(ResumeService::class)->save(auth()->user(), '<p><strong>Currículo</strong> com <em>Laravel</em>.</p>', ResumeLanguage::PtBr);

    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertSet('data.original_resume', 'Currículo com Laravel.');
});

it('expõe o link de download do PDF na página', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Currículo adaptado para a vaga.',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertActionHasUrl('downloadPdf', route('filament.admin.resume-pdf.download', ['posting' => $posting, 'language' => 'pt-BR']));
});

it('baixa o PDF da adaptação pela rota dedicada', function () {
    Pdf::fake();

    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Currículo adaptado para a vaga.',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    $this->get(route('filament.admin.resume-pdf.download', $posting))
        ->assertOk();

    Pdf::assertRespondedWithPdf(fn (PdfBuilder $pdf) => $pdf->viewName === 'pdf.adapted-resume');
});

it('existe e está registrado em /job-postings/{record}/application', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);

    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->assertSuccessful();
});

it('renderiza a página de view com a descrição rica', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->create([
        'description' => '<p><strong>Vaga</strong> para dev com <em>Laravel</em>.</p>',
    ]);

    Livewire::test(ViewJobPosting::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertSeeHtml('<strong>Vaga</strong>');
});

it('a ação de Candidatura aparece na listagem para vagas confirmadas e esconde fora desse status', function () {
    $confirmed = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);
    $pending = JobPosting::factory()->for(auth()->user(), 'user')->create(['description' => longDescription()]);
    $needsReview = JobPosting::factory()->for(auth()->user(), 'user')->needsReview()->create(['description' => longDescription()]);

    Livewire::test(ListJobPostings::class)
        ->assertTableActionVisible('application', $confirmed)
        ->assertTableActionHidden('application', $pending)
        ->assertTableActionHidden('application', $needsReview)
        ->assertSeeHtml('href="'.ApplicationPage::getUrl(['record' => $confirmed]).'"');
});

it('ApplicationPage redireciona para a listagem em vaga não confirmada', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->create(['description' => longDescription()]);

    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->assertRedirect(ListJobPostings::getUrl());
});

it('ApplicationPage renderiza os métodos confirmados com origem, plataforma, URL e e-mail', function () {
    $confirmed = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create([
        'description' => longDescription(),
        'source_url' => 'https://www.linkedin.com/jobs/view/999',
    ]);
    $confirmed->applicationMethods()->createMany([
        ['type' => 'email', 'email' => 'vagas@empresa.com', 'source' => 'ai', 'dedup_key' => str_repeat('a', 64)],
        ['type' => 'external_url', 'url' => 'https://empresa.gupy.io/jobs/123', 'platform' => 'Gupy', 'source' => 'user', 'dedup_key' => str_repeat('b', 64)],
    ]);

    Livewire::test(ApplicationPage::class, ['record' => $confirmed->id])
        ->assertSuccessful()
        ->assertSee('Como se candidatar')
        ->assertSee('E-mail')
        ->assertSee('vagas@empresa.com')
        ->assertSee('Gupy')
        ->assertSee('https://empresa.gupy.io/jobs/123')
        // Link da vaga original na seção "Vaga".
        ->assertSeeHtml('href="https://www.linkedin.com/jobs/view/999"');
});

it('ApplicationPage mostra mensagem de ausência quando não há métodos', function () {
    $confirmed = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);

    Livewire::test(ApplicationPage::class, ['record' => $confirmed->id])
        ->assertSuccessful()
        ->assertSee('Forma de candidatura não identificada.');
});

it('ViewJobPosting não exibe mais a seção de candidatura', function () {
    $confirmed = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);
    $confirmed->applicationMethods()->create([
        'type' => 'email',
        'email' => 'vagas@empresa.com',
        'source' => 'ai',
        'dedup_key' => str_repeat('c', 64),
    ]);

    Livewire::test(ViewJobPosting::class, ['record' => $confirmed->id])
        ->assertSuccessful()
        ->assertSee('Descrição da vaga')
        ->assertDontSee('Como se candidatar')
        ->assertDontSee('vagas@empresa.com');
});

it('ApplicationPage sem currículo cadastrado mostra empty state e continua abrindo', function () {
    $confirmed = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);

    Livewire::test(ApplicationPage::class, ['record' => $confirmed->id])
        ->assertSuccessful()
        ->assertSee('Nenhum currículo cadastrado');
});

it('ApplicationPage sem adaptação mostra empty state de adequação', function () {
    app(ResumeService::class)->save(auth()->user(), 'Currículo base.', ResumeLanguage::PtBr);
    $confirmed = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);

    Livewire::test(ApplicationPage::class, ['record' => $confirmed->id])
        ->assertSuccessful()
        ->assertSee('Nenhuma adequação gerada');
});

it('manageApplicationMethods cria métodos manuais com source user e dedup_key', function () {
    $confirmed = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);

    Livewire::test(ApplicationPage::class, ['record' => $confirmed->id])
        ->callAction('manageApplicationMethods', [
            'application_methods' => [
                ['type' => 'email', 'email' => 'recrutamento@empresa.com', 'platform' => null, 'url' => null, 'phone' => null, 'instructions' => null],
                ['type' => 'external_url', 'platform' => 'Gupy', 'url' => 'https://empresa.gupy.io/jobs/42', 'email' => null, 'phone' => null, 'instructions' => 'Anexar portfólio'],
            ],
        ])
        ->assertHasNoActionErrors();

    $methods = $confirmed->refresh()->applicationMethods;

    expect($methods)->toHaveCount(2)
        ->and($methods->where('source', 'user'))->toHaveCount(2)
        ->and($methods->where('type', 'email')->first()->email)->toBe('recrutamento@empresa.com')
        ->and($methods->where('type', 'external_url')->first()->platform)->toBe('Gupy')
        ->and($methods->where('type', 'external_url')->first()->dedup_key)->not->toBeEmpty();

    // suggested_data deve permanecer intocado (não foi passado na operação manual).
    expect($confirmed->suggested_data)->toBeNull();
});

it('manageApplicationMethods edita métodos existentes preservando os não tocados', function () {
    $confirmed = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);
    $email = $confirmed->applicationMethods()->create([
        'type' => 'email',
        'email' => 'old@empresa.com',
        'source' => 'ai',
        'dedup_key' => str_repeat('a', 64),
    ]);
    $external = $confirmed->applicationMethods()->create([
        'type' => 'external_url',
        'url' => 'https://empresa.gupy.io/jobs/1',
        'platform' => 'Gupy',
        'source' => 'user',
        'dedup_key' => str_repeat('b', 64),
    ]);

    Livewire::test(ApplicationPage::class, ['record' => $confirmed->id])
        ->callAction('manageApplicationMethods', [
            'application_methods' => [
                ['type' => 'email', 'email' => 'new@empresa.com', 'platform' => null, 'url' => null, 'phone' => null, 'instructions' => 'Atualizado'],
                ['type' => 'external_url', 'platform' => 'Gupy', 'url' => 'https://empresa.gupy.io/jobs/1', 'email' => null, 'phone' => null, 'instructions' => null],
            ],
        ])
        ->assertHasNoActionErrors();

    $methods = $confirmed->refresh()->applicationMethods;

    expect($methods)->toHaveCount(2)
        ->and($methods->where('type', 'email')->first()->email)->toBe('new@empresa.com')
        ->and($methods->where('type', 'email')->first()->instructions)->toBe('Atualizado')
        ->and($methods->where('type', 'external_url')->first()->url)->toBe('https://empresa.gupy.io/jobs/1');
});
it('manageApplicationMethods com lista vazia remove apenas os métodos da vaga', function () {
    $a = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);
    $b = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);
    $a->applicationMethods()->create([
        'type' => 'email', 'email' => 'a@empresa.com', 'source' => 'ai', 'dedup_key' => str_repeat('a', 64),
    ]);
    $b->applicationMethods()->create([
        'type' => 'email', 'email' => 'b@empresa.com', 'source' => 'ai', 'dedup_key' => str_repeat('b', 64),
    ]);
    $adaptation = ResumeAdaptation::create([
        'job_posting_id' => $a->id,
        'adapted_content' => 'Texto adaptado preservado.',
    ]);

    Livewire::test(ApplicationPage::class, ['record' => $a->id])
        ->callAction('manageApplicationMethods', [
            'application_methods' => [],
        ])
        ->assertHasNoActionErrors();

    expect($a->refresh()->applicationMethods)->toHaveCount(0)
        ->and($b->refresh()->applicationMethods)->toHaveCount(1)
        ->and(ResumeAdaptation::find($adaptation->id))->not->toBeNull()
        ->and(ResumeAdaptation::find($adaptation->id)->adapted_content)->toBe('Texto adaptado preservado.');
});

it('manageApplicationMethods rejeita URL sem esquema http(s) e não grava', function () {
    $confirmed = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);

    Livewire::test(ApplicationPage::class, ['record' => $confirmed->id])
        ->callAction('manageApplicationMethods', [
            'application_methods' => [
                ['type' => 'external_url', 'platform' => 'Gupy', 'url' => 'empresa.gupy.io/jobs/1', 'email' => null, 'phone' => null, 'instructions' => null],
            ],
        ])
        ->assertHasFormErrors(['application_methods.0.url']);

    expect($confirmed->refresh()->applicationMethods)->toHaveCount(0);
});

it('manageApplicationMethods rejeita e-mail inválido e não grava', function () {
    $confirmed = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);

    Livewire::test(ApplicationPage::class, ['record' => $confirmed->id])
        ->callAction('manageApplicationMethods', [
            'application_methods' => [
                ['type' => 'email', 'email' => 'nao-eh-email', 'platform' => null, 'url' => null, 'phone' => null, 'instructions' => null],
            ],
        ])
        ->assertHasFormErrors(['application_methods.0.email']);

    expect($confirmed->refresh()->applicationMethods)->toHaveCount(0);
});

it('ApplicationPage abre uma vaga confirmada sem afetar currículo ou métodos existentes ao entrar', function () {
    $confirmed = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);
    $confirmed->applicationMethods()->createMany([
        ['type' => 'email', 'email' => 'a@empresa.com', 'source' => 'user', 'dedup_key' => str_repeat('a', 64)],
    ]);
    ResumeAdaptation::create([
        'job_posting_id' => $confirmed->id,
        'adapted_content' => 'Versão adaptada preservada.',
        'changes' => ['Resumo reescrito'],
    ]);

    Livewire::test(ApplicationPage::class, ['record' => $confirmed->id])
        ->assertSuccessful();

    $confirmed->refresh();
    expect($confirmed->applicationMethods)->toHaveCount(1)
        ->and($confirmed->resumeAdaptation(ResumeLanguage::PtBr)->first()?->adapted_content)->toBe('Versão adaptada preservada.');
});

it('permite visualizar vaga aguardando revisão no detalhe', function () {
    $needsReview = JobPosting::factory()->for(auth()->user(), 'user')->needsReview()->create(['description' => longDescription()]);

    Livewire::test(ViewJobPosting::class, ['record' => $needsReview->id])
        ->assertSuccessful();
});

it('permite visualizar vaga com falha para diagnóstico', function () {
    $failed = JobPosting::factory()->for(auth()->user(), 'user')->failed()->create(['description' => longDescription()]);

    Livewire::test(ViewJobPosting::class, ['record' => $failed->id])
        ->assertSuccessful()
        ->assertSee('Falha simulada');
});

it('permite visualizar vagas pendentes e confirmadas', function () {
    $pending = JobPosting::factory()->for(auth()->user(), 'user')->create(['description' => longDescription()]);
    $confirmed = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);

    Livewire::test(ViewJobPosting::class, ['record' => $pending->id])->assertSuccessful();
    Livewire::test(ViewJobPosting::class, ['record' => $confirmed->id])->assertSuccessful();
});

it('renderiza a página de onboarding da extensão com os quatro passos e máscara quando há token', function () {
    $service = app(CaptureTokenService::class);
    $service->issueToken(auth()->user());

    $page = Livewire::test(CaptureSetupPage::class)
        ->assertSuccessful()
        ->assertSee('Instale a extensão')
        ->assertSee('Sua credencial')
        ->assertSee('Cole no popup da extensão')
        ->assertSee('Valide e conecte');

    // Máscara deve aparecer; token completo só após geração, não na carga inicial.
    expect($page->html())->toContain('•')
        ->and($page->html())->not->toContain('VAGAS_API_TOKEN');
});

it('mostra estado sem credencial quando usuário não tem token', function () {
    // Garante sem token
    auth()->user()->forceFill(['capture_token_hash' => null, 'capture_token_last_four' => null])->save();

    Livewire::test(CaptureSetupPage::class)
        ->assertSuccessful()
        ->assertSee('Você ainda não possui credencial')
        ->assertSee('Gerar credencial');
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
        ProfessionalCompetenciesPage::class,
        MarketComparisonPage::class,
        MarketTrendsPage::class,
    ]);
});

it('gera a versão em inglês a partir das abas do currículo', function () {
    Queue::fake([ProcessAiOperation::class]);
    app(ResumeService::class)->save(auth()->user(), 'Currículo com Python.', ResumeLanguage::PtBr);

    Livewire::test(ResumePage::class)
        ->assertSuccessful()
        ->assertSee('PT-BR')
        ->assertSee('EN')
        ->callAction('generateEnglish')
        ->assertNotified();

    expect(AiOperation::query()->where('operation_type', 'resume_translation')->count())->toBe(1);
});

it('recusa gerar a versão em inglês sem o currículo pt-BR', function () {
    Livewire::test(ResumePage::class)
        ->callAction('generateEnglish')
        ->assertNotified();

    expect(AiOperation::query()->count())->toBe(0);
});

it('salva a versão em inglês pela aba EN', function () {
    app(ResumeService::class)->save(auth()->user(), 'Currículo com Python.', ResumeLanguage::PtBr);

    Livewire::test(ResumePage::class)
        ->set('activeTab', 'en')
        ->fillForm(['content_en' => 'English resume with Python.'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(ResumeService::class)->content(auth()->user(), ResumeLanguage::En))->toBe('English resume with Python.');
});

it('pede confirmação ao regenerar com a versão em inglês existente', function () {
    Queue::fake([ProcessAiOperation::class]);
    app(ResumeService::class)->save(auth()->user(), 'Currículo com Python.', ResumeLanguage::PtBr);
    app(ResumeService::class)->save(auth()->user(), 'Existing english.', ResumeLanguage::En);

    $component = Livewire::test(ResumePage::class)
        ->mountAction('generateEnglish');

    expect(AiOperation::query()->count())->toBe(0);

    $component->callMountedAction();

    expect(AiOperation::query()->count())->toBe(1);
});

it('abre a Candidatura em PT-BR e troca o currículo original pelo seletor de idioma', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);
    app(ResumeService::class)->save(auth()->user(), 'Currículo em português.', ResumeLanguage::PtBr);
    app(ResumeService::class)->save(auth()->user(), 'English resume.', ResumeLanguage::En);

    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertSet('resumeLanguage', 'pt-BR')
        ->assertSet('data.original_resume', 'Currículo em português.')
        ->set('resumeLanguage', 'en')
        ->assertSet('resumeLanguage', 'en')
        ->assertSet('data.original_resume', 'English resume.');
});

it('mostra o dropdown de adequação com um item por idioma e indica "novamente" quando já há adaptação', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);
    app(ResumeService::class)->save(auth()->user(), 'Currículo em português.', ResumeLanguage::PtBr);
    app(ResumeService::class)->save(auth()->user(), 'English resume.', ResumeLanguage::En);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'language' => 'pt-BR',
        'adapted_content' => 'Adaptação PT.',
    ]);

    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertSee('Adequar novamente em Português (PT-BR)')
        ->assertSee('Adequar em Inglês (EN)');
});

it('desabilita o item Inglês sem a versão EN e oferece a orientação e o link para o Currículo', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);
    app(ResumeService::class)->save(auth()->user(), 'Currículo em português.', ResumeLanguage::PtBr);

    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertActionDisabled('adaptEn')
        ->assertSee('Não há currículo em inglês')
        ->assertSeeHtml('href="'.ResumePage::getUrl().'"');

    app(ResumeService::class)->save(auth()->user(), 'English resume.', ResumeLanguage::En);

    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->assertActionEnabled('adaptEn');
});

it('adequar em inglês preserva a adaptação PT-BR da mesma vaga', function () {
    ResumeAdaptationAgent::fake([[
        'adapted_resume' => 'English adapted.',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]]);

    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);
    app(ResumeService::class)->save(auth()->user(), 'Currículo em português.', ResumeLanguage::PtBr);
    app(ResumeService::class)->save(auth()->user(), 'English resume.', ResumeLanguage::En);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'language' => 'pt-BR',
        'adapted_content' => 'Adaptação PT preservada.',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->callAction('adaptEn')
        ->assertNotified()
        ->assertSet('resumeLanguage', 'en');

    $adaptations = ResumeAdaptation::where('job_posting_id', $posting->id)->get();

    expect($adaptations)->toHaveCount(2)
        ->and($adaptations->firstWhere('language', ResumeLanguage::PtBr)->adapted_content)->toBe('Adaptação PT preservada.')
        ->and($adaptations->firstWhere('language', ResumeLanguage::En)->adapted_content)->toBe('English adapted.');
});

it('mostra a adequação e o empty state por idioma conforme o seletor', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);
    app(ResumeService::class)->save(auth()->user(), 'Currículo em português.', ResumeLanguage::PtBr);
    app(ResumeService::class)->save(auth()->user(), 'English resume.', ResumeLanguage::En);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'language' => 'pt-BR',
        'adapted_content' => 'Adaptação PT.',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->assertSet('resumeLanguage', 'pt-BR')
        ->assertSet('data.adapted_resume', 'Adaptação PT.')
        ->assertDontSee('Nenhuma adequação gerada')
        ->set('resumeLanguage', 'en')
        ->assertSet('data.adapted_resume', '')
        ->assertSee('Nenhuma adequação gerada')
        ->assertSee('Ainda não há adequação em Inglês (EN)');
});

it('o download do PDF segue o idioma selecionado', function () {
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create(['description' => longDescription()]);
    app(ResumeService::class)->save(auth()->user(), 'Currículo em português.', ResumeLanguage::PtBr);
    app(ResumeService::class)->save(auth()->user(), 'English resume.', ResumeLanguage::En);
    ResumeAdaptation::create(['job_posting_id' => $posting->id, 'language' => 'pt-BR', 'adapted_content' => 'PT.']);
    ResumeAdaptation::create(['job_posting_id' => $posting->id, 'language' => 'en', 'adapted_content' => 'EN.']);

    Livewire::test(ApplicationPage::class, ['record' => $posting->id])
        ->assertActionHasUrl('downloadPdf', route('filament.admin.resume-pdf.download', ['posting' => $posting, 'language' => 'pt-BR']))
        ->set('resumeLanguage', 'en')
        ->assertActionHasUrl('downloadPdf', route('filament.admin.resume-pdf.download', ['posting' => $posting, 'language' => 'en']));
});

it('avisa que a versão EN está desatualizada quando o pt-BR muda', function () {
    $user = auth()->user();
    app(ResumeService::class)->save($user, 'Currículo com Python.', ResumeLanguage::PtBr);
    $fingerprint = app(AiInputSnapshotService::class)->resumeTranslation($user)['fingerprint'];
    app(ResumeService::class)->saveTranslation($user, 'English resume with Python.', $fingerprint);

    Livewire::test(ResumePage::class)
        ->set('activeTab', 'en')
        ->assertDontSee('desatualizada');

    app(ResumeService::class)->save($user, 'Currículo atualizado com Python e Docker.', ResumeLanguage::PtBr);

    Livewire::test(ResumePage::class)
        ->set('activeTab', 'en')
        ->assertSee('desatualizada');
});
