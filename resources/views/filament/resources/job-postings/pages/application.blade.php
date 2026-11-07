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
        @if ($activeOperation || $this->hasActivePreparationOperation()) wire:poll.5s="refreshAiOperation" @endif
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

        @unless ($this->canGenerateApplicationDocuments())
            <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm text-warning-900 dark:border-warning-500/30 dark:bg-warning-400/10 dark:text-warning-100" role="status">
                Esta vaga não está pronta para novas gerações ou preparação. Você ainda pode consultar o histórico e o material já congelado.
            </div>
        @endunless

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
                                    <x-filament::button wire:click="simulateAtsScore" color="info" icon="heroicon-o-chart-bar" size="sm" :disabled="$this->hasActiveAiOperation() || ! $this->canGenerateApplicationDocuments()">Simular nota ATS</x-filament::button>
                                    <x-filament::button wire:click="toggleAtsOptimization" color="gray" size="sm" icon="heroicon-o-x-mark" wire:confirm="Desativar a otimização? O bloco invisível será removido." :disabled="$this->hasActiveAiOperation() || ! $this->canGenerateApplicationDocuments()">Desativar otimização</x-filament::button>
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
                                    <x-filament::button wire:click="toggleAtsOptimization" color="primary" size="sm" icon="heroicon-o-cpu-chip" :disabled="$this->hasActiveAiOperation() || ! $this->canGenerateApplicationDocuments()">Otimizar para triagem IA</x-filament::button>
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

        <x-filament::section divided icon="heroicon-o-document-check" heading="Preparar material da candidatura" description="Gera um conjunto congelado para revisão. Não aprova nem envia candidatura.">
            <div class="space-y-5">
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="grid gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-200">
                        Destino da candidatura
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model="destinationApplicationMethodId">
                                <option value="">Selecione uma forma cadastrada</option>
                                @foreach ($methods as $method)
                                    <option value="{{ $method->id }}">
                                        {{ $this->methodTypeLabel($method->type?->value ?? '') }}
                                        @if ($method->email) · {{ $method->email }} @elseif ($method->url) · {{ $method->platform ?: $method->url }} @elseif ($method->phone) · {{ $method->phone }} @endif
                                    </option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                    <div class="flex flex-col justify-center gap-3">
                        @if ($language === \App\Enums\ResumeLanguage::En)
                            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                                <input type="checkbox" wire:model="preparationTranslateMissing" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                                Solicitar tradução se currículo EN estiver ausente
                            </label>
                        @endif
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                            <input type="checkbox" wire:model="preparationCoverLetterRequested" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                            Incluir carta de apresentação
                        </label>
                        @if ($this->canUseAtsOptimization())
                            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                                <input type="checkbox" wire:model="preparationAtsConsent" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                                Incluir bloco ATS otimizado (consentimento explícito)
                            </label>
                        @endif
                    </div>
                </div>

                <div class="space-y-2">
                    {{ $this->preparationForm }}
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <x-filament::button wire:click="prepareApplicationMaterials" icon="heroicon-o-document-check" :disabled="$this->hasActivePreparationOperation() || ! $this->canGenerateApplicationDocuments()">
                        Preparar material
                    </x-filament::button>
                    @if ($preparationResult)
                        <x-filament::button color="gray" wire:click="refreshAiOperation">Atualizar estado</x-filament::button>
                    @endif
                </div>

                @if ($preparationResult)
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10" role="status">
                        <p class="font-semibold">{{ match ($preparationResult['state']) { 'preparing' => 'Preparação em andamento', 'pending_information' => 'Faltam informações', default => 'Material preparado para revisão' } }}</p>
                        @if ($preparationResult['preparation_id'] ?? false)
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Identificador da preparação: {{ $preparationResult['preparation_id'] }}</p>
                        @endif
                        @if ($preparationResult['fingerprint'] ?? false)
                            <p class="mt-1 break-all font-mono text-xs text-gray-500">Fingerprint: {{ $preparationResult['fingerprint'] }}</p>
                        @endif
                        @if ($preparationResult['source_versions'] ?? false)
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                                Versões congeladas: perfil confirmado {{ $preparationResult['source_versions']['profile_confirmed_version'] ?? '—' }} · vaga {{ $preparationResult['source_versions']['opportunity_version'] ?? '—' }}
                            </p>
                        @endif
                    </div>

                    @if ($preparationOperations)
                        <ul class="space-y-2 text-sm">
                            @foreach ($preparationOperations as $operation)
                                <li class="flex items-center gap-2">
                                    <x-filament::badge :color="$operation['active'] ? 'info' : ($operation['status'] === 'completed' ? 'success' : 'gray')">{{ $operation['status'] }}</x-filament::badge>
                                    <span>{{ $operation['type'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($preparationResult['pending'] ?? [])
                        <div class="rounded-lg border border-warning-300 bg-warning-50 p-4 dark:border-warning-500/30 dark:bg-warning-400/10">
                            <h3 class="font-semibold">Pendências</h3>
                            <ul class="mt-2 list-disc space-y-1 ps-5 text-sm">
                                @foreach ($preparationResult['pending'] as $pending)
                                    <li>
                                        {{ $this->preparationPendingLabel($pending) }}
                                        @if ($pending['question_id'] ?? false) · {{ $preparationQuestionLabels[$pending['question_id']] ?? 'Pergunta '.$pending['question_id'] }} @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($preparationResult['stale'] ?? false)
                        <p class="rounded-lg bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-400/10 dark:text-warning-200">Fontes mudaram depois da preparação. Gere novo material para usar versões atuais. O snapshot antigo permanece separado.</p>
                    @endif

                    @if ($preparationResult['snapshot'] ?? null)
                        <x-filament::section heading="Material congelado" collapsible>
                            <div class="grid gap-5 md:grid-cols-2">
                                <div>
                                    <h3 class="text-sm font-semibold">Destino</h3>
                                    <p class="mt-1 text-sm">{{ $preparationResult['snapshot']['destination']['type'] ?? '—' }}</p>
                                    @if ($preparationResult['snapshot']['destination']['email'] ?? false)<p class="text-sm">{{ $preparationResult['snapshot']['destination']['email'] }}</p>@endif
                                    @if ($preparationResult['snapshot']['destination']['url'] ?? false)<p class="break-all text-sm">{{ $preparationResult['snapshot']['destination']['url'] }}</p>@endif
                                    @if ($preparationResult['snapshot']['destination']['instructions'] ?? false)<p class="mt-1 whitespace-pre-wrap text-sm">{{ $preparationResult['snapshot']['destination']['instructions'] }}</p>@endif
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold">Vaga</h3>
                                    <p class="mt-1 text-sm">{{ $preparationResult['snapshot']['opportunity']['role'] ?? 'Cargo não informado' }} · {{ $preparationResult['snapshot']['opportunity']['company'] ?? 'Empresa não informada' }}</p>
                                </div>
                                <div class="md:col-span-2">
                                    <h3 class="text-sm font-semibold">Perfil confirmado usado</h3>
                                    <pre class="mt-1 max-h-64 overflow-auto whitespace-pre-wrap text-sm">{{ json_encode($preparationResult['snapshot']['profile'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </div>
                                <div class="md:col-span-2">
                                    <h3 class="text-sm font-semibold">Currículo adaptado</h3>
                                    <pre class="mt-1 max-h-80 overflow-auto whitespace-pre-wrap text-sm">{{ $preparationResult['snapshot']['adaptation']['adapted_content'] ?? '' }}</pre>
                                </div>
                                @if ($preparationResult['snapshot']['cover_letter'] ?? false)
                                    <div class="md:col-span-2">
                                        <h3 class="text-sm font-semibold">Carta de apresentação</h3>
                                        <pre class="mt-1 max-h-64 overflow-auto whitespace-pre-wrap text-sm">{{ $preparationResult['snapshot']['cover_letter'] }}</pre>
                                    </div>
                                @endif
                            </div>
                            @if ($preparationResult['artifacts'] ?? [])
                                <div class="mt-5">
                                    <h3 class="text-sm font-semibold">{{ $preparationResult['state'] === 'pending_information' ? 'Arquivos gerados até agora' : 'Arquivos gerados' }}</h3>
                                    <ul class="mt-2 space-y-2 text-sm">
                                        @foreach ($preparationResult['artifacts'] as $artifact)
                                            @php $downloadUrl = $this->preparedArtifactUrl((string) $artifact['artifact_id']); @endphp
                                            <li class="flex flex-wrap items-center gap-3">
                                                <span>{{ $artifact['type'] }} ({{ $artifact['language'] }}) · {{ number_format($artifact['bytes']) }} bytes · expira {{ $artifact['artifact_expires_at'] }}</span>
                                                @if ($downloadUrl)
                                                    <x-filament::link :href="$downloadUrl" icon="heroicon-o-arrow-down-tray" target="_blank">
                                                        {{ $artifact['type'] === 'resume' ? 'Baixar currículo adaptado' : 'Baixar carta de apresentação' }}
                                                    </x-filament::link>
                                                @else
                                                    <span class="text-warning-700 dark:text-warning-300">Artefato indisponível para download.</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </x-filament::section>
                    @endif

                    @if ($approvalData && ($approvalData['approvals'] ?? []))
                        <x-filament::section heading="Consentimento registrado pelo Hermes" icon="heroicon-o-shield-check">
                            <div class="space-y-4">
                                <p class="text-sm text-gray-700 dark:text-gray-200">Hermes atesta uma interação humana em conversa privada. Esta aprovação registrada não envia candidatura nem prova consentimento independente contra um Hermes comprometido.</p>
                                <p class="text-xs text-gray-500">{{ $approvalData['trust_statement'] }}</p>
                                @foreach ($approvalData['approvals'] as $approval)
                                    @php
                                        $approvalCanBeActive = $approval['status'] === 'active'
                                            && ($preparationResult['current'] ?? false)
                                            && ! ($preparationResult['stale'] ?? true)
                                            && $preparationResult['state'] === 'awaiting_approval'
                                            && ($preparationResult['pending'] ?? []) === [];
                                    @endphp
                                    <article class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                                        <div class="flex flex-wrap items-center justify-between gap-3">
                                            <h3 class="font-semibold">{{ $this->approvalStatusLabel($approval) }}</h3>
                                            <x-filament::badge :color="$approvalCanBeActive ? 'success' : ($approval['status'] === 'revoked' ? 'gray' : 'warning')">
                                                {{ $approvalCanBeActive ? 'Válida para este material' : ($approval['status'] === 'revoked' ? 'Revogada' : ($approval['status'] === 'expired' ? 'Expirada' : 'Inválida para este material')) }}
                                            </x-filament::badge>
                                        </div>
                                        <div class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                                            <p>Integração: {{ $this->approvalIntegrationName((int) $approval['integration_id']) }}</p>
                                            <p>ID Telegram: <span class="font-mono">{{ $approval['telegram_user_id'] }}</span></p>
                                            <p>Chat privado: <span class="font-mono">{{ $approval['chat_id'] }}</span></p>
                                            <p>Mensagem de consentimento: <span class="font-mono">{{ $approval['message_id'] }}</span></p>
                                            <p>Consentimento em: {{ $approval['approved_at'] }}</p>
                                            <p>Registrada em: {{ $approval['received_at'] }}</p>
                                            <p>Expira em: {{ $approval['expires_at'] }}</p>
                                            @if ($approval['revoked_at'])<p>Revogada em: {{ $approval['revoked_at'] }}</p>@endif
                                        </div>
                                        <p class="mt-3 break-all font-mono text-xs text-gray-500">Fingerprint do material: {{ $approval['fingerprint'] }}</p>
                                        <p class="mt-2 text-xs text-gray-500">Preparação {{ $approval['preparation_id'] }} · destino e documentos correspondem ao material congelado acima.</p>
                                        @if ($approval['reasons'] ?? [])
                                            <div class="mt-3 rounded-lg bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-400/10 dark:text-warning-200">
                                                Não está válida para uso: {{ collect($approval['reasons'])->map(fn (string $reason): string => match ($reason) {
                                                    'approval_revoked' => 'foi revogada',
                                                    'approval_expired' => 'expirou',
                                                    'preparation_stale' => 'preparação mudou ou foi substituída',
                                                    'artifacts_unavailable' => 'documentos expiraram ou foram revogados',
                                                    'binding_inactive' => 'vínculo Telegram não está ativo',
                                                    'integration_inactive' => 'integração não está ativa',
                                                    'account_inactive' => 'conta não está ativa',
                                                    default => 'estado não pode ser validado',
                                                })->implode('; ') }}.
                                            </div>
                                        @endif
                                        @if ($approvalCanBeActive)
                                            <x-filament::button class="mt-4" color="danger" wire:click="revokeApplicationApproval('{{ $approval['id'] }}')" wire:confirm="Revogar esta aprovação registrada? Isso não altera nem envia candidatura.">Revogar aprovação registrada</x-filament::button>
                                        @endif
                                    </article>
                                @endforeach
                                @if (array_key_exists('authorizes_submission', $approvalData) && $approvalData['authorizes_submission'] === false)
                                    <p class="text-sm font-medium text-gray-600 dark:text-gray-300">Aprovação registrada não é candidatura enviada.</p>
                                @endif
                            </div>
                        </x-filament::section>
                    @endif
                @endif
            </div>
        </x-filament::section>

        {{-- Acompanhamento da candidatura: estado atual, pipeline e histórico --}}
        @php
            $application = $this->application();
            $events = $this->applicationEvents();
            $reached = $this->reachedStages();
            $current = $application?->status;
            $next = $this->suggestedNextStage();
            $lastEvent = $events->first();
        @endphp

        <x-filament::section divided icon="heroicon-o-paper-airplane" heading="Estado do envio">
            @if ($this->submissionSummary)
                @php
                    $submissionStateLabel = match ($this->submissionSummary['state']) {
                        'reserved' => 'Reserva ativa', 'sending' => 'Envio em andamento',
                        'unknown' => 'Resultado incerto', 'succeeded' => 'Envio confirmado',
                        'failed' => 'Envio não concluído', 'expired' => 'Reserva expirada',
                        default => 'Estado indisponível',
                    };
                @endphp
                <div class="space-y-4" role="status">
                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament::badge :color="$this->submissionSummary['blocked'] ? 'warning' : 'gray'">{{ $submissionStateLabel }}</x-filament::badge>
                        <span class="text-sm text-gray-600 dark:text-gray-300">Tentativa {{ $this->submissionSummary['attempt_id'] }} · revisão {{ $this->submissionSummary['revision'] }}</span>
                    </div>
                    @if ($this->submissionDetails['events'] ?? [])
                        <ul class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
                            @foreach ($this->submissionDetails['events'] as $event)
                                <li>{{ match ($event['kind']) { 'reconciled' => 'Reconciliação registrada', 'reported' => 'Resultado informado', 'reservation' => 'Reserva criada', 'consumed' => 'Execução autorizada', default => 'Atualização registrada' } }} · {{ $event['received_at'] }}</li>
                                @if ($event['evidence'])
                                    <li class="break-words">Evidência: {{ $event['evidence']['kind'] }}@if ($event['evidence']['reference'] ?? null) · {{ $event['evidence']['reference'] }}@endif @if ($event['evidence']['details'] ?? null) · {{ $event['evidence']['details'] }}@endif</li>
                                @endif
                            @endforeach
                        </ul>
                    @endif
                    <x-filament::button color="gray" size="sm" wire:click="refreshSubmissionStatus" wire:loading.attr="disabled" wire:target="refreshSubmissionStatus,reconcileSubmission">Atualizar estado</x-filament::button>
                    @if ($this->submissionSummary['state'] === 'unknown')
                        <div class="space-y-4 rounded-xl border border-warning-300 bg-warning-50 p-4 dark:border-warning-500/30 dark:bg-warning-400/10">
                            <p class="text-sm text-warning-900 dark:text-warning-100">Confirme o resultado com evidência. Resultado incerto continua bloqueando novo envio.</p>
                            @if ($this->submissionError)
                                <p class="text-sm text-danger-600 dark:text-danger-400" role="alert">{{ $this->submissionError }}</p>
                            @endif
                            <label class="block text-sm font-medium">Resultado
                                <x-filament::input.wrapper><x-filament::input.select wire:model.live="reconciliationData.outcome"><option value="unknown">Ainda incerto</option><option value="succeeded">Enviado</option><option value="failed">Não enviado</option></x-filament::input.select></x-filament::input.wrapper>
                            </label>
                            <label class="block text-sm font-medium">Tipo de evidência
                                <x-filament::input.wrapper><x-filament::input.select wire:model="reconciliationData.evidence_kind"><option value="ambiguous">Inconclusiva</option><option value="submission_receipt">Comprovante de envio</option><option value="not_submitted">Comprovante de não envio</option></x-filament::input.select></x-filament::input.wrapper>
                            </label>
                            <label class="block text-sm font-medium">Referência da evidência
                                <x-filament::input.wrapper><x-filament::input inputmode="text" wire:model="reconciliationData.reference" /></x-filament::input.wrapper>
                            </label>
                            <label class="block text-sm font-medium">Detalhes
                                <x-filament::input.wrapper><textarea wire:model="reconciliationData.details" rows="3" maxlength="2000" class="fi-input block w-full border-none bg-transparent px-3 py-2 text-sm text-gray-950 outline-none dark:text-white"></textarea></x-filament::input.wrapper>
                            </label>
                            @if ($this->reconciliationData['outcome'] === 'failed')
                                <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="reconciliationData.executor_stopped" class="mt-1 rounded border-gray-300 text-primary-600 focus:ring-primary-500" /><span>Confirmei que execução anterior foi encerrada.</span></label>
                            @endif
                            <x-filament::button wire:click="reconcileSubmission" wire:loading.attr="disabled" wire:target="reconcileSubmission">Reconciliar tentativa</x-filament::button>
                        </div>
                    @endif
                </div>
            @else
                <p class="text-sm text-gray-600 dark:text-gray-300">Nenhuma tentativa de envio registrada. Acompanhar candidatura é separado do envio.</p>
            @endif
        </x-filament::section>

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
