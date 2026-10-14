<x-filament-panels::page>
    @if ($activeTab === 'en')
        @if ($this->isEnglishStale())
            <div class="mb-6 rounded-xl border border-warning-300 bg-warning-50 p-4 dark:border-warning-500/30 dark:bg-warning-400/10" role="status">
                <p class="text-sm font-semibold text-warning-800 dark:text-warning-200">
                    Versão EN desatualizada
                </p>
                <p class="mt-1 text-sm text-warning-700 dark:text-warning-300">
                    O currículo em português mudou depois da última tradução. Regenerar substitui o texto em inglês atual.
                </p>
                <x-filament::button
                    color="warning"
                    size="sm"
                    class="mt-3"
                    icon="heroicon-o-arrow-path"
                    wire:click="mountAction('generateEnglish')"
                >
                    Regenerar versão em inglês
                </x-filament::button>
            </div>
        @elseif ($lastUpdatedAt = $this->lastUpdatedAtEn())
            <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                Última atualização: {{ $lastUpdatedAt }}
            </p>
        @endif
    @elseif ($lastUpdatedAt = $this->lastUpdatedAt())
        <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
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
