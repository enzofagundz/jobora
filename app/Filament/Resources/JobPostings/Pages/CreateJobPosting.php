<?php

namespace App\Filament\Resources\JobPostings\Pages;

use App\Enums\JobPostingStatus;
use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Jobs\AnalyzeJobPosting;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateJobPosting extends CreateRecord
{
    protected static string $resource = JobPostingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['collected_at'] = now();
        $data['status'] = JobPostingStatus::Pending;

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return auth()->user()->jobPostings()->create($data);
    }

    protected function afterCreate(): void
    {
        AnalyzeJobPosting::dispatch($this->record, (int) auth()->id());
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Vaga cadastrada — análise de IA em andamento';
    }
}
