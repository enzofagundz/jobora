<?php

namespace App\Filament\Resources\JobPostings\Pages;

use App\Enums\JobPostingStatus;
use App\Filament\Resources\JobPostings\JobPostingResource;
use Filament\Resources\Pages\ViewRecord;

class ViewJobPosting extends ViewRecord
{
    protected static string $resource = JobPostingResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->record->status !== JobPostingStatus::Pending) {
            $this->redirect(ListJobPostings::getUrl());
        }
    }
}
