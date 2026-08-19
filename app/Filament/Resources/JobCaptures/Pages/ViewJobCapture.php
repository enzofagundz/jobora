<?php

namespace App\Filament\Resources\JobCaptures\Pages;

use App\Filament\Resources\JobCaptures\JobCaptureResource;
use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Services\JobPromotionService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use LogicException;

class ViewJobCapture extends ViewRecord
{
    protected static string $resource = JobCaptureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('promote')
                ->label('Criar vaga')
                ->icon(Heroicon::OutlinedPlusCircle)
                ->visible(fn (): bool => $this->record->job_posting_id === null)
                ->action(function (): void {
                    try {
                        $posting = app(JobPromotionService::class)->promote($this->record);
                    } catch (LogicException $e) {
                        Notification::make()
                            ->danger()
                            ->title($e->getMessage())
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title('Vaga criada — análise de IA em andamento')
                        ->send();

                    $this->redirect(JobPostingResource::getUrl('view', ['record' => $posting]));
                }),
        ];
    }
}
