<x-filament-panels::page>
    @php($activeOperation = $this->activeAiOperation())

    <div @if ($activeOperation) wire:poll.5s="refreshAiOperation" @endif>
        @if ($activeOperation)
            <div
                class="mb-6 rounded-xl border border-info-200 bg-info-50 p-4 dark:border-info-500/30 dark:bg-info-400/10"
                role="status"
            >
                <div class="flex items-start gap-3">
                    <x-filament::icon icon="heroicon-o-arrow-path" class="mt-0.5 size-5 shrink-0 text-info-600 dark:text-info-400" />
                    <div>
                        <p class="text-sm font-semibold text-info-800 dark:text-info-200">Classificação estratégica em andamento</p>
                        <p class="mt-1 text-sm text-info-700 dark:text-info-300">As competências serão atualizadas automaticamente quando o processamento terminar.</p>
                    </div>
                </div>
            </div>
        @endif

        <x-filament::section>
        <x-slot name="heading">
            Papel estratégico das competências do perfil
        </x-slot>

        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
            Núcleo (base da atuação), Expansão (em desenvolvimento) ou Diferenciação (vantagem competitiva). A
            situação é calculada a partir do currículo atual — atualize o texto na página Currículo.
        </p>

        {{ $this->table }}

        @if (blank(app(\App\Services\ResumeService::class)->content(auth()->user(), \App\Enums\ResumeLanguage::PtBr)))
            <p class="mt-4 text-sm text-warning-600 dark:text-warning-400">
                Nenhum currículo cadastrado — a classificação com IA exige o currículo para montar os candidatos.
            </p>
        @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
