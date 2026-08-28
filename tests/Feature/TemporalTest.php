<?php

use App\Enums\ConceptCategory;
use App\Filament\Pages\MarketTrendsPage;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\MarketAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// As páginas do painel exigem usuário autenticado (policy + middleware do painel).
beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

function postingAt(Carbon $date, array $items, ?User $owner = null): JobPosting
{
    $user = $owner ?? auth()->user() ?? User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create(['collected_at' => $date]);

    foreach ($items as $name) {
        $posting->items()->create(['category' => 'skill', 'name' => $name, 'source' => 'ai']);
    }

    return $posting;
}

it('agrupa frequências por mês de coleta', function () {
    $user = auth()->user();
    postingAt(Carbon::create(2026, 5, 10), ['Python'], $user);
    postingAt(Carbon::create(2026, 5, 20), ['Python'], $user);
    postingAt(Carbon::create(2026, 6, 5), ['Python'], $user);
    postingAt(Carbon::create(2026, 6, 8), ['React'], $user);

    $series = app(MarketAnalysisService::class)->monthlySeries($user);

    // Ordem determinística: mês asc, total desc, nome asc.
    expect($series)->toBe([
        ['month' => '2026-05', 'name' => 'Python', 'total' => 2],
        ['month' => '2026-06', 'name' => 'Python', 'total' => 1],
        ['month' => '2026-06', 'name' => 'React', 'total' => 1],
    ]);
});

it('lista meses distintos com vagas confirmadas', function () {
    $user = auth()->user();
    postingAt(Carbon::create(2026, 5, 10), ['Python'], $user);
    postingAt(Carbon::create(2026, 7, 2), ['Python'], $user);

    expect(app(MarketAnalysisService::class)->months($user))->toBe(['2026-05', '2026-07']);
});

it('filtra série mensal por categoria', function () {
    $user = auth()->user();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create(['collected_at' => Carbon::create(2026, 5, 10)]);
    $posting->items()->create(['category' => 'skill', 'name' => 'Python', 'source' => 'ai']);
    $posting->items()->create(['category' => 'tool', 'name' => 'React', 'source' => 'ai']);

    $series = app(MarketAnalysisService::class)->monthlySeries($user, ConceptCategory::Skill);

    expect($series)->toHaveCount(1)
        ->and($series[0]['name'])->toBe('Python');
});

it('mostra aviso de dados insuficientes com menos de 2 meses', function () {
    postingAt(Carbon::now()->subDays(2), ['Python']);

    Livewire::test(MarketTrendsPage::class)
        ->assertSet('category', null)
        ->assertSee('Dados insuficientes');
});

it('não mostra aviso com 2 meses distintos', function () {
    postingAt(Carbon::now()->subMonths(2), ['Python']);
    postingAt(Carbon::now()->subMonths(1), ['Python']);

    Livewire::test(MarketTrendsPage::class)
        ->assertDontSee('Dados insuficientes')
        ->assertSee('Comparação de períodos');
});

it('classifica tendência subiu/desceu/estável entre os dois últimos trimestres', function () {
    // Período A (3 anteriores): Python 1, React 4 → Período B (últimos 3): Python 3, React 2.
    postingAt(Carbon::now()->startOfMonth()->subMonths(6)->addDays(2), ['Python']);
    foreach (range(1, 4) as $i) {
        postingAt(Carbon::now()->startOfMonth()->subMonths(6)->addDays(2 + $i), ['React']);
    }
    foreach (range(1, 3) as $i) {
        postingAt(Carbon::now()->startOfMonth()->subMonths(1)->addDays($i), ['Python']);
    }
    postingAt(Carbon::now()->startOfMonth()->subMonths(1)->addDays(5), ['React']);
    postingAt(Carbon::now()->startOfMonth()->subMonths(1)->addDays(6), ['React']);

    $page = Livewire::test(MarketTrendsPage::class);
    $comparison = collect($page->instance()->comparison());

    expect($comparison->firstWhere('name', 'Python')['trend'])->toBe('subiu')
        ->and($comparison->firstWhere('name', 'React')['trend'])->toBe('desceu');
});

it('persiste a categoria selecionada na URL (deep-link)', function () {
    postingAt(Carbon::now()->subMonths(2), ['Python']);

    Livewire::withQueryParams(['category' => 'skill'])
        ->test(MarketTrendsPage::class)
        ->assertSet('category', 'skill');
});
