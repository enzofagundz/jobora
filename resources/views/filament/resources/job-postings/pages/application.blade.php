<x-filament-panels::page>
    @php
        $originalResume = $this->originalResume();
        $adaptation = $this->adaptation();
        $language = $this->language();
        $activeOperation = $this->activeAiOperation();
        $methods = $this->applicationMethods();
        $sourceUrl = \App\Filament\Resources\JobPostings\Pages\ApplicationPage::safeHttpUrl($this->record->source_url);
    @endphp

    <div
        class="space-y-6"
        @if ($activeOperation) wire:poll.5s="refreshAiOperation" @endif
    >
        @if ($activeOperation)
            <div
                class="rounded-xl border border-info-200 bg-info-50 p-4 dark:border-info-500/30 dark:bg-info-400/10"
                role="status"
            >
                <div class="flex items-start gap-3">
                    <x-filament::icon icon="heroicon-o-arrow-path" class="mt-0.5 size-5 shrink-0 text-info-600 dark:text-info-400" />
                    <div>
                        <p class="text-sm font-semibold text-info-800 dark:text-info-200">
                            {{ $this->aiOperationLabel($activeOperation) }} em andamento
                        </p>
                        <p class="mt-1 text-sm text-info-700 dark:text-info-300">
                            O resultado será atualizado automaticamente quando o processamento terminar.
                        </p>
                    </div>
                </div>
            </div>
        @endif

        {{-- Seletor de idioma: controla original, adaptado, ATS e PDF. Default PT-BR. --}}
        <x-filament::section compact>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <label for="resumeLanguage" class="text-sm font-medium text-gray-700 dark:text-gray-200">
                    Idioma
                </label>
                <div class="w-56">
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="resumeLanguage" wire:model.live="resumeLanguage">
                            @foreach (\App\Enums\ResumeLanguage::cases() as $option)
                                <option value="{{ $option->value }}">
                                    {{ $this->languageName($option) }}
                                </option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Controla o currículo original, a adequação e o PDF exibidos.
                </p>
            </div>
        </x-filament::section>

        {{-- Vaga: cargo, empresa, status contextual, link externo validado --}}
        <x-filament::section divided icon="heroicon-o-briefcase" heading="Vaga">
            <div class="flex flex-col gap-2">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-base font-semibold text-gray-950 dark:text-white">
                        {{ $this->record->role ?? 'Sem cargo' }}
                    </p>
                    <x-filament::badge color="success">
                        {{ $this->record->status?->getLabel() }}
                    </x-filament::badge>
                </div>
                <p class="flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-o-building-office-2" class="size-4" />
                    {{ $this->record->company ?: 'Empresa não informada' }}
                </p>
                @if ($sourceUrl)
                    <x-filament::link
                        :href="$sourceUrl"
                        icon="heroicon-o-arrow-top-right-on-square"
                        target="_blank"
                    >
                        Ver vaga original
                    </x-filament::link>
                @endif
            </div>
        </x-filament::section>

        {{-- Currículo: comparação original x adaptado + resultados + empty states --}}
        @if ($originalResume)
            @if ($adaptation)
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

                <x-filament::section divided icon="heroicon-o-clipboard-document-list" heading="Resultados da adequação">
                    <div class="grid gap-x-6 gap-y-8 lg:grid-cols-2">
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

                @if(\Illuminate\Support\Facades\Gate::allows('useAtsOptimization'))
                    <x-filament::section divided icon="heroicon-o-cpu-chip" heading="Otimização para triagem IA" description="Bloco invisível para ATS com IA — visível apenas para você.">

                        @if($adaptation->is_ats_optimized && filled($adaptation->ats_prompt))
                            <div class="space-y-4">
                                <div class="rounded-lg border border-warning-300 bg-warning-50 p-4 dark:border-warning-500/30 dark:bg-warning-400/10">
                                    <div class="flex gap-3">
                                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="size-5 shrink-0 text-warning-600 dark:text-warning-400" />
                                        <div>
                                            <p class="text-sm font-semibold text-warning-800 dark:text-warning-200">Aviso: bloco invisível para ATS</p>
                                            <p class="mt-1 text-sm text-warning-700 dark:text-warning-300">Este PDF contém um bloco invisível (texto branco 1px ao final) destinado a sistemas de triagem com IA. Alguns ATS podem detectar como manipulação. Use por sua conta.</p>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <p class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-200">Preview do bloco invisível (extraído por ATS, invisível no PDF):</p>
                                    <div class="rounded-lg border border-gray-200 bg-yellow-50 p-3 dark:border-white/10 dark:bg-yellow-400/10">
                                        <p class="font-mono text-xs text-gray-800 dark:text-gray-200">{{ $adaptation->ats_prompt }}</p>
                                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Invisível no PDF — extraído por parsers de texto. Máx 300 caracteres, máx 15 palavras-chave.</p>
                                    </div>
                                    @if ($this->atsPromptIsStale())
                                        <p class="mt-2 text-xs font-medium text-warning-700 dark:text-warning-300">Este bloco foi gerado com uma versão anterior do currículo ou da vaga.</p>
                                    @endif
                                </div>

                                <div class="flex items-center gap-2">
                                    <input type="checkbox" wire:model.live="atsConsent" id="atsConsent" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                                    <label for="atsConsent" class="text-sm text-gray-700 dark:text-gray-300">Entendo os riscos e quero baixar o PDF otimizado assim.</label>
                                </div>

                                @if($this->atsScoreResult)
                                    <div class="rounded-lg border border-info-200 bg-info-50 p-3 dark:border-info-500/30 dark:bg-info-400/10">
                                        <p class="text-sm font-semibold text-info-800 dark:text-info-200">Nota simulada ATS: {{ $this->atsScoreResult['score'] }}/10</p>
                                        <p class="mt-1 text-sm text-info-700 dark:text-info-300">{{ $this->atsScoreResult['rationale'] }}</p>
                                        @if ($this->atsScoreIsStale())
                                            <p class="mt-2 text-xs font-medium text-warning-700 dark:text-warning-300">Nota desatualizada em relação à versão atual da adaptação.</p>
                                        @endif
                                    </div>
                                @endif

                                <div class="flex flex-wrap gap-2">
                                    <x-filament::button wire:click="simulateAtsScore" color="info" icon="heroicon-o-chart-bar" size="sm" :disabled="$this->hasActiveAiOperation()">Simular nota ATS</x-filament::button>
                                    <x-filament::button wire:click="toggleAtsOptimization" color="gray" size="sm" icon="heroicon-o-x-mark" wire:confirm="Desativar a otimização? O bloco invisível será removido." :disabled="$this->hasActiveAiOperation()">Desativar otimização</x-filament::button>
                                    @if($this->adaptation()?->is_ats_optimized && $this->atsConsent)
                                        <x-filament::button tag="a" :href="route('filament.admin.resume-pdf.download', ['posting' => $this->record, 'ats_consent' => '1', 'language' => $language->value])" target="_blank" color="success" icon="heroicon-o-arrow-down-tray" size="sm">Baixar PDF otimizado</x-filament::button>
                                    @endif
                                </div>
                            </div>
                        @else
                            <div class="space-y-3">
                                <p class="text-sm text-gray-600 dark:text-gray-300">Ative a otimização para gerar um bloco persuasivo oculto ao final do PDF. O bloco é determinístico, usa apenas palavras-chave já presentes no seu currículo e respeita os limites (300 caracteres, 15 palavras).</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Desativado por padrão. Ao re-adequar com otimização ativa, o bloco é regenerado.</p>
                                <div>
                                    <x-filament::button wire:click="toggleAtsOptimization" color="primary" size="sm" icon="heroicon-o-cpu-chip" :disabled="$this->hasActiveAiOperation()">Otimizar para triagem IA</x-filament::button>
                                </div>
                            </div>
                        @endif
                    </x-filament::section>
                @endif
            @else
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
                        Ainda não há adequação em {{ $this->languageName($language) }} para esta vaga.
                        Use o dropdown "Adequar currículo" para gerar a versão adaptada deste idioma.
                    </p>
                </div>
            @endif
        @else
            <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center dark:border-white/10">
                <div class="mx-auto mb-4 flex size-12 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-o-document-text" class="size-6" />
                </div>
                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Nenhum currículo cadastrado</h2>
                <p class="mx-auto mt-1 max-w-md text-sm text-gray-500 dark:text-gray-400">
                    {{ $language->missingResumeGuidance() }}
                </p>
                <div class="mt-4">
                    <x-filament::link
                        :href="\App\Filament\Pages\ResumePage::getUrl()"
                        icon="heroicon-o-document-text"
                    >
                        Ir para a página Currículo
                    </x-filament::link>
                </div>
            </div>
        @endif

        {{-- Acompanhamento da candidatura: estado atual, pipeline e histórico --}}
        @php
            $application = $this->application();
            $events = $this->applicationEvents();
            $reached = $this->reachedStages();
            $current = $application?->status;
            $next = $this->suggestedNextStage();
            $lastEvent = $events->first();
        @endphp

        <x-filament::section divided icon="heroicon-o-map" heading="Acompanhamento da candidatura">
            @if ($application === null)
                <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center dark:border-white/10">
                    <div class="mx-auto mb-4 flex size-12 items-center justify-center rounded-full bg-info-50 text-info-600 dark:bg-info-400/10 dark:text-info-400">
                        <x-filament::icon icon="heroicon-o-paper-airplane" class="size-6" />
                    </div>
                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Nenhum acompanhamento iniciado</h2>
                    <p class="mx-auto mt-1 max-w-md text-sm text-gray-500 dark:text-gray-400">
                        Métodos e currículo adaptado não significam candidatura enviada. Use
                        “Iniciar acompanhamento” no topo da página para criar a candidatura.
                    </p>
                </div>
            @else
                <div class="space-y-6">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Status atual:</span>
                        <x-filament::badge :color="$this->statusColor($current)">
                            {{ $current->getLabel() }}
                        </x-filament::badge>
                    </div>

                    @if ($lastEvent)
                        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Última movimentação</p>
                            <p class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">{{ $lastEvent->title }}</p>
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                {{ $lastEvent->occurred_at->format('d/m/Y H:i') }}
                                @if ($lastEvent->contact)
                                    · {{ $lastEvent->contact }}
                                @endif
                            </p>
                            @if ($lastEvent->notes)
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $lastEvent->notes }}</p>
                            @endif
                        </div>
                    @endif

                    <div>
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Pipeline</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach (\App\Enums\ApplicationStatus::pipelineStages() as $stage)
                                <x-filament::badge
                                    :color="in_array($stage, $reached, true) ? $this->statusColor($stage) : 'gray'"
                                >
                                    {{ $stage->getLabel() }}
                                    @if ($stage === $current)
                                        <x-filament::icon icon="heroicon-o-arrow-right-circle" class="ms-1 size-3" />
                                    @endif
                                </x-filament::badge>
                            @endforeach

                            @if ($current === \App\Enums\ApplicationStatus::Rejected || $current === \App\Enums\ApplicationStatus::Withdrawn)
                                <x-filament::badge :color="$this->statusColor($current)">
                                    {{ $current->getLabel() }}
                                </x-filament::badge>
                            @endif
                        </div>
                    </div>

                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Próxima etapa sugerida</p>
                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-200">
                            @if ($next)
                                {{ $next->getLabel() }}
                            @else
                                Não definida
                            @endif
                        </p>
                    </div>

                    <div>
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Histórico</p>
                        {{ $this->table }}
                    </div>
                </div>
            @endif
        </x-filament::section>
        {{-- Como se candidatar: métodos persistidos, com origem e ação segura --}}
        <x-filament::section divided icon="heroicon-o-paper-airplane" heading="Como se candidatar">
            <div class="space-y-4">
                @forelse ($methods as $method)
                    @php
                        $typeValue = $method->type?->value ?? '';
                        $safeUrl = \App\Filament\Resources\JobPostings\Pages\ApplicationPage::safeHttpUrl($method->url);
                        $typeLabel = \App\Filament\Resources\JobPostings\Pages\ApplicationPage::methodTypeLabel($typeValue);
                        $actionLabel = \App\Filament\Resources\JobPostings\Pages\ApplicationPage::methodActionLabel($typeValue);
                        $sourceLabel = $method->source?->getLabel();
                        $showInstructions = \App\Filament\Resources\JobPostings\Pages\ApplicationPage::shouldShowInstructions($method);
                    @endphp
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-filament::badge color="info">{{ $typeLabel }}</x-filament::badge>
                            <x-filament::badge color="gray">{{ $sourceLabel }}</x-filament::badge>
                            @if (filled($method->platform))
                                <span class="text-sm text-gray-700 dark:text-gray-200">{{ $method->platform }}</span>
                            @endif
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-gray-700 dark:text-gray-200">
                            @if ($safeUrl)
                                <x-filament::link
                                    :href="$safeUrl"
                                    icon="heroicon-o-arrow-top-right-on-square"
                                    target="_blank"
                                >
                                    {{ $actionLabel }}
                                </x-filament::link>
                            @elseif (filled($method->email))
                                <x-filament::link
                                    :href="'mailto:'.$method->email"
                                    icon="heroicon-o-envelope"
                                >
                                    {{ $method->email }}
                                </x-filament::link>
                            @elseif (filled($method->phone))
                                <x-filament::link
                                    :href="'tel:'.preg_replace('/\s+/', '', $method->phone)"
                                    icon="heroicon-o-phone"
                                >
                                    {{ $method->phone }}
                                </x-filament::link>
                            @endif
                            @if ($showInstructions && filled($method->instructions))
                                <p class="basis-full text-sm text-gray-600 dark:text-gray-300">
                                    {{ $method->instructions }}
                                </p>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Forma de candidatura não identificada.
                    </p>
                @endforelse
            </div>
        </x-filament::section>
    </div>
    <x-filament-actions::modals />
</x-filament-panels::page>
