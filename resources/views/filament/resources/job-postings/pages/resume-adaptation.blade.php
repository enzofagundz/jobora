<x-filament-panels::page>
    @php
        $originalResume = $this->originalResume();
        $adaptation = $this->adaptation();
    @endphp

    <div class="space-y-6">
        {{-- Contexto da vaga --}}
        <x-filament::section divided icon="heroicon-o-briefcase" heading="Vaga">
            <div class="flex flex-col gap-1">
                <p class="text-base font-semibold text-gray-950 dark:text-white">
                    {{ $this->record->role ?? 'Sem cargo' }}
                </p>
                <p class="flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-o-building-office-2" class="size-4" />
                    {{ $this->record->company ?: 'Empresa não informada' }}
                </p>
            </div>
        </x-filament::section>

        @if ($originalResume)
            @if ($adaptation)
                {{-- Comparação: original x adaptado --}}
                <div class="grid items-start gap-6 lg:grid-cols-2">
                    <x-filament::section
                        secondary
                        divided
                        icon="heroicon-o-document-text"
                        heading="Antes"
                        description="Fonte, nunca alterado."
                    >
                        {{ $this->form }}
                    </x-filament::section>

                    <x-filament::section
                        x-data="{ copied: false }"
                        divided
                        icon="heroicon-o-sparkles"
                        icon-color="primary"
                        heading="Depois"
                    >
                        <x-slot name="afterHeader">
                            <x-filament::button
                                size="sm"
                                color="gray"
                                icon="heroicon-o-clipboard-document"
                                x-on:click="navigator.clipboard.writeText($refs.adaptedBox.querySelector('textarea').value).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                            >
                                <span x-show="!copied">Copiar</span>
                                <span x-show="copied" x-cloak>Copiado</span>
                            </x-filament::button>
                        </x-slot>
                        <div x-ref="adaptedBox">
                            {{ $this->adaptedForm }}
                        </div>
                    </x-filament::section>
                </div>

                {{-- Resultados --}}
                <x-filament::section divided icon="heroicon-o-clipboard-document-list" heading="Resultados da adequação">
                    <div class="grid gap-x-6 gap-y-8 lg:grid-cols-2">
                        {{-- Requisitos contemplados --}}
                        <div>
                            <div class="flex items-center gap-3">
                                <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-success-50 text-success-600 dark:bg-success-400/10 dark:text-success-400">
                                    <x-filament::icon icon="heroicon-o-check-circle" class="size-5" />
                                </div>
                                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Requisitos contemplados</h3>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @forelse ($adaptation->covered_keywords ?? [] as $keyword)
                                    <x-filament::badge color="success">{{ $keyword }}</x-filament::badge>
                                @empty
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Nenhum requisito contemplado.</p>
                                @endforelse
                            </div>
                        </div>

                        {{-- Requisitos ausentes --}}
                        <div>
                            <div class="flex items-center gap-3">
                                <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-danger-50 text-danger-600 dark:bg-danger-400/10 dark:text-danger-400">
                                    <x-filament::icon icon="heroicon-o-x-circle" class="size-5" />
                                </div>
                                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Requisitos ausentes</h3>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @forelse ($adaptation->missing_requirements ?? [] as $requirement)
                                    <x-filament::badge color="danger">{{ $requirement }}</x-filament::badge>
                                @empty
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Nenhuma lacuna encontrada.</p>
                                @endforelse
                            </div>
                        </div>

                        {{-- Alterações realizadas --}}
                        <div>
                            <div class="flex items-center gap-3">
                                <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-400">
                                    <x-filament::icon icon="heroicon-o-pencil-square" class="size-5" />
                                </div>
                                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Alterações realizadas</h3>
                            </div>
                            @if (filled($adaptation->changes ?? []))
                                <ul class="mt-3 list-disc space-y-2 ps-4 text-sm text-gray-700 dark:text-gray-300 marker:text-gray-400 dark:marker:text-gray-500">
                                    @foreach ($adaptation->changes as $change)
                                        <li>{{ $change }}</li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Nenhuma alteração registrada.</p>
                            @endif
                        </div>

                        {{-- Alertas de integridade --}}
                        <div>
                            <div class="flex items-center gap-3">
                                <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-warning-50 text-warning-600 dark:bg-warning-400/10 dark:text-warning-400">
                                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="size-5" />
                                </div>
                                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Alertas de integridade</h3>
                            </div>
                            @if (filled($adaptation->alerts ?? []))
                                <ul class="mt-3 list-disc space-y-2 ps-4 text-sm text-gray-700 dark:text-gray-300 marker:text-warning-500 dark:marker:text-warning-400">
                                    @foreach ($adaptation->alerts as $alert)
                                        <li>{{ $alert }}</li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Nenhum alerta.</p>
                            @endif
                        </div>
                    </div>
                </x-filament::section>
            @else
                {{-- Sem adaptação ainda: mostra o original e orienta a ação --}}
                <div class="max-w-2xl">
                    <x-filament::section
                        secondary
                        divided
                        icon="heroicon-o-document-text"
                        heading="Currículo original"
                        description="Fonte, nunca alterado."
                    >
                        {{ $this->form }}
                    </x-filament::section>
                </div>

                <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center dark:border-white/10">
                    <div class="mx-auto mb-4 flex size-12 items-center justify-center rounded-full bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                        <x-filament::icon icon="heroicon-o-sparkles" class="size-6" />
                    </div>
                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Nenhuma adequação gerada</h2>
                    <p class="mx-auto mt-1 max-w-md text-sm text-gray-500 dark:text-gray-400">
                        Use o botão "Adequar currículo" para gerar a versão adaptada desta vaga.
                    </p>
                </div>
            @endif
        @else
            {{-- Sem currículo cadastrado --}}
            <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center dark:border-white/10">
                <div class="mx-auto mb-4 flex size-12 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-o-document-text" class="size-6" />
                </div>
                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Nenhum currículo cadastrado</h2>
                <p class="mx-auto mt-1 max-w-md text-sm text-gray-500 dark:text-gray-400">
                    Cadastre o currículo na página Currículo antes de adequá-lo a uma vaga.
                </p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
