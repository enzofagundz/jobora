<?php

namespace App\Filament\Resources\PageCaptures\Pages;

use App\Filament\Resources\PageCaptures\PageCaptureResource;
use Filament\Resources\Pages\ManageRecords;

class ManagePageCaptures extends ManageRecords
{
    protected static string $resource = PageCaptureResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
