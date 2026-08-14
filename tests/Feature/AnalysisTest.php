<?php

use App\Enums\ConceptCategory;
use App\Models\JobPosting;
use App\Services\MarketAnalysisService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function confirmedPosting(array $items): JobPosting
{
    $posting = JobPosting::factory()->confirmed()->create();

    foreach ($items as [$category, $name]) {
        $posting->items()->create(['category' => $category, 'name' => $name, 'source' => 'ai']);
    }

    return $posting;
}

it('calcula frequências absolutas por (categoria, nome) entre vagas confirmadas', function () {
    confirmedPosting([['language', 'Python'], ['framework', 'React']]);
    confirmedPosting([['language', 'Python']]);
    confirmedPosting([['framework', 'React']]);

    $frequencies = app(MarketAnalysisService::class)->frequencies();

    expect($frequencies)->toBe([
        ['category' => 'language', 'name' => 'Python', 'total' => 2],
        ['category' => 'framework', 'name' => 'React', 'total' => 2],
    ]);
});

it('calcula frequências relativas ao total de vagas confirmadas', function () {
    confirmedPosting([['language', 'Python'], ['framework', 'React']]);
    confirmedPosting([['language', 'Python']]);
    confirmedPosting([['framework', 'React']]);

    $frequencies = app(MarketAnalysisService::class)->relativeFrequencies();

    expect($frequencies)->toHaveCount(2)
        ->and(collect($frequencies)->firstWhere('name', 'Python')['percent'])->toBe(66.7)
        ->and(collect($frequencies)->firstWhere('name', 'React')['percent'])->toBe(66.7);
});

it('filtra por categoria', function () {
    confirmedPosting([['language', 'Python']]);
    confirmedPosting([['framework', 'React']]);

    $frequencies = app(MarketAnalysisService::class)->frequencies(ConceptCategory::Language);

    expect($frequencies)->toHaveCount(1)
        ->and($frequencies[0]['name'])->toBe('Python');
});

it('retorna ranking top N ordenado por frequência', function () {
    confirmedPosting([['language', 'Python']]);
    confirmedPosting([['language', 'Python'], ['language', 'PHP']]);

    $top = app(MarketAnalysisService::class)->top(1);

    expect($top)->toHaveCount(1)
        ->and($top[0]['name'])->toBe('Python')
        ->and($top[0]['total'])->toBe(2);
});

it('calcula combinações recorrentes na mesma categoria', function () {
    confirmedPosting([['framework', 'Laravel'], ['framework', 'Vue.js']]);
    confirmedPosting([['framework', 'Laravel'], ['framework', 'Vue.js']]);
    confirmedPosting([['framework', 'Laravel'], ['framework', 'Symfony']]);

    $pairs = app(MarketAnalysisService::class)->pairsByCategory();

    expect($pairs)->toHaveCount(2)
        ->and($pairs[0])->toBe([
            'category' => 'framework',
            'name_a' => 'Laravel',
            'name_b' => 'Vue.js',
            'total' => 2,
        ])
        ->and($pairs[1]['name_b'])->toBe('Symfony')
        ->and($pairs[1]['total'])->toBe(1);
});

it('não duplica o mesmo conceito dentro de uma vaga', function () {
    $posting = confirmedPosting([['language', 'Python']]);

    // unique (job_posting_id, category, name) — inserir duplicado falha.
    expect(fn () => $posting->items()->create(['category' => 'language', 'name' => 'Python', 'source' => 'ai']))
        ->toThrow(QueryException::class);
});
