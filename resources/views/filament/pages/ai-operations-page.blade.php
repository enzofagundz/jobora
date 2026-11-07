<x-filament-panels::page>
    <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">
        Histórico de execuções da sua conta. Esta tela mostra estados e códigos de falha, não snapshots privados nem credenciais.
    </p>
    <x-filament::section>
        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
