<?php

namespace App\Filament\Widgets;

use App\Models\JobPosting;
use App\Models\JobPostingItem;
use Filament\Widgets\StatsOverviewWidget as BaseStatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseStatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Vagas', JobPosting::count())
                ->description('total cadastradas'),
            Stat::make('Confirmadas', JobPosting::query()->where('status', 'confirmed')->count())
                ->description('compõem as análises')
                ->color('success'),
            Stat::make('Aguardando revisão', JobPosting::query()->where('status', 'needs_review')->count())
                ->color('warning'),
            Stat::make('Falhas na análise', JobPosting::query()->where('status', 'failed')->count())
                ->color('danger'),
            Stat::make('Conceitos distintos', JobPostingItem::distinct()->count('name'))
                ->description('na base confirmada'),
        ];
    }
}
