<?php

namespace App\Filament\Widgets;

use App\Enums\ConceptCategory;
use App\Services\MarketComparisonService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class ComparisonSummaryWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Principais lacunas')
            ->records(fn (): array => app(MarketComparisonService::class)->gaps(10))
            ->columns([
                TextColumn::make('name')
                    ->label('Item')
                    ->wrap()
                    ->width('42%'),
                TextColumn::make('category')
                    ->label('Categoria')
                    ->formatStateUsing(fn (string $state): string => ConceptCategory::from($state)->label())
                    ->width('26%'),
                TextColumn::make('total')
                    ->label('Vagas')
                    ->numeric(decimalPlaces: 0)
                    ->alignEnd()
                    ->width('14%'),
                TextColumn::make('percent')
                    ->label('% das vagas')
                    ->formatStateUsing(fn (float $state): string => $this->formatPercent($state))
                    ->alignEnd()
                    ->width('18%'),
            ])
            ->emptyStateHeading('Nenhuma lacuna identificada')
            ->emptyStateDescription('Ou não há vagas confirmadas ou o currículo não está cadastrado.');
    }

    private function formatPercent(float $state): string
    {
        return rtrim(rtrim(number_format($state, 1), '0'), '.').'%';
    }
}
