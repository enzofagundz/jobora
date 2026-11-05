<?php

namespace App\Filament\Resources\JobPostings\Pages;

use App\Enums\JobPostingStatus;
use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Filament\Resources\JobPostings\Schemas\OpportunityReviewForm;
use App\Jobs\AnalyzeJobPosting;
use App\Models\Publication;
use App\Services\AiOperationService;
use App\Services\OpportunityReviewConflict;
use App\Services\OpportunityReviewService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use LogicException;

class ViewJobPosting extends ViewRecord
{
    protected static string $resource = JobPostingResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if (! in_array($this->record->status, [JobPostingStatus::Pending, JobPostingStatus::Confirmed, JobPostingStatus::Failed, JobPostingStatus::NeedsReview], true)) {
            $this->redirect(ListJobPostings::getUrl());
        }

        if ($this->record->status === JobPostingStatus::Failed) {
            auth()->user()?->unreadNotifications()
                ->where('data->title', 'Falha na análise da vaga')
                ->update(['read_at' => now()]);
        }
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('correctOpportunity')
                ->label('Corrigir oportunidade')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('warning')
                ->authorize('update')
                ->visible(fn (): bool => $this->record->isStructuredOpportunity() && $this->record->readiness === 'needs_review')
                ->modalHeading('Corrigir oportunidade')
                ->modalDescription('A correção preserva publicação, evidências, origem e histórico. A oportunidade fica pronta quando não restar pendência.')
                ->modalWidth(Width::SevenExtraLarge)
                ->modalSubmitActionLabel('Salvar correção')
                ->schema([
                    Hidden::make('expected_version'),
                    ...OpportunityReviewForm::schema(),
                ])
                ->fillForm(fn (): array => [
                    'expected_version' => $this->record->opportunity_version,
                    ...OpportunityReviewForm::data($this->record),
                ])
                ->action(function (array $data): void {
                    $expectedVersion = (int) ($data['expected_version'] ?? 0);
                    unset($data['expected_version']);
                    $data = OpportunityReviewForm::withoutUnchangedProvenance($data, $this->record);

                    try {
                        $revision = app(OpportunityReviewService::class)->review(
                            auth()->user(),
                            $this->record,
                            $expectedVersion,
                            $data,
                        );
                    } catch (OpportunityReviewConflict $exception) {
                        Notification::make()
                            ->danger()
                            ->title('A oportunidade mudou')
                            ->body($exception->getMessage())
                            ->send();

                        return;
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->danger()
                            ->title('Correção não aceita')
                            ->body(collect($exception->errors())->flatten()->first() ?? 'Revise os dados informados.')
                            ->send();

                        return;
                    } catch (LogicException $exception) {
                        Notification::make()
                            ->warning()
                            ->title($exception->getMessage())
                            ->send();

                        return;
                    }

                    $posting = $revision->jobPosting;
                    $this->record->refresh();

                    if ($posting->readiness === 'ready') {
                        Notification::make()
                            ->success()
                            ->title('Oportunidade pronta')
                            ->body('As pendências de revisão foram resolvidas.')
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->warning()
                        ->title('Correção salva')
                        ->body('A oportunidade continua aguardando revisão: '.implode(', ', array_map(
                            JobPostingResource::reviewReasonLabel(...),
                            $posting->review_reasons ?? [],
                        )).'.')
                        ->send();
                }),
            Action::make('reevaluatePublication')
                ->label('Reavaliar publicação')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('info')
                ->authorize('update')
                ->visible(fn (): bool => $this->record->isStructuredOpportunity() && $this->publication() !== null)
                ->modalHeading('Reavaliar publicação')
                ->modalDescription('Reexecuta a triagem com o material já persistido, sem nova navegação. Custo de uma chamada de IA por solicitação.')
                ->modalSubmitActionLabel('Solicitar reavaliação')
                ->schema([
                    Toggle::make('retry')
                        ->label('Repetir somente a etapa com falha')
                        ->helperText('Reutiliza a decisão e o motivo registrados na operação original.')
                        ->live(),
                    Toggle::make('bypass_clef')
                        ->label('Reavaliar mesmo com a publicação barrada pelo Clef')
                        ->helperText('O motivo fica registrado junto da decisão original.')
                        ->visible(fn (Get $get): bool => ! $get('retry'))
                        ->live(),
                    Textarea::make('reason')
                        ->label('Motivo do bypass')
                        ->required(fn (Get $get): bool => (bool) $get('bypass_clef'))
                        ->visible(fn (Get $get): bool => ! $get('retry'))
                        ->maxLength(500)
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->action(function (array $data): void {
                    $publication = $this->publication();

                    if ($publication === null) {
                        Notification::make()
                            ->warning()
                            ->title('Publicação de origem não encontrada')
                            ->send();

                        return;
                    }

                    $retry = (bool) ($data['retry'] ?? false);
                    $bypassClef = ! $retry && (bool) ($data['bypass_clef'] ?? false);

                    try {
                        app(AiOperationService::class)->enqueuePublicationTriage(
                            auth()->user(),
                            $publication->id,
                            $publication->content_version,
                            $retry,
                            $bypassClef,
                            $bypassClef ? ($data['reason'] ?? null) : null,
                        );
                    } catch (LogicException $exception) {
                        Notification::make()
                            ->warning()
                            ->title($exception->getMessage())
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->info()
                        ->title('Reavaliação solicitada')
                        ->body('A triagem foi solicitada com o material persistido. O resultado aparece no diagnóstico da oportunidade.')
                        ->send();
                }),
            Action::make('retry')
                ->label('Tentar novamente')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('primary')
                ->authorize('update')
                ->visible(fn (): bool => ! $this->record->isStructuredOpportunity() && $this->record->status === JobPostingStatus::Failed)
                ->requiresConfirmation()
                ->modalHeading('Analisar com IA')
                ->modalDescription('A descrição da vaga será enviada ao modelo de linguagem para (re)estruturação. Custo de uma chamada de IA por execução.')
                ->action(function (): void {
                    AnalyzeJobPosting::dispatch($this->record, (int) auth()->id(), 'manual_retry');

                    Notification::make()
                        ->info()
                        ->title('Análise em andamento')
                        ->body('A vaga está sendo analisada em segundo plano. O status será atualizado na listagem.')
                        ->send();
                }),
        ];
    }

    private function publication(): ?Publication
    {
        return Publication::query()
            ->whereKey($this->record->sources()->value('publication_id'))
            ->first();
    }
}
