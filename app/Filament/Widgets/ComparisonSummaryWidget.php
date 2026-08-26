<?php

namespace App\Filament\Widgets;

use App\Enums\ConceptCategory;
use App\Enums\StrategicRole;
use App\Services\MarketComparisonService;
use App\Support\Number;
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
            ->records(fn (): array => app(MarketComparisonService::class)->gaps(auth()->user(), 10))
            ->columns([
                TextColumn::make('name')
                    ->label('Item')
                    ->wrap()
                    ->width('42%'),
                TextColumn::make('category')
                    ->label('Categoria')
                    ->formatStateUsing(fn (string $state): string => ConceptCategory::from($state)->getLabel())
                    ->width('26%'),
                TextColumn::make('total')
                    ->label('Vagas')
                    ->numeric(decimalPlaces: 0)
                    ->alignEnd()
                    ->width('14%'),
                TextColumn::make('percent')
                    ->label('% das vagas')
                    ->formatStateUsing(fn (float $state): string => Number::percent($state))
                    ->alignEnd()
                    ->width('18%'),
                TextColumn::make('strategic_role')
                    ->label('Papel')
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        StrategicRole::Core->value => 'primary',
                        StrategicRole::Expansion->value => 'info',
                        StrategicRole::Differentiation->value => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : StrategicRole::from($state)->getLabel())
                    ->width('14%'),
            ])
            ->emptyStateHeading('Nenhuma lacuna identificada')
            ->emptyStateDescription('Ou não há vagas confirmadas ou o currículo não está cadastrado.');
    }
}
