<?php

namespace App\Filament\Pages;

use App\Enums\AnalysisPeriod;
use App\Enums\ConceptCategory;
use App\Services\MarketAnalysisService;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

class MarketTrendsPage extends Page
{
    protected string $view = 'filament.pages.market-trends';

    protected static ?string $slug = 'market-trends';

    protected static ?string $navigationLabel = 'Tendências';

    protected static ?string $title = 'Tendências do mercado';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static string|UnitEnum|null $navigationGroup = 'Mercado';

    protected static ?int $navigationSort = 8;

    #[Url]
    public ?string $category = null;

    #[Url]
    public ?string $period = 'month';

    /** @var array<string, array> */
    private array $memoizedBuckets = [];

    /** @var array<string, array>|null */
    private ?array $memoizedRows = null;

    /**
     * @return array<string, string>
     */
    public function categories(): array
    {
        return collect(ConceptCategory::all())
            ->mapWithKeys(fn (ConceptCategory $case): array => [$case->value => $case->getLabel()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function periodOptions(): array
    {
        return AnalysisPeriod::options();
    }

    public function selectedPeriod(): AnalysisPeriod
    {
        return AnalysisPeriod::tryFrom($this->period ?? 'month') ?? AnalysisPeriod::Month;
    }

    public function selectedPeriodLabel(): string
    {
        return $this->selectedPeriod()->getLabel();
    }

    public function headingLabel(): string
    {
        return match ($this->selectedPeriod()) {
            AnalysisPeriod::Week => 'Evolução semanal das frequências',
            AnalysisPeriod::Month => 'Evolução mensal das frequências',
            AnalysisPeriod::Quarter => 'Evolução trimestral das frequências',
            AnalysisPeriod::Year => 'Evolução anual das frequências',
        };
    }

    /**
     * Buckets para o período/categoria selecionados.
     *
     * @return array<int, array{key: string, label: string, start: CarbonImmutable, end: CarbonImmutable, observed_count: int, is_complete: bool}>
     */
    public function buckets(): array
    {
        $period = $this->selectedPeriod();
        $category = $this->selectedCategory();
        $cacheKey = $period->value.'|'.($category?->value ?? '_all');

        if (isset($this->memoizedBuckets[$cacheKey])) {
            return $this->memoizedBuckets[$cacheKey];
        }

        return $this->memoizedBuckets[$cacheKey] = app(MarketAnalysisService::class)->periodBuckets(auth()->user(), $period, $category);
    }

    /**
     * Compat: meses distintos (YYYY-MM) para callers antigos. Quando período não é mensal, retorna chaves do período atual.
     *
     * @return array<int, string>
     */
    public function months(): array
    {
        // Keep BC for TestsChartWidget and existing tests that call months() expecting monthly keys.
        // For monthly period, return bucket keys. For other periods, still return current period keys (so hasEnoughData remains coherent).
        // If caller expects only monthly, they will still get correct when period is month (default).
        return array_map(fn (array $b): string => $b['key'], $this->buckets());
    }

    public function hasEnoughData(): bool
    {
        return app(MarketAnalysisService::class)->periodHasEnoughData(auth()->user(), $this->selectedPeriod(), $this->selectedCategory());
    }

    public function isEmpty(): bool
    {
        return $this->buckets() === [];
    }

    public function insufficientMessage(): string
    {
        $period = $this->selectedPeriod();
        $label = match ($period) {
            AnalysisPeriod::Week => '4 semanas completas distintas (segunda a domingo)',
            AnalysisPeriod::Month => '2 meses diferentes',
            AnalysisPeriod::Quarter => '2 trimestres completos distintos',
            AnalysisPeriod::Year => '2 anos completos distintos',
        };

        return "Dados insuficientes para análise temporal: são necessárias vagas confirmadas em pelo menos {$label} no escopo selecionado.";
    }

    public function partialBucketNotice(): ?string
    {
        $buckets = $this->buckets();
        if ($buckets === []) {
            return null;
        }
        $last = end($buckets);
        if ($last['is_complete'] === false && $last['observed_count'] > 0) {
            $label = $last['label'];

            return "O período atual ({$label}) está incompleto e não entra na comparação. Os valores são parciais.";
        }

        return null;
    }

    /**
     * Monthly rows compat + period-aware: one per item (top 12), columns = buckets.
     *
     * @return array<int, array{name: string, category: string, months: array<string, int|null>, buckets: array<string, int|null>, total: int}>
     */
    public function monthlyRows(): array
    {
        // Use memoization for rows per period+category
        if ($this->memoizedRows !== null) {
            return $this->memoizedRows;
        }

        $buckets = $this->buckets();
        if ($buckets === []) {
            return $this->memoizedRows = [];
        }

        $period = $this->selectedPeriod();
        $category = $this->selectedCategory();
        $user = auth()->user();

        $series = collect(app(MarketAnalysisService::class)->periodSeries($user, $period, $category));

        // Build buckets map for quick observed check
        $bucketKeys = array_map(fn (array $b): string => $b['key'], $buckets);
        $observedMap = collect($buckets)->pluck('observed_count', 'key')->all();

        // Group by category|name to avoid merging same name across categories
        $byItem = $series->groupBy(fn (array $row): string => $row['category'].'|'.$row['name']);

        // Compute total per item
        $items = $byItem->map(function (Collection $rows, string $key) use ($bucketKeys, $observedMap): array {
            [$cat, $name] = explode('|', $key, 2);
            $byBucket = $rows->pluck('total', 'bucket');

            $bucketCounts = [];
            foreach ($bucketKeys as $bk) {
                if (($observedMap[$bk] ?? 0) === 0) {
                    // No postings in this bucket at all (gap) -> null/placeholder
                    $bucketCounts[$bk] = null;
                } else {
                    $bucketCounts[$bk] = (int) ($byBucket[$bk] ?? 0);
                }
            }

            return [
                'name' => $name,
                'category' => $cat,
                'months' => $bucketCounts, // BC
                'buckets' => $bucketCounts,
                'total' => (int) $rows->sum('total'),
            ];
        });

        $sorted = $items->sortByDesc('total')->take(12)->values()->all();

        return $this->memoizedRows = $sorted;
    }

    /**
     * Compat alias for new rows handling
     *
     * @return array<int, array{name: string, category: string, months: array<string, int|null>, buckets: array<string, int|null>, total: int}>
     */
    public function periodRows(): array
    {
        return $this->monthlyRows();
    }

    /**
     * Period comparison: janelas de calendário completas.
     *
     * @return array<int, array{name: string, category: string, previous: int, current: int, delta_abs: int, delta_rel: float|null, trend: string}>
     */
    public function comparison(): array
    {
        $period = $this->selectedPeriod();
        $category = $this->selectedCategory();
        $user = auth()->user();

        return app(MarketAnalysisService::class)->periodComparison($user, $period, $category);
    }

    /**
     * @return array{previous: string, current: string}
     */
    public function comparisonLabels(): array
    {
        $period = $this->selectedPeriod();

        return app(MarketAnalysisService::class)->periodComparisonLabels($period);
    }

    /**
     * Keep comparisonPeriods for BC if needed, but now delegates to service windows.
     * Not used internally anymore.
     *
     * @return array{0: Carbon, 1: Carbon, 2: Carbon}|null
     */
    private function comparisonPeriods(): ?array
    {
        // Preserve shape for any external caller, but map to period-aware windows
        $period = $this->selectedPeriod();
        $labels = $this->comparisonLabels();
        // Not reconstructing Carbon; return null to signal new path
        // Keep original month logic for month period to avoid breaking old tests that might inspect this private method via reflection?
        if ($period === AnalysisPeriod::Month) {
            $periodBFrom = now()->startOfMonth()->subMonths(3);
            $periodBTo = now()->startOfMonth();
            $periodAFrom = $periodBFrom->copy()->subMonths(3);

            return [$periodAFrom, $periodBFrom, $periodBTo];
        }

        return null;
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
