<?php

use App\Enums\ConceptCategory;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\MarketAnalysisService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function confirmedPosting(array $items, ?User $owner = null): JobPosting
{
    $user = $owner ?? User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();

    foreach ($items as [$category, $name]) {
        $posting->items()->create(['category' => $category, 'name' => $name, 'source' => 'ai']);
    }

    return $posting;
}

it('calcula frequências absolutas por (categoria, nome) entre vagas confirmadas', function () {
    $user = User::factory()->create();
    confirmedPosting([['skill', 'Python'], ['skill', 'React']], $user);
    confirmedPosting([['skill', 'Python']], $user);
    confirmedPosting([['skill', 'React']], $user);

    $frequencies = app(MarketAnalysisService::class)->frequencies($user);

    expect($frequencies)->toBe([
        ['category' => 'skill', 'name' => 'Python', 'total' => 2],
        ['category' => 'skill', 'name' => 'React', 'total' => 2],
    ]);
});

it('calcula frequências relativas ao total de vagas confirmadas', function () {
    $user = User::factory()->create();
    confirmedPosting([['skill', 'Python'], ['skill', 'React']], $user);
    confirmedPosting([['skill', 'Python']], $user);
    confirmedPosting([['skill', 'React']], $user);

    $frequencies = app(MarketAnalysisService::class)->relativeFrequencies($user);

    expect($frequencies)->toHaveCount(2)
        ->and(collect($frequencies)->firstWhere('name', 'Python')['percent'])->toBe(66.7)
        ->and(collect($frequencies)->firstWhere('name', 'React')['percent'])->toBe(66.7);
});

it('filtra por categoria', function () {
    $user = User::factory()->create();
    confirmedPosting([['skill', 'Python']], $user);
    confirmedPosting([['tool', 'React']], $user);

    $frequencies = app(MarketAnalysisService::class)->frequencies($user, ConceptCategory::Skill);

    expect($frequencies)->toHaveCount(1)
        ->and($frequencies[0]['name'])->toBe('Python');
});

it('retorna ranking top N ordenado por frequência', function () {
    $user = User::factory()->create();
    confirmedPosting([['skill', 'Python']], $user);
    confirmedPosting([['skill', 'Python'], ['skill', 'PHP']], $user);

    $top = app(MarketAnalysisService::class)->top($user, 1);

    expect($top)->toHaveCount(1)
        ->and($top[0]['name'])->toBe('Python')
        ->and($top[0]['total'])->toBe(2);
});

it('calcula combinações recorrentes na mesma categoria', function () {
    $user = User::factory()->create();
    confirmedPosting([['skill', 'Laravel'], ['skill', 'Vue.js']], $user);
    confirmedPosting([['skill', 'Laravel'], ['skill', 'Vue.js']], $user);
    confirmedPosting([['skill', 'Laravel'], ['skill', 'Symfony']], $user);

    $pairs = app(MarketAnalysisService::class)->pairsByCategory($user);

    expect($pairs)->toHaveCount(2)
        ->and($pairs[0])->toBe([
            'category' => 'skill',
            'name_a' => 'Laravel',
            'name_b' => 'Vue.js',
            'total' => 2,
        ])
        ->and($pairs[1]['name_b'])->toBe('Symfony')
        ->and($pairs[1]['total'])->toBe(1);
});

it('não duplica o mesmo conceito dentro de uma vaga', function () {
    $posting = confirmedPosting([['skill', 'Python']]);

    // unique (job_posting_id, category, name) — inserir duplicado falha.
    expect(fn () => $posting->items()->create(['category' => 'skill', 'name' => 'Python', 'source' => 'ai']))
        ->toThrow(QueryException::class);
});
