<?php

namespace App\Filament\Widgets;

use App\Models\JobPosting;
use Filament\Widgets\StatsOverviewWidget as BaseStatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseStatsOverviewWidget
{
    protected function getStats(): array
    {
        $user = auth()->user();

        return [
            Stat::make('Oportunidades', JobPosting::where('user_id', $user->id)->whereNotNull('readiness')->count())
                ->description('estruturadas no Jobora V2'),
            Stat::make('Oportunidades prontas', JobPosting::query()->where('user_id', $user->id)->where('readiness', 'ready')->count())
                ->description('com prontidão V2 marcada como pronta')
                ->color('success'),
            Stat::make('Precisam de revisão', JobPosting::query()->where('user_id', $user->id)->where(function ($query): void {
                $query->where('readiness', 'needs_review')
                    ->orWhere(function ($legacy): void {
                        $legacy->whereNull('readiness')->where('status', 'needs_review');
                    });
            })->count())
                ->color('warning'),
            Stat::make('Falhas na análise', JobPosting::query()->where('user_id', $user->id)
                ->whereNull('readiness')->where('status', 'failed')->count())
                ->color('danger'),
        ];
    }
}
