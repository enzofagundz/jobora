<x-filament-panels::page>
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
        <div>
            <p class="text-sm font-medium text-gray-950 dark:text-white">Versão atual: {{ $version }}</p>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                @if ($confirmedVersion)
                    Última confirmação: versão {{ $confirmedVersion }}{{ $confirmedAt ? ' · '.$confirmedAt : '' }}
                @else
                    Nenhuma versão confirmada ainda.
                @endif
            </p>
        </div>
        @if ($hasUnconfirmedChanges)
            <x-filament::badge color="warning">Alterações não confirmadas</x-filament::badge>
        @endif
    </div>

    <form wire:submit="saveDraft" class="space-y-6">
        {{ $this->form }}
        <div class="flex flex-wrap justify-end gap-3">
            <x-filament::button type="submit" color="gray" icon="heroicon-o-document-arrow-down">Salvar rascunho</x-filament::button>
            <x-filament::button type="button" wire:click="requestConfirmation" icon="heroicon-o-check-badge">Confirmar perfil</x-filament::button>
        </div>
    </form>

    @if ($confirmedSnapshot)
        <x-filament::section class="mt-8" heading="Perfil confirmado" description="Quando um matching for solicitado, esta versão será sua referência.">
            <pre class="overflow-auto whitespace-pre-wrap text-sm">{{ json_encode($confirmedSnapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </x-filament::section>
    @endif

    <x-filament::section class="mt-8" heading="Importar do currículo" description="A extração cria uma proposta revisável. Ela não altera o perfil até você confirmar.">
        <div class="flex flex-wrap items-end gap-3">
            <label class="grid gap-1 text-sm font-medium text-gray-950 dark:text-white">
                Idioma do currículo
                <select wire:model="importLanguage" class="fi-input rounded-lg border-gray-300 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <option value="pt-BR">Português (Brasil)</option>
                    <option value="en">Inglês</option>
                </select>
            </label>
            <x-filament::button wire:click="requestProfileImport" icon="heroicon-o-document-magnifying-glass">Solicitar extração</x-filament::button>
            @if ($importOperation)
                <x-filament::button color="gray" wire:click="refreshProfileImport">Atualizar estado</x-filament::button>
            @endif
        </div>

        @if ($importOperation)
            <div class="mt-5 rounded-lg border border-gray-200 p-4 dark:border-white/10">
                <p class="font-medium">Estado da extração: {{ $importOperation['status'] }}</p>
                @if ($importOperation['failure_code'])
                    <p class="mt-1 text-sm text-danger-600">A tentativa falhou ({{ $importOperation['failure_code'] }}).</p>
                @endif
                @if ($importOperation['retry_allowed'])
                    <x-filament::button class="mt-3" color="gray" wire:click="retryProfileImport">Tentar novamente</x-filament::button>
                @endif
            </div>
        @endif

        @if ($importProposal)
            <div class="mt-5 space-y-5">
                @if ($importProposal['source']['source_stale'])
                    <x-filament::badge color="warning">Currículo de origem mudou desde a extração</x-filament::badge>
                @endif
                @if ($importProposal['status'] === 'pending' && $importProposal['stale'])
                    <div class="rounded-lg border border-warning-300 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-400/20 dark:bg-warning-400/10 dark:text-warning-200">
                        <p class="font-semibold">Proposta desatualizada</p>
                        <p>O perfil mudou desde a solicitação. Rejeite esta proposta e solicite uma nova extração.</p>
                    </div>
                @endif
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    Origem: currículo {{ $importProposal['source']['language'] === 'en' ? 'em inglês' : 'em português (Brasil)' }}.
                    Revise os dados com atenção: trechos citados ajudam na conferência, mas não garantem que interpretação esteja correta.
                </p>

                <div class="space-y-3">
                    @foreach ($importProposal['changes'] as $section => $change)
                        <x-filament::section :heading="match ($section) { 'experiences' => 'Experiências', 'education' => 'Formação', 'competencies' => 'Competências', default => $section }" collapsible>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <h4 class="text-sm font-semibold">Perfil salvo</h4>
                                    <pre class="mt-2 overflow-auto whitespace-pre-wrap text-sm">{{ json_encode($change['before'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </div>
                                <div>
                                    <h4 class="text-sm font-semibold">Proposta extraída</h4>
                                    <pre class="mt-2 overflow-auto whitespace-pre-wrap text-sm">{{ json_encode($change['after'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </div>
                            </div>
                        </x-filament::section>
                    @endforeach
                </div>

                <x-filament::section heading="Trechos para conferência" collapsible>
                    <ul class="space-y-3">
                        @foreach ($importProposal['evidence'] as $item)
                            <li class="rounded-md bg-gray-50 p-3 text-sm dark:bg-white/5">
                                <p class="font-medium">{{ $item['path'] }}</p>
                                <blockquote class="mt-1 border-s-2 border-primary-500 ps-3">“{{ $item['quote'] }}”</blockquote>
                                <p class="mt-1 text-gray-500">Bloco {{ $item['block_id'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>

                @if (in_array('confirm', $importProposal['allowed_actions'], true))
                    <x-filament::button wire:click="requestImportConfirmation" icon="heroicon-o-check-badge">Revisar e confirmar proposta</x-filament::button>
                @endif
                @if (in_array('reject', $importProposal['allowed_actions'], true))
                    <x-filament::button color="gray" wire:click="rejectProfileImport">Rejeitar proposta</x-filament::button>
                @endif
                @if ($importProposal['status'] !== 'pending')
                    <x-filament::badge :color="$importProposal['status'] === 'confirmed' ? 'success' : 'gray'">Proposta {{ $importProposal['status'] }}</x-filament::badge>
                @endif
            </div>
        @endif
    </x-filament::section>

    <x-filament::modal id="confirm-professional-profile" :visible="$showConfirmation" sticky>
        <x-slot name="heading">Confirmar perfil profissional?</x-slot>
        <x-slot name="description">Esta versão passará a ser usada no matching. Revise seus dados antes de confirmar.</x-slot>
        <x-slot name="footer">
            <div class="flex justify-end gap-3">
                <x-filament::button color="gray" wire:click="$set('showConfirmation', false)">Voltar</x-filament::button>
                <x-filament::button wire:click="confirm">Confirmar perfil</x-filament::button>
            </div>
        </x-slot>
    </x-filament::modal>

    <x-filament::modal id="confirm-profile-import" :visible="$showImportConfirmation" sticky>
        <x-slot name="heading">Confirmar dados extraídos?</x-slot>
        <x-slot name="description">A proposta substituirá as seções correspondentes do perfil salvo. Revise diferenças e evidências antes de continuar.</x-slot>
        <x-slot name="footer">
            <div class="flex justify-end gap-3">
                <x-filament::button color="gray" wire:click="$set('showImportConfirmation', false)">Voltar</x-filament::button>
                <x-filament::button wire:click="confirmProfileImport">Confirmar proposta</x-filament::button>
            </div>
        </x-slot>
    </x-filament::modal>
</x-filament-panels::page>
