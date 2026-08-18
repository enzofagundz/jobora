<?php

namespace App\Filament\Pages;

use App\Enums\ConceptCategory;
use App\Services\MarketAnalysisService;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

class MarketTrendsPage extends Page
{
    protected string $view = 'filament.pages.market-trends';

    protected static ?string $slug = 'market-trends';

    protected static ?string $navigationLabel = 'Tendências';

    protected static ?string $title = 'Tendências do mercado';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-trending-up';

    protected static ?int $navigationSort = 4;

    #[Url]
    public ?string $category = null;

    /**
     * @return array<string, string>
     */
    public function categories(): array
    {
        return collect(ConceptCategory::all())
            ->mapWithKeys(fn (ConceptCategory $case): array => [$case->value => $case->label()])
            ->all();
    }

    public function categoryLabel(string $value): string
    {
        return ConceptCategory::from($value)->label();
    }

    /**
     * Distinct months (YYYY-MM) with confirmed postings in scope.
     *
     * @return array<int, string>
     */
    public function months(): array
    {
        return app(MarketAnalysisService::class)->months();
    }

    /**
     * Fewer than 2 distinct months → insufficient data (PRD).
     */
    public function hasEnoughData(): bool
    {
        return count($this->months()) >= 2;
    }

    /**
     * Monthly rows: one per item (top 12 by total in scope), columns = months.
     *
     * @return array<int, array{name: string, months: array<string, int>, total: int}>
     */
    public function monthlyRows(): array
    {
        $months = $this->months();
        $series = collect(app(MarketAnalysisService::class)->monthlySeries($this->selectedCategory()));

        return $series
            ->groupBy('name')
            ->map(function (Collection $rows, string $name) use ($months): array {
                $byMonth = $rows->pluck('total', 'month');

                return [
                    'name' => $name,
                    'months' => collect($months)->mapWithKeys(
                        fn (string $month): array => [$month => (int) ($byMonth[$month] ?? 0)],
                    )->all(),
                    'total' => (int) $rows->sum('total'),
                ];
            })
            ->sortByDesc('total')
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * Period comparison: last 3 complete months × the 3 months before.
     * Delta relativo com threshold ±20% → subiu/desceu/estável.
     *
     * @return array<int, array{name: string, previous: int, current: int, delta_abs: int, delta_rel: float|null, trend: string}>
     */
    public function comparison(): array
    {
        $periods = $this->comparisonPeriods();

        if ($periods === null) {
            return [];
        }

        [$periodAFrom, $periodBFrom, $periodBTo] = $periods;

        $analysis = app(MarketAnalysisService::class);
        $category = $this->selectedCategory();

        $previous = collect($analysis->frequencies($category, $periodAFrom, $periodBFrom))
            ->pluck('total', 'name');
        $current = collect($analysis->frequencies($category, $periodBFrom, $periodBTo))
            ->pluck('total', 'name');

        return $current
            ->map(function (int $currentTotal, string $name) use ($previous): array {
                $previousTotal = (int) ($previous[$name] ?? 0);
                $deltaAbs = $currentTotal - $previousTotal;
                $deltaRel = $previousTotal > 0
                    ? round($deltaAbs / $previousTotal * 100, 1)
                    : null;

                return [
                    'name' => $name,
                    'previous' => $previousTotal,
                    'current' => $currentTotal,
                    'delta_abs' => $deltaAbs,
                    'delta_rel' => $deltaRel,
                    'trend' => $this->classify($deltaRel, $deltaAbs),
                ];
            })
            ->sortByDesc(fn (array $row): int => $row['current'])
            ->values()
            ->all();
    }

    /**
     * Window labels for the comparison (pt-BR, e.g. "mai/26 – jul/26").
     *
     * @return array{previous: string, current: string}
     */
    public function comparisonLabels(): array
    {
        $periods = $this->comparisonPeriods();

        if ($periods === null) {
            return ['previous' => '—', 'current' => '—'];
        }

        [$periodAFrom, $periodBFrom, $periodBTo] = $periods;

        $fmt = fn (Carbon $date): string => mb_strtolower($date->translatedFormat('M/y'), 'UTF-8');

        return [
            'previous' => $fmt($periodAFrom).' – '.$fmt($periodBFrom->copy()->subDay()),
            'current' => $fmt($periodBFrom).' – '.$fmt($periodBTo->copy()->subDay()),
        ];
    }

    /**
     * [periodAFrom, periodBFrom, periodBTo): A = 3 meses anteriores,
     * B = últimos 3 meses completos. Null quando não há mês completo passado.
     *
     * @return array{0: Carbon, 1: Carbon, 2: Carbon}|null
     */
    private function comparisonPeriods(): ?array
    {
        // Last complete month = month before the current (partial) one.
        $periodBFrom = now()->startOfMonth()->subMonths(3);
        $periodBTo = now()->startOfMonth();
        $periodAFrom = $periodBFrom->copy()->subMonths(3);

        return [$periodAFrom, $periodBFrom, $periodBTo];
    }

    private function classify(?float $deltaRel, int $deltaAbs): string
    {
        if ($deltaRel === null) {
            return $deltaAbs > 0 ? 'nova' : 'estável';
        }

        return match (true) {
            $deltaRel >= 20 => 'subiu',
            $deltaRel <= -20 => 'desceu',
            default => 'estável',
        };
    }

    private function selectedCategory(): ?ConceptCategory
    {
        return blank($this->category) ? null : ConceptCategory::tryFrom($this->category);
    }
}
