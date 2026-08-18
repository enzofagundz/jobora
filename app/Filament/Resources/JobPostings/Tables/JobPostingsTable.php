<?php

namespace App\Filament\Resources\JobPostings\Tables;

use App\Enums\ConceptCategory;
use App\Enums\JobPostingStatus;
use App\Filament\Resources\JobPostings\Pages\ResumeAdaptationPage;
use App\Filament\Resources\JobPostings\Pages\ReviewJobPosting;
use App\Filament\Resources\JobPostings\Pages\ViewJobPosting;
use App\Jobs\AnalyzeJobPosting;
use App\Models\JobPosting;
use App\Models\Resume;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class JobPostingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('role')
                    ->label('Cargo')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('company')
                    ->label('Empresa')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('collected_at')
                    ->label('Coletada em')
                    ->dateTime('d/m/Y')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (JobPostingStatus $state): string => $state->label())
                    ->color(fn (JobPostingStatus $state): string => match ($state) {
                        JobPostingStatus::Pending => 'gray',
                        JobPostingStatus::NeedsReview => 'warning',
                        JobPostingStatus::Confirmed => 'success',
                        JobPostingStatus::Failed => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('items_count')
                    ->label('Itens')
                    ->counts('items')
                    ->badge()
                    ->color('info'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(JobPostingStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()),
                Filter::make('collected_at')
                    ->label('Período de coleta')
                    ->form([
                        DatePicker::make('from')->label('De'),
                        DatePicker::make('until')->label('Até'),
                    ])
                    ->query(function ($query, array $data): void {
                        $query
                            ->when($data['from'], fn ($q, $from) => $q->where('collected_at', '>=', $from))
                            ->when($data['until'], fn ($q, $until) => $q->where('collected_at', '<=', $until.' 23:59:59'));
                    }),
                SelectFilter::make('category')
                    ->label('Categoria de item')
                    ->options(collect(ConceptCategory::all())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all())
                    ->query(fn ($query, array $data) => $query
                        ->when($data['value'], fn ($q, $value) => $q->whereHas('items', fn ($items) => $items->where('category', $value)))),
            ])
            ->recordActions([
                Action::make('analyze')
                    ->label('Analisar com IA')
                    ->icon('heroicon-o-sparkles')
                    ->color('primary')
                    ->authorize('update')
                    ->visible(fn (JobPosting $record): bool => $record->status !== JobPostingStatus::Confirmed)
                    ->disabled(fn (JobPosting $record): bool => $record->status === JobPostingStatus::Confirmed)
                    ->requiresConfirmation()
                    ->modalHeading('Analisar com IA')
                    ->modalDescription('A descrição da vaga será enviada ao modelo de linguagem para (re)estruturação. Custo de uma chamada de IA por execução.')
                    ->action(function (JobPosting $record): void {
                        AnalyzeJobPosting::dispatch($record);

                        Notification::make()
                            ->info()
                            ->title('Análise em andamento')
                            ->body('A vaga está sendo analisada em segundo plano. O status será atualizado na listagem.')
                            ->send();
                    }),
                Action::make('view')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->visible(fn (JobPosting $record): bool => $record->status === JobPostingStatus::Pending)
                    ->url(fn (JobPosting $record): string => ViewJobPosting::getUrl(['record' => $record])),
                Action::make('review')
                    ->label('Revisar')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('warning')
                    ->visible(fn (JobPosting $record): bool => $record->status === JobPostingStatus::NeedsReview)
                    ->url(fn (JobPosting $record): string => ReviewJobPosting::getUrl(['record' => $record])),
                Action::make('adaptResume')
                    ->label('Adequar currículo')
                    ->icon('heroicon-o-document-arrow-up')
                    ->color('info')
                    ->authorize('view')
                    ->visible(fn (JobPosting $record): bool => $record->status === JobPostingStatus::Confirmed
                        && once(fn (): bool => Resume::query()->exists()))
                    ->url(fn (JobPosting $record): string => ResumeAdaptationPage::getUrl(['record' => $record])),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('collected_at', 'desc');
    }
}
