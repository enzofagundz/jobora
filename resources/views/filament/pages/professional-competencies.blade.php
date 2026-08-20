<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            Papel estratégico das competências do perfil
        </x-slot>

        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
            Núcleo (base da atuação), Expansão (em desenvolvimento) ou Diferenciação (vantagem competitiva). A
            situação é calculada a partir do currículo atual — atualize o texto na página Currículo.
        </p>

        {{ $this->table }}

        @if (blank(app(\App\Services\ResumeService::class)->content()))
            <p class="mt-4 text-sm text-warning-600 dark:text-warning-400">
                Nenhum currículo cadastrado — a classificação com IA exige o currículo para montar os candidatos.
            </p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
