<?php

namespace App\Filament\Widgets;

use App\Enums\ConceptCategory;
use App\Models\JobPostingItem;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Model;

class TopConceptsWidget extends TableWidget
{
    protected static ?string $heading = 'Top 10 conceitos';

    protected int|string|array $columnSpan = 'full';

    public function getTableRecordKey(Model|array $record): string
    {
        // Linhas agregadas não têm PK; (categoria, nome) é único no grupo.
        $category = $record->category;

        return ($category instanceof \UnitEnum ? $category->value : $category).'|'.$record->name;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                JobPostingItem::query()
                    ->join('job_postings', 'job_postings.id', '=', 'job_posting_items.job_posting_id')
                    ->where('job_postings.status', 'confirmed')
                    ->where('job_postings.user_id', auth()->id())
                    ->selectRaw('job_posting_items.category, job_posting_items.name, COUNT(DISTINCT job_posting_items.job_posting_id) AS total')
                    ->groupBy('job_posting_items.category', 'job_posting_items.name')
                    ->orderByDesc('total')
                    ->limit(10)
            )
            ->columns([
                TextColumn::make('name')->label('Conceito')->searchable(),
                TextColumn::make('category')
                    ->label('Categoria')
                    ->badge(),
                TextColumn::make('total')->label('Vagas')->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Categoria')
                    ->options(ConceptCategory::class),
            ])
            ->paginated(false);
    }
}
