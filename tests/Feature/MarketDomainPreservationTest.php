<?php

use App\Enums\AnalysisPeriod;
use App\Enums\ResumeLanguage;
use App\Models\JobPosting;
use App\Services\MarketAnalysisService;
use App\Services\MarketComparisonService;
use App\Services\ResumeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Mcp\McpTestHelpers;

uses(RefreshDatabase::class);

it('devolve a frequência dos conceitos das vagas confirmadas', function () {
    $user = McpTestHelpers::user();

    $pythonA = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::item($pythonA, 'Python');
    $pythonB = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::item($pythonB, 'Python');
    $react = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::item($react, 'React');

    $market = app(MarketAnalysisService::class);
    expect($market->totalConfirmed($user))->toBe(3);
    $items = $market->relativeFrequencies($user);
    expect($items[0])->toMatchArray(['name' => 'Python', 'total' => 2, 'percent' => 66.7]);
    expect($items[1])->toMatchArray(['name' => 'React', 'total' => 1]);
});

it('devolve combinações recorrentes na mesma categoria', function () {
    $user = McpTestHelpers::user();

    $first = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::item($first, 'PHP');
    McpTestHelpers::item($first, 'Laravel');

    $second = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::item($second, 'PHP');
    McpTestHelpers::item($second, 'Laravel');

    expect(app(MarketAnalysisService::class)->pairsByCategory($user)[0])->toMatchArray(['name_a' => 'Laravel', 'name_b' => 'PHP', 'total' => 2]);
});

it('compara o mercado com o currículo e aponta lacunas', function () {
    $user = McpTestHelpers::user();
    app(ResumeService::class)->save($user, 'Currículo com Python e automação.', ResumeLanguage::PtBr);

    foreach (['Python', 'Python', 'React'] as $name) {
        $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
        McpTestHelpers::item($posting, $name);
    }

    $comparison = app(MarketComparisonService::class);
    expect($comparison->table($user)[0])->toMatchArray(['name' => 'Python', 'present' => true, 'is_gap' => false]);
    expect($comparison->gaps($user)[0])->toMatchArray(['name' => 'React', 'total' => 1]);
});

it('avisa dados insuficientes quando há menos de dois meses', function () {
    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create(['collected_at' => now()]);
    McpTestHelpers::item($posting, 'PHP');

    expect(app(MarketAnalysisService::class)->periodInsufficientReason($user, AnalysisPeriod::Month))->toBe('insufficient');
});

it('devolve a série temporal com dois meses de dados', function () {
    $user = McpTestHelpers::user();

    $thisMonth = JobPosting::factory()->for($user, 'user')->confirmed()->create(['collected_at' => now()]);
    McpTestHelpers::item($thisMonth, 'PHP');
    $lastMonth = JobPosting::factory()->for($user, 'user')->confirmed()->create(['collected_at' => now()->subMonthNoOverflow()]);
    McpTestHelpers::item($lastMonth, 'PHP');

    $market = app(MarketAnalysisService::class);
    expect($market->periodInsufficientReason($user, AnalysisPeriod::Month))->toBeNull();
    expect($market->periodSeries($user, AnalysisPeriod::Month)[0]['name'])->toBe('PHP');
});

it('preserva os dados compartilhados do antigo snapshot sem publicar resource', function () {
    $user = McpTestHelpers::user();
    app(ResumeService::class)->save($user, 'Currículo com Python.', ResumeLanguage::PtBr);

    foreach (['Python', 'Python', 'React'] as $name) {
        $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
        McpTestHelpers::item($posting, $name);
    }

    expect(app(MarketAnalysisService::class)->totalConfirmed($user))->toBe(3);
    expect(app(MarketComparisonService::class)->gaps($user)[0]['name'])->toBe('React');
});
