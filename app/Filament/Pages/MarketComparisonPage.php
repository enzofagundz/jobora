<?php

namespace App\Filament\Pages;

use App\Enums\ConceptCategory;
use App\Enums\ResumeLanguage;
use App\Services\MarketAnalysisService;
use App\Services\MarketComparisonService;
use App\Services\ResumeService;
use App\Support\Number;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class MarketComparisonPage extends Page implements HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected string $view = 'filament.pages.market-comparison';

    protected static ?string $slug = 'market-comparison';

    protected static ?string $navigationLabel = 'Mercado × Currículo';

    protected static ?string $title = 'Mercado × Currículo';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Mercado';

    protected static ?int $navigationSort = 7;

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (array $filters): array => $this->getRows($this->selectedCategory($filters)))
            ->columns([
                TextColumn::make('name')
                    ->label('Item')
                    ->wrap()
                    ->badge(fn (array $record): bool => $record['is_gap'])
                    ->color(fn (array $record): string => $record['is_gap'] ? 'danger' : 'gray')
                    ->width('32%'),
                TextColumn::make('category')
                    ->label('Categoria')
                    ->formatStateUsing(fn (string $state): string => $this->categoryLabel($state))
                    ->width('20%'),
                TextColumn::make('total')
                    ->label('Nº de vagas')
                    ->numeric(decimalPlaces: 0)
                    ->alignEnd()
                    ->width('14%'),
                TextColumn::make('percent')
                    ->label('% das vagas')
                    ->formatStateUsing(fn (float $state): string => Number::percent($state))
                    ->alignEnd()
                    ->width('16%'),
                TextColumn::make('present')
                    ->label('Currículo')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Presente' : 'Ausente')
                    ->alignEnd()
                    ->width('18%'),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Categoria')
                    ->options(ConceptCategory::class),
            ])
            ->emptyStateHeading('Sem itens para exibir')
            ->emptyStateDescription('Cadastre vagas, analise com IA e confirme-as para popular a comparação.');
    }

    /**
     * @return array<int, array{name: string, category: string, total: int, percent: float, present: bool, is_gap: bool}>
     */
    public function getRows(?ConceptCategory $category = null): array
    {
        return app(MarketComparisonService::class)->table(auth()->user(), $category);
    }

    public function getTotalConfirmed(): int
    {
        return app(MarketAnalysisService::class)->totalConfirmed(auth()->user());
    }

    public function hasResume(): bool
    {
        return ! blank(app(ResumeService::class)->content(auth()->user(), ResumeLanguage::PtBr));
    }

    public function categoryLabel(string $value): string
    {
        return ConceptCategory::from($value)->getLabel();
    }

    private function selectedCategory(array $filters): ?ConceptCategory
    {
        $value = $filters['category']['value'] ?? null;

        return blank($value) ? null : ConceptCategory::tryFrom($value);
    }
}
