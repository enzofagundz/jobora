<?php

namespace App\Filament\Resources\DiscoverySources\Pages;

use App\Filament\Resources\DiscoverySources\DiscoverySourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDiscoverySources extends ListRecords
{
    protected static string $resource = DiscoverySourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nova fonte')
                ->authorize(fn (): bool => auth()->check()),
        ];
    }
}
