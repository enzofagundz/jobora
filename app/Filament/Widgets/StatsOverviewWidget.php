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
        $user = auth()->user();

        return [
            Stat::make('Vagas', JobPosting::where('user_id', $user->id)->count())
                ->description('total cadastradas'),
            Stat::make('Confirmadas', JobPosting::query()->where('user_id', $user->id)->where('status', 'confirmed')->count())
                ->description('compõem as análises')
                ->color('success'),
            Stat::make('Aguardando revisão', JobPosting::query()->where('user_id', $user->id)->where('status', 'needs_review')->count())
                ->color('warning'),
            Stat::make('Falhas na análise', JobPosting::query()->where('user_id', $user->id)->where('status', 'failed')->count())
                ->color('danger'),
            Stat::make('Conceitos distintos', JobPostingItem::whereHas('jobPosting', fn ($q) => $q->where('user_id', $user->id)->where('status', 'confirmed'))->distinct()->count('name'))
                ->description('na base confirmada'),
        ];
    }
}
