<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            Frequência dos itens nas vagas confirmadas × presença no currículo
        </x-slot>

        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
            Base: {{ $this->getTotalConfirmed() }} vaga(s) confirmada(s) · Percentuais relativos a essa base.
        </p>

        {{ $this->table }}

        @if (! $this->hasResume())
            <p class="mt-4 text-sm text-warning-600 dark:text-warning-400">
                Nenhum currículo cadastrado — todas as linhas aparecem como "Ausente". Cadastre o currículo na página Currículo.
            </p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
