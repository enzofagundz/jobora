<?php

namespace App\Filament\Resources\DiscoverySources\Pages;

use App\Filament\Resources\DiscoverySources\DiscoverySourceResource;
use App\Services\Discovery\DiscoverySourceService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class CreateDiscoverySource extends CreateRecord
{
    protected static string $resource = DiscoverySourceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return DiscoverySourceResource::normalizeFormData($data);
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(DiscoverySourceService::class)->create(auth()->user(), $data);
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->danger()
                ->title('Não foi possível salvar a fonte')
                ->body($exception->getMessage())
                ->send();

            $this->halt(true);
        }
    }

    protected function getRedirectUrl(): string
    {
        return DiscoverySourceResource::getUrl('index');
    }
}
