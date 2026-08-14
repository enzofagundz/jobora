<x-filament-panels::page>
    @if ($lastUpdatedAt = $this->lastUpdatedAt())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Última atualização: {{ $lastUpdatedAt }}
        </p>
    @endif

    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-filament::button type="submit" icon="heroicon-o-check-circle">
                Salvar currículo
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
