<?php

namespace App\Filament\Resources\JobPostings\Pages;

use App\Enums\JobPostingStatus;
use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Jobs\AnalyzeJobPosting;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

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
            Action::make('retry')
                ->label('Tentar novamente')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('primary')
                ->authorize('update')
                ->visible(fn (): bool => $this->record->status === JobPostingStatus::Failed)
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
}
