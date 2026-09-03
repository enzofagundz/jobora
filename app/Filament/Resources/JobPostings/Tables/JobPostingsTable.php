<?php

namespace App\Filament\Resources\JobPostings\Tables;

use App\Enums\ConceptCategory;
use App\Enums\ItemSource;
use App\Enums\JobPostingStatus;
use App\Filament\Resources\JobPostings\Pages\ApplicationPage;
use App\Filament\Resources\JobPostings\Schemas\ApplicationMethodForm;
use App\Jobs\AnalyzeJobPosting;
use App\Models\JobPosting;
use App\Services\JobReviewService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
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
                    ->color(fn (JobPostingStatus $state): string => match ($state) {
                        JobPostingStatus::Pending => 'gray',
                        JobPostingStatus::NeedsReview => 'warning',
                        JobPostingStatus::Confirmed => 'success',
                        JobPostingStatus::Failed => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('error_message')
                    ->label('Erro')
                    ->placeholder('—')
                    ->limit(60)
                    ->toggleable(),
                TextColumn::make('items_count')
                    ->label('Itens')
                    ->counts('items')
                    ->badge()
                    ->color('info'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(JobPostingStatus::class),
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
                    ->options(ConceptCategory::class)
                    ->query(fn ($query, array $data) => $query
                        ->when($data['value'], fn ($q, $value) => $q->whereHas('items', fn ($items) => $items->where('category', $value)))),
            ])
            ->recordActions([
                Action::make('analyze')
                    ->label(fn (JobPosting $record): string => $record->status === JobPostingStatus::Failed ? 'Tentar novamente' : 'Analisar com IA')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->color('primary')
                    ->authorize('update')
                    ->visible(fn (JobPosting $record): bool => $record->status === JobPostingStatus::Failed)
                    ->requiresConfirmation()
                    ->modalHeading('Analisar com IA')
                    ->modalDescription('A descrição da vaga será enviada ao modelo de linguagem para (re)estruturação. Custo de uma chamada de IA por execução.')
                    ->action(function (JobPosting $record): void {
                        AnalyzeJobPosting::dispatch($record, (int) auth()->id(), 'manual_retry');

                        Notification::make()
                            ->info()
                            ->title('Análise em andamento')
                            ->body('A vaga está sendo analisada em segundo plano. O status será atualizado na listagem.')
                            ->send();
                    }),
                ViewAction::make()
                    ->label('Ver')
                    ->visible(fn (JobPosting $record): bool => $record->status === JobPostingStatus::Pending),
                Action::make('review')
                    ->label('Revisar')
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->color('warning')
                    ->authorize('update')
                    ->visible(fn (JobPosting $record): bool => $record->status === JobPostingStatus::NeedsReview)
                    ->modalHeading('Revisar vaga')
                    ->modalWidth(Width::SevenExtraLarge)
                    ->schema([
                        RichEditor::make('description')
                            ->label('Descrição original (imutável)')
                            ->disabled()
                            ->columnSpanFull(),
                        TextInput::make('role')
                            ->label('Cargo')
                            ->maxLength(255),
                        TextInput::make('company')
                            ->label('Empresa')
                            ->maxLength(255),
                        Repeater::make('items')
                            ->label('Itens extraídos')
                            ->helperText('Itens mantidos como vieram da IA são marcados como "ai"; itens editados ou adicionados, como "user".')
                            ->schema([
                                Select::make('category')
                                    ->label('Categoria')
                                    ->options(ConceptCategory::class)
                                    ->required(),
                                TextInput::make('name')
                                    ->label('Nome')
                                    ->required()
                                    ->maxLength(100),
                                TextInput::make('source')
                                    ->label('Origem')
                                    ->disabled()
                                    ->dehydrated(false),
                            ])
                            ->columns(3)
                            ->columnSpanFull(),
                        Repeater::make('application_methods')
                            ->label('Formas de candidatura')
                            ->helperText('Sugestões da IA extraídas da descrição. A vaga só guarda a versão confirmada aqui.')
                            ->schema(ApplicationMethodForm::schema())
                            ->columns(3)
                            ->columnSpanFull(),
                    ])
                    ->fillForm(fn (JobPosting $record): array => [
                        'description' => $record->description,
                        'role' => $record->role,
                        'company' => $record->company,
                        'items' => collect($record->suggested_data['items'] ?? [])
                            ->map(fn (array $item): array => $item + ['source' => ItemSource::Ai->value])
                            ->all(),
                        'application_methods' => $record->suggested_data['application_methods'] ?? [],
                    ])
                    ->modalSubmitActionLabel('Confirmar')
                    ->modalSubmitAction(fn (Action $action) => $action->color('success'))
                    ->extraModalFooterActions([
                        Action::make('discard')
                            ->label('Descartar sugestão')
                            ->icon(Heroicon::OutlinedXCircle)
                            ->color('danger')
                            ->authorize('update')
                            ->requiresConfirmation()
                            ->modalHeading('Descartar sugestão')
                            ->modalDescription('A sugestão da IA será removida e a vaga voltará para "pendente".')
                            ->cancelParentActions()
                            ->action(function (JobPosting $record): void {
                                app(JobReviewService::class)->discard(auth()->user(), $record);

                                Notification::make()
                                    ->warning()
                                    ->title('Sugestão descartada')
                                    ->body('A vaga voltou para "pendente" e pode ser analisada novamente.')
                                    ->send();
                            }),
                    ])
                    ->action(function (array $data, JobPosting $record): void {
                        app(JobReviewService::class)->confirm(
                            auth()->user(),
                            $record,
                            $data['items'],
                            $data['application_methods'] ?? [],
                            $data['role'] ?? null,
                            $data['company'] ?? null,
                        );

                        Notification::make()
                            ->success()
                            ->title('Vaga confirmada')
                            ->body('Os itens confirmados agora compõem as análises.')
                            ->send();
                    }),
                Action::make('application')
                    ->label('Candidatura')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->color('info')
                    ->authorize('view')
                    ->visible(fn (JobPosting $record): bool => $record->status === JobPostingStatus::Confirmed)
                    ->url(fn (JobPosting $record): string => ApplicationPage::getUrl(['record' => $record])),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords(),
                ]),
            ])
            ->defaultSort('collected_at', 'desc');
    }
}
