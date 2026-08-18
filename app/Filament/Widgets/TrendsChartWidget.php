<?php

namespace App\Filament\Widgets;

use App\Services\MarketAnalysisService;
use Filament\Widgets\LineChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

class TrendsChartWidget extends LineChartWidget
{
    protected ?string $heading = 'Evolução mensal';

    public function getData(): array
    {
        $analysis = app(MarketAnalysisService::class);
        $months = $analysis->months();

        if ($months === []) {
            return ['datasets' => [], 'labels' => []];
        }

        $series = collect($analysis->monthlySeries());

        $byName = $series->groupBy('name');
        $topNames = $byName
            ->map(fn ($rows): int => (int) $rows->sum('total'))
            ->sortDesc()
            ->take(6)
            ->keys();

        $palette = ['#f59e0b', '#10b981', '#3b82f6', '#ef4444', '#8b5cf6', '#14b8a6'];

        $datasets = $topNames->values()
            ->map(function (string $name, int $index) use ($byName, $months, $palette): array {
                $byMonth = $byName[$name]->pluck('total', 'month');

                return [
                    'label' => $name,
                    'data' => collect($months)
                        ->map(fn (string $month): int => (int) ($byMonth[$month] ?? 0))
                        ->all(),
                    'borderColor' => $palette[$index % count($palette)],
                    'backgroundColor' => $palette[$index % count($palette)],
                ];
            })
            ->all();

        return [
            'datasets' => $datasets,
            'labels' => collect($months)->map(fn (string $month): string => Carbon::createFromFormat('Y-m', $month)->translatedFormat('M/y'))->all(),
        ];
    }

    public function getDescription(): string|Htmlable|null
    {
        if (app(MarketAnalysisService::class)->months() !== []) {
            return null;
        }

        return 'Sem dados suficientes para exibir a evolução mensal.';
    }
}
