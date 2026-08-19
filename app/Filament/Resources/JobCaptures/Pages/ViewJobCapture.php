<?php

namespace App\Filament\Resources\JobCaptures\Pages;

use App\Enums\JobPostingStatus;
use App\Filament\Resources\JobCaptures\JobCaptureResource;
use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Jobs\AnalyzeJobPosting;
use App\Models\JobPosting;
use App\Services\JobAnalysisService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

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
                    $capture = $this->record;

                    if (mb_strlen((string) $capture->description) < JobAnalysisService::MIN_DESCRIPTION_LENGTH) {
                        Notification::make()
                            ->danger()
                            ->title('Descrição insuficiente para criar vaga')
                            ->body('Mínimo de '.JobAnalysisService::MIN_DESCRIPTION_LENGTH.' caracteres.')
                            ->send();

                        return;
                    }

                    $posting = JobPosting::create([
                        'description' => $capture->description,
                        'source_url' => $capture->url,
                        'collected_at' => $capture->captured_at ?? now(),
                        'status' => JobPostingStatus::Pending,
                    ]);

                    AnalyzeJobPosting::dispatch($posting);

                    $capture->update(['job_posting_id' => $posting->id]);

                    Notification::make()
                        ->success()
                        ->title('Vaga criada — análise de IA em andamento')
                        ->send();

                    $this->redirect(JobPostingResource::getUrl('view', ['record' => $posting]));
                }),
        ];
    }
}
