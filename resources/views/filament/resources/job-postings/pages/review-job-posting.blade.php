<x-filament-panels::page>
    <form wire:submit="confirm" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end gap-3">
            <x-filament::button type="submit" color="primary" icon="heroicon-o-check-circle">
                Confirmar vaga
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
