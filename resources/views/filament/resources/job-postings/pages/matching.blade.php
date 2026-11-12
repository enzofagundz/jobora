<x-filament-panels::page>
    @php
        $history = $matchingData['history']['items'] ?? [];
        $canRequest = $this->record->readiness === 'ready' && $profileConfirmed;
    @endphp

    <div class="space-y-6">
        <x-filament::section
            icon="heroicon-o-scale"
            heading="Compatibilidade com a oportunidade"
            description="Avaliação baseada somente no perfil confirmado e nas informações desta vaga. Não é recomendação de candidatura."
        >
            <div class="space-y-4">
                <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-gray-600 dark:text-gray-300">
                    <span>Critérios: matching-v1</span>
                    <span>Versão da vaga: {{ $expectedOpportunityVersion ?? '—' }}</span>
                    <span>Versão do perfil confirmado: {{ $profileConfirmed ? $expectedProfileConfirmedVersion : 'Sem perfil confirmado' }}</span>
                </div>
                @if ($this->record->readiness !== 'ready')
                    <p class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-400/10 dark:text-warning-200" role="status">
                        Oportunidade ainda não está pronta. Matching não foi solicitado.
                    </p>
                @elseif (! $profileConfirmed)
                    <p class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-400/10 dark:text-warning-200" role="status">
                        Confirme seu perfil para avaliar compatibilidade. Rascunhos não entram na avaliação.
                    </p>
                @endif

                <div class="flex flex-wrap items-center gap-3">
                    <x-filament::button
                        wire:click="requestMatching"
                        icon="heroicon-o-sparkles"
                        :disabled="! $canRequest || in_array($operationState['status'] ?? null, ['queued', 'running', 'completed', 'failed'], true)"
                    >Solicitar avaliação</x-filament::button>
                    <x-filament::button color="gray" wire:click="refreshMatching" icon="heroicon-o-arrow-path">Atualizar estado</x-filament::button>
                    <x-filament::link :href="\App\Filament\Resources\JobPostings\Pages\ViewJobPosting::getUrl(['record' => $this->record])">
                        Voltar à oportunidade
                    </x-filament::link>
                </div>
            </div>
        </x-filament::section>

        @if ($operationState)
            <x-filament::section heading="Operação de avaliação" icon="heroicon-o-clock">
                <div class="flex flex-wrap items-center gap-3">
                    <x-filament::badge :color="match ($operationState['status']) { 'completed' => 'success', 'failed' => 'danger', 'running' => 'info', default => 'gray' }">
                        {{ match ($operationState['status']) { 'queued' => 'Na fila', 'running' => 'Em andamento', 'completed' => 'Concluída', 'failed' => 'Falhou', default => $operationState['status'] } }}
                    </x-filament::badge>
                    <span class="text-sm text-gray-600 dark:text-gray-300">Operação #{{ $operationState['id'] }}</span>
                    @if ($operationState['failure_code'])
                        <span class="text-sm text-danger-600 dark:text-danger-400">Falha: {{ $operationState['failure_code'] }}</span>
                    @endif
                    @if ($operationState['status'] === 'failed' && $this->record->readiness === 'ready' && $profileConfirmed && $this->canRetryMatching())
                        <x-filament::button color="gray" wire:click="retryMatching">Tentar novamente</x-filament::button>
                    @endif
                </div>
            </x-filament::section>
        @endif

        @if ($activeEvaluation)
            @php
                $result = $activeEvaluation['result'] ?? [];
                $anchors = collect($activeEvaluation['anchors'] ?? [])->keyBy('id');
                $isStale = $activeEvaluation['stale'] ?? true;
            @endphp
            <x-filament::section
                heading="{{ $isStale ? 'Avaliação anterior — fontes alteradas' : 'Avaliação atual' }}"
                icon="heroicon-o-clipboard-document-check"
            >
                <div class="space-y-5">
                    @if ($isStale)
                        <p class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-400/10 dark:text-warning-200" role="status">
                            Perfil confirmado, vaga ou fontes mudaram desde esta avaliação. Resultado permanece no histórico e não representa estado atual.
                        </p>
                    @endif
                    <div class="flex flex-wrap gap-2 text-sm">
                        <span class="rounded-full bg-success-50 px-3 py-1 text-success-700 dark:bg-success-400/10 dark:text-success-300">Atendidos: {{ $result['summary']['met'] ?? 0 }}</span>
                        <span class="rounded-full bg-gray-100 px-3 py-1 text-gray-700 dark:bg-white/10 dark:text-gray-300">Sem evidência: {{ $result['summary']['unsupported'] ?? 0 }}</span>
                        <span class="rounded-full bg-danger-50 px-3 py-1 text-danger-700 dark:bg-danger-400/10 dark:text-danger-300">Incompatíveis: {{ $result['summary']['incompatible'] ?? 0 }}</span>
                        <span class="rounded-full bg-warning-50 px-3 py-1 text-warning-700 dark:bg-warning-400/10 dark:text-warning-300">Incertos: {{ $result['summary']['uncertain'] ?? 0 }}</span>
                        <span class="text-gray-500">Contagens, não nota.</span>
                    </div>
                    <p class="text-xs text-gray-500">Perfil confirmado v{{ $result['basis']['confirmed_version'] ?? '—' }} · oportunidade v{{ $result['basis']['opportunity_version'] ?? '—' }} · critérios {{ $result['criteria_version'] ?? 'matching-v1' }}</p>

                    @if ($activeEvaluation['provenance_warnings'] ?? [])
                        <div class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-400/10 dark:text-warning-200">
                            <strong>Parte da proveniência não está disponível.</strong>
                            Algumas fontes de publicação não puderam ser verificadas. Nenhuma evidência foi inventada.
                        </div>
                    @endif

                    <div class="space-y-3">
                        @foreach (($result['criteria'] ?? []) as $criterion)
                            <article class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <h3 class="font-semibold">{{ $this->criterionLabel($criterion['kind'] ?? null) }}{{ str_starts_with($criterion['id'] ?? '', 'requirement:') || str_starts_with($criterion['id'] ?? '', 'technology:') ? ' '.(int) substr($criterion['id'], strpos($criterion['id'], ':') + 1) + 1 : '' }}</h3>
                                    <x-filament::badge :color="match ($criterion['status'] ?? '') { 'met' => 'success', 'incompatible' => 'danger', 'uncertain' => 'warning', default => 'gray' }">
                                        {{ $this->matchingStatusLabel($criterion['status'] ?? null) }}
                                    </x-filament::badge>
                                </div>
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $this->reasonLabel($criterion['reason_code'] ?? null) }}</p>
                                @if (filled($criterion['explanation'] ?? null))
                                    <p class="mt-2 whitespace-pre-wrap text-sm">{{ $criterion['explanation'] }}</p>
                                @endif
                                @if ($criterion['missing_paths'] ?? [])
                                    <p class="mt-2 text-sm text-gray-500">Informações ausentes: {{ implode(', ', $criterion['missing_paths']) }}</p>
                                @endif
                                @if ($criterion['evidence'] ?? [])
                                    <ul class="mt-3 space-y-2">
                                        @foreach ($criterion['evidence'] as $evidence)
                                            @php $anchor = $anchors->get($evidence['anchor_id']); @endphp
                                            <li class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">
                                                <blockquote class="whitespace-pre-wrap">“{{ $evidence['quote'] }}”</blockquote>
                                                @if ($anchor)
                                                    <p class="mt-1 text-xs text-gray-500">{{ $this->sourceLabel($anchor['source'] ?? null) }} · v{{ $anchor['version'] ?? '—' }} · {{ $anchor['path'] ?? 'campo sem caminho' }}</p>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </div>
            </x-filament::section>
        @endif

        <x-filament::section heading="Histórico de avaliações" icon="heroicon-o-archive-box">
            @if ($history)
                <div class="space-y-4">
                    @foreach ($history as $evaluation)
                        @php
                            $historicalResult = $evaluation['result'] ?? [];
                            $historicalAnchors = collect($evaluation['anchors'] ?? [])->keyBy('id');
                        @endphp
                        <details class="rounded-xl border border-gray-200 p-4 dark:border-white/10" @if ($loop->first && ! $activeEvaluation) open @endif>
                            <summary class="flex cursor-pointer flex-wrap items-center gap-3 font-medium">
                                <span>{{ $evaluation['stale'] ? 'Anterior — fontes alteradas' : 'Avaliação atual' }}</span>
                                <span class="text-xs text-gray-500">{{ $evaluation['created_at'] }}</span>
                                <span class="text-xs text-gray-500">Perfil v{{ $historicalResult['basis']['confirmed_version'] ?? '—' }} · vaga v{{ $historicalResult['basis']['opportunity_version'] ?? '—' }}</span>
                            </summary>
                            @if ($evaluation['provenance_warnings'] ?? [])
                                <p class="mt-3 text-sm text-warning-700 dark:text-warning-300">Proveniência incompleta: fontes de publicação indisponíveis; não foram inventadas evidências.</p>
                            @endif
                            <div class="mt-3 space-y-2">
                                @foreach (($historicalResult['criteria'] ?? []) as $criterion)
                                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <strong>{{ $this->criterionLabel($criterion['kind'] ?? null) }}</strong>
                                            <x-filament::badge :color="match ($criterion['status'] ?? '') { 'met' => 'success', 'incompatible' => 'danger', 'uncertain' => 'warning', default => 'gray' }">{{ $this->matchingStatusLabel($criterion['status'] ?? null) }}</x-filament::badge>
                                        </div>
                                        <p class="mt-1 text-sm">{{ $this->reasonLabel($criterion['reason_code'] ?? null) }}</p>
                                        @if (filled($criterion['explanation'] ?? null))<p class="mt-1 whitespace-pre-wrap text-sm">{{ $criterion['explanation'] }}</p>@endif
                                        @if ($criterion['missing_paths'] ?? [])<p class="mt-1 text-xs text-gray-500">Ausentes: {{ implode(', ', $criterion['missing_paths']) }}</p>@endif
                                        @foreach (($criterion['evidence'] ?? []) as $evidence)
                                            @php $anchor = $historicalAnchors->get($evidence['anchor_id']); @endphp
                                            <p class="mt-2 whitespace-pre-wrap text-sm">“{{ $evidence['quote'] }}” @if ($anchor)<span class="text-xs text-gray-500">— {{ $this->sourceLabel($anchor['source'] ?? null) }}, v{{ $anchor['version'] ?? '—' }}, {{ $anchor['path'] ?? 'sem caminho' }}</span>@endif</p>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endforeach
                    @if ($nextHistoryOffset !== null)
                        <x-filament::button color="gray" wire:click="showMoreHistory">Carregar avaliações anteriores</x-filament::button>
                    @endif
                </div>
            @else
                <p class="text-sm text-gray-600 dark:text-gray-300">Ainda não há avaliações. Solicite uma quando perfil e oportunidade estiverem prontos.</p>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
