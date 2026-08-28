<?php

namespace App\Filament\Widgets;

use App\Enums\AnalysisPeriod;
use App\Services\MarketAnalysisService;
use Filament\Widgets\LineChartWidget;
use Illuminate\Contracts\Support\Htmlable;

class TrendsChartWidget extends LineChartWidget
{
    protected ?string $heading = 'Evolução mensal';

    public function getData(): array
    {
        $analysis = app(MarketAnalysisService::class);
        $user = auth()->user();

        // Home remains mensal, via shared API
        $buckets = $analysis->periodBuckets($user, AnalysisPeriod::Month, null);

        if ($buckets === []) {
            return ['datasets' => [], 'labels' => []];
        }

        $bucketKeys = array_map(fn (array $b): string => $b['key'], $buckets);
        $observedMap = collect($buckets)->pluck('observed_count', 'key')->all();

        $series = collect($analysis->periodSeries($user, AnalysisPeriod::Month, null));

        // Group by category|name to avoid merging same name across categories
        $byItem = $series->groupBy(fn (array $row): string => $row['category'].'|'.$row['name']);

        $topKeys = $byItem
            ->map(fn ($rows): int => (int) collect($rows)->sum('total'))
            ->sortDesc()
            ->take(6)
            ->keys();

        $palette = ['#f59e0b', '#10b981', '#3b82f6', '#ef4444', '#8b5cf6', '#14b8a6'];

        $datasets = $topKeys->values()
            ->map(function (string $compound, int $index) use ($byItem, $bucketKeys, $observedMap, $palette): array {
                [$cat, $name] = explode('|', $compound, 2);
                $rows = $byItem[$compound] ?? collect();
                $byBucket = collect($rows)->pluck('total', 'bucket');

                $data = collect($bucketKeys)->map(function (string $bk) use ($byBucket, $observedMap): ?int {
                    if (($observedMap[$bk] ?? 0) === 0) {
                        return null;
                    }

                    return (int) ($byBucket[$bk] ?? 0);
                })->all();

                return [
                    'label' => $name,
                    'data' => $data,
                    'borderColor' => $palette[$index % count($palette)],
                    'backgroundColor' => $palette[$index % count($palette)],
                    'spanGaps' => false,
                ];
            })
            ->all();

        $labels = collect($buckets)->pluck('label')->all();

        return [
            'datasets' => $datasets,
            'labels' => $labels,
        ];
    }

    public function getDescription(): string|Htmlable|null
    {
        $user = auth()->user();
        if ($user === null) {
            return 'Sem dados suficientes para exibir a evolução mensal.';
        }

        $buckets = app(MarketAnalysisService::class)->periodBuckets($user, AnalysisPeriod::Month, null);

        if ($buckets !== []) {
            return null;
        }

        return 'Sem dados suficientes para exibir a evolução mensal.';
    }
}
