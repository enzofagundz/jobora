<?php

use App\Agents\JobExtractionAgent;
use App\Enums\JobPostingStatus;
use App\Filament\Pages\MarketComparisonPage;
use App\Filament\Pages\MarketTrendsPage;
use App\Filament\Pages\ResumePage;
use App\Filament\Resources\JobPostings\Pages\ListJobPostings;
use App\Filament\Resources\JobPostings\Pages\ReviewJobPosting;
use App\Filament\Widgets\ComparisonSummaryWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Filament\Widgets\TopConceptsWidget;
use App\Filament\Widgets\TrendsChartWidget;
use App\Jobs\AnalyzeJobPosting;
use App\Models\JobPosting;
use App\Services\JobAnalysisService;
use App\Services\ResumeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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

it('renderiza a página de revisão com a sugestão', function () {
    JobExtractionAgent::fake([[
        'role' => 'Dev Python',
        'technologies' => ['React'],
        'languages' => ['Python'],
    ]]);

    $posting = JobPosting::factory()->create(['description' => longDescription()]);
    app(JobAnalysisService::class)->analyze($posting);

    Livewire::test(ReviewJobPosting::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertSee('Descrição original')
        ->assertSet('data.role', 'Dev Python');
});

it('renderiza a página do currículo e salva o texto', function () {
    Livewire::test(ResumePage::class)
        ->assertSuccessful()
        ->set('data.content', 'Currículo com Python e Docker.')
        ->call('save');

    expect(app(ResumeService::class)->content())->toBe('Currículo com Python e Docker.');
});

it('renderiza a comparação mercado × currículo com dados do PRD', function () {
    $p1 = JobPosting::factory()->confirmed()->create(['description' => longDescription()]);
    $p1->items()->createMany([
        ['category' => 'language', 'name' => 'Python', 'source' => 'ai'],
        ['category' => 'framework', 'name' => 'React', 'source' => 'ai'],
    ]);
    $p2 = JobPosting::factory()->confirmed()->create(['description' => longDescription()]);
    $p2->items()->create(['category' => 'language', 'name' => 'Python', 'source' => 'ai']);

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
    $posting->items()->create(['category' => 'language', 'name' => 'Python', 'source' => 'ai']);

    $posting2 = JobPosting::factory()->confirmed()->create([
        'description' => longDescription(),
        'collected_at' => now()->subMonth(),
    ]);
    $posting2->items()->create(['category' => 'language', 'name' => 'Python', 'source' => 'ai']);

    Livewire::test(MarketTrendsPage::class)
        ->assertSuccessful()
        ->assertDontSee('Dados insuficientes');
});

it('widgets exibem dados corretos', function () {
    $posting = JobPosting::factory()->confirmed()->create(['description' => longDescription()]);
    $posting->items()->create(['category' => 'language', 'name' => 'Python', 'source' => 'ai']);
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
        'technologies' => ['Python'],
    ]]);

    $posting = JobPosting::factory()->create(['description' => longDescription()]);

    Livewire::test(ListJobPostings::class)
        ->callTableAction('analyze', $posting);

    Queue::assertPushed(AnalyzeJobPosting::class, fn (AnalyzeJobPosting $job) => $job->posting->is($posting));

    // A análise roda em segundo plano; o estado só muda quando o job executa.
    $posting->refresh();
    expect($posting->status)->toBe(JobPostingStatus::Pending);

    (new AnalyzeJobPosting($posting))->handle(app(JobAnalysisService::class));

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::NeedsReview)
        ->and($posting->suggested_data)->not->toBeNull();
});

it('redireciona para a listagem ao abrir a revisão de vaga já confirmada', function () {
    $posting = JobPosting::factory()->confirmed()->create(['description' => longDescription()]);

    Livewire::test(ReviewJobPosting::class, ['record' => $posting->id])
        ->assertRedirect(ListJobPostings::getUrl());
});
