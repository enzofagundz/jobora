<?php

namespace App\Filament\Resources\JobCaptures\Tables;

use App\Enums\JobCaptureTriageResult;
use App\Enums\JobCaptureTriageStatus;
use App\Filament\Resources\JobCaptures\Pages\ViewJobCapture;
use App\Jobs\TriageJobCaptures;
use App\Models\JobCapture;
use App\Services\JobAnalysisService;
use App\Services\JobPromotionService;
use App\Services\JobTriageService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class JobCapturesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->limit(70)
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('source')
                    ->label('Origem')
                    ->badge(),
                TextColumn::make('external_id')
                    ->label('ID externo')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('captured_at')
                    ->label('Capturada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('triage_status')
                    ->label('Triagem')
                    ->badge()
                    ->formatStateUsing(fn (JobCaptureTriageStatus $state): string => $state->label())
                    ->color(fn (JobCaptureTriageStatus $state): string => match ($state) {
                        JobCaptureTriageStatus::Pending => 'gray',
                        JobCaptureTriageStatus::Processing => 'info',
                        JobCaptureTriageStatus::Completed => 'success',
                        JobCaptureTriageStatus::Failed => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('triage_result')
                    ->label('Resultado')
                    ->badge()
                    ->formatStateUsing(fn (?JobCaptureTriageResult $state): string => $state?->label() ?? '—')
                    ->color(fn (?JobCaptureTriageResult $state): string => match ($state) {
                        JobCaptureTriageResult::Relevant => 'success',
                        JobCaptureTriageResult::Rejected => 'danger',
                        JobCaptureTriageResult::NotAJob => 'gray',
                        JobCaptureTriageResult::Uncertain => 'warning',
                        default => 'gray',
                    })
                    ->tooltip(function (JobCapture $record, ?JobCaptureTriageResult $state): ?string {
                        return $state === JobCaptureTriageResult::Rejected ? $record->triage_reason : null;
                    }),
                TextColumn::make('triage_reason')
                    ->label('Justificativa')
                    ->limit(60)
                    ->wrap()
                    ->placeholder('—')
                    ->tooltip(fn (?string $state): string => (string) $state),
                TextColumn::make('job_posting_id')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Promovida' : 'Pendente')
                    ->color(fn ($state) => $state ? 'success' : 'warning'),
            ])
            ->filters([
                SelectFilter::make('triage_status')
                    ->label('Triagem')
                    ->options(collect(JobCaptureTriageStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()),
                SelectFilter::make('triage_result')
                    ->label('Resultado')
                    ->options([
                        'hide_non_jobs' => 'Somente vagas',
                        'all' => 'Todas (inclui rejeitadas e não-vagas)',
                    ])
                    ->default('hide_non_jobs')
                    ->query(function ($query, array $data): void {
                        if (($data['value'] ?? 'hide_non_jobs') === 'hide_non_jobs') {
                            $query->where(fn ($q) => $q->whereNull('triage_result')->orWhereNotIn('triage_result', ['rejected', 'not_a_job']));
                        }
                    }),
                SelectFilter::make('situacao')
                    ->label('Situação')
                    ->options([
                        'promoted' => 'Promovida',
                        'pending' => 'Pendente',
                    ])
                    ->query(function ($query, array $data): void {
                        $query
                            ->when($data['value'] === 'promoted', fn ($q) => $q->whereNotNull('job_posting_id'))
                            ->when($data['value'] === 'pending', fn ($q) => $q->whereNull('job_posting_id'));
                    }),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (JobCapture $record): string => ViewJobCapture::getUrl(['record' => $record])),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('triage')
                        ->label('Enviar para triagem')
                        ->icon('heroicon-o-sparkles')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalHeading('Enviar para triagem')
                        ->modalDescription('As capturas selecionadas serão avaliadas por IA em lote. Custo de 1 chamada de IA por lote.')
                        ->action(function (BulkAction $action, Collection $records): void {
                            $eligible = $records->filter(fn (JobCapture $c): bool => $c->job_posting_id === null
                                && in_array($c->triage_status, [JobCaptureTriageStatus::Pending, JobCaptureTriageStatus::Failed], true));

                            if ($eligible->count() > JobTriageService::MAX_BATCH_SIZE) {
                                Notification::make()
                                    ->warning()
                                    ->title('Selecione no máximo '.JobTriageService::MAX_BATCH_SIZE.' capturas por lote.')
                                    ->send();

                                return;
                            }

                            if ($eligible->isEmpty()) {
                                Notification::make()
                                    ->warning()
                                    ->title('Nenhuma captura elegível para triagem.')
                                    ->send();

                                return;
                            }

                            $ids = $eligible->pluck('id')->all();

                            JobCapture::query()->whereIn('id', $ids)->update([
                                'triage_status' => JobCaptureTriageStatus::Processing,
                                'triage_error' => null,
                            ]);

                            TriageJobCaptures::dispatch($ids);

                            Notification::make()
                                ->info()
                                ->title('Triagem em andamento')
                                ->body(count($ids).' captura(s) enviada(s) para triagem em lote.')
                                ->send();
                        }),
                    BulkAction::make('promoteSelected')
                        ->label('Cadastrar selecionadas')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription('As capturas selecionadas viram vagas e entram na análise completa. Custo de 1 chamada de IA por vaga.')
                        ->action(function (BulkAction $action, Collection $records): void {
                            $eligible = $records->filter(fn (JobCapture $c): bool => $c->job_posting_id === null
                                && $c->triage_status === JobCaptureTriageStatus::Completed
                                && in_array($c->triage_result, [JobCaptureTriageResult::Relevant, JobCaptureTriageResult::Uncertain], true)
                                && mb_strlen((string) $c->description) >= JobAnalysisService::MIN_DESCRIPTION_LENGTH);

                            if ($eligible->isEmpty()) {
                                Notification::make()
                                    ->warning()
                                    ->title('Nenhuma captura elegível para cadastro.')
                                    ->send();

                                return;
                            }

                            $promoted = 0;

                            foreach ($eligible as $capture) {
                                app(JobPromotionService::class)->promote($capture);
                                $promoted++;
                            }

                            Notification::make()
                                ->success()
                                ->title('Vagas cadastradas')
                                ->body($promoted.' vaga(s) criada(s) — análise de IA em andamento.')
                                ->send();
                        }),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('captured_at', 'desc');
    }
}
