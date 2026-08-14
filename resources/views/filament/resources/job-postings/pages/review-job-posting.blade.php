<x-filament-panels::page>
    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
        <p class="mb-2 text-sm font-medium text-gray-500 dark:text-gray-400">Descrição original (imutável)</p>
        <p class="whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300">{{ $this->record->description }}</p>
    </div>

    <form wire:submit="confirm" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end gap-3">
            <x-filament::button type="submit" color="primary" icon="heroicon-o-check-circle">
                Confirmar vaga
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
