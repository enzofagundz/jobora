<?php

namespace App\Filament\Resources\DiscoverySources\Pages;

use App\Filament\Resources\DiscoverySources\DiscoverySourceResource;
use App\Services\Discovery\DiscoverySourceConflictException;
use App\Services\Discovery\DiscoverySourceService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class EditDiscoverySource extends EditRecord
{
    protected static string $resource = DiscoverySourceResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['search_mode'] = filled($data['criteria'] ?? null) ? 'criteria' : 'url';
        $data['expected_version'] = $this->getRecord()->version;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $expectedVersion = (int) ($data['expected_version'] ?? $record->version);

        $data = DiscoverySourceResource::normalizeFormData($data);

        try {
            return app(DiscoverySourceService::class)->update(
                auth()->user(),
                $record->id,
                $expectedVersion,
                $data,
            );
        } catch (DiscoverySourceConflictException) {
            Notification::make()
                ->warning()
                ->title('Fonte alterada em outra sessão')
                ->body('A fonte foi atualizada em outra janela. Recarregue a página antes de tentar novamente.')
                ->send();

            $this->halt(true);
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
