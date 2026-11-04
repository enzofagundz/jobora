<?php

use App\Enums\AnalysisPeriod;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\MarketAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function baselinePortabilityPosting(User $user, Carbon $date, array $items = ['Python']): JobPosting
{
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create(['collected_at' => $date]);

    foreach ($items as $name) {
        $posting->items()->create(['category' => 'skill', 'name' => $name, 'source' => 'ai']);
    }

    return $posting;
}

it('lista meses distintos de vagas confirmadas', function () {
    baselinePortabilityPosting($this->user, Carbon::create(2026, 5, 10));
    baselinePortabilityPosting($this->user, Carbon::create(2026, 7, 2));

    expect(app(MarketAnalysisService::class)->months($this->user))->toBe(['2026-05', '2026-07']);
});

it('agrupa série mensal por mês e nome', function () {
    baselinePortabilityPosting($this->user, Carbon::create(2026, 5, 10), ['Python']);
    baselinePortabilityPosting($this->user, Carbon::create(2026, 5, 20), ['Python']);
    baselinePortabilityPosting($this->user, Carbon::create(2026, 6, 5), ['React']);

    expect(app(MarketAnalysisService::class)->monthlySeries($this->user))->toBe([
        ['month' => '2026-05', 'name' => 'Python', 'total' => 2],
        ['month' => '2026-06', 'name' => 'React', 'total' => 1],
    ]);
});

it('gera buckets de calendário com chave e label por período', function (AnalysisPeriod $period, string $date, string $key, string $label) {
    baselinePortabilityPosting($this->user, Carbon::parse($date));

    $buckets = app(MarketAnalysisService::class)->periodBuckets($this->user, $period);

    expect($buckets)->toHaveCount(1)
        ->and($buckets[0]['key'])->toBe($key)
        ->and($buckets[0]['label'])->toBe($label)
        ->and($buckets[0]['observed_count'])->toBe(1);
})->with([
    'semana' => [AnalysisPeriod::Week, '2026-05-10', '2026-05-04', 'sem 19/26'],
    'mês' => [AnalysisPeriod::Month, '2026-05-10', '2026-05', 'mai/2026'],
    'trimestre' => [AnalysisPeriod::Quarter, '2026-05-10', '2026-Q2', 'T2/26'],
    'ano' => [AnalysisPeriod::Year, '2026-05-10', '2026', '2026'],
]);

it('gera série por bucket e item', function () {
    baselinePortabilityPosting($this->user, Carbon::create(2026, 5, 10), ['Python']);
    baselinePortabilityPosting($this->user, Carbon::create(2026, 7, 2), ['Python', 'React']);

    expect(app(MarketAnalysisService::class)->periodSeries($this->user, AnalysisPeriod::Month))->toBe([
        ['bucket' => '2026-05', 'category' => 'skill', 'name' => 'Python', 'total' => 1],
        ['bucket' => '2026-07', 'category' => 'skill', 'name' => 'Python', 'total' => 1],
        ['bucket' => '2026-07', 'category' => 'skill', 'name' => 'React', 'total' => 1],
    ]);
});
