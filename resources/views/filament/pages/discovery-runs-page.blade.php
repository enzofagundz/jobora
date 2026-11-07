<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            {{ $this->table }}
        </x-filament::section>

        @if ($run = $this->selectedRun)
            <x-filament::section heading="Detalhes da execução" icon="heroicon-o-clock">
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="font-medium text-gray-500">Fonte</dt><dd>{{ $run->source?->name ?? 'Fonte removida' }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Integração responsável</dt><dd>{{ $run->integration?->name ?? 'Indisponível' }}</dd></div>
                    <div><dt class="font-medium text-gray-500">ID da execução</dt><dd class="break-all font-mono">{{ $run->execution_id }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Captura</dt><dd>{{ $run->page_capture_id ? 'Captura #'.$run->page_capture_id : 'Nenhuma captura vinculada' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="font-medium text-gray-500">URL inicial</dt><dd class="break-all">{{ $run->initial_url }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Último acesso</dt><dd>{{ $run->last_access_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Expira</dt><dd>{{ $run->expires_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
                </dl>

                <div class="mt-6">
                    <h3 class="mb-3 text-sm font-semibold">Histórico de acesso</h3>
                    @forelse ($run->events as $event)
                        <article class="border-t border-gray-200 py-3 text-sm dark:border-white/10">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-filament::badge color="{{ $event->outcome?->value === 'success' ? 'success' : 'warning' }}">{{ match ($event->outcome?->value) { 'success' => 'Sucesso', 'failure' => 'Falha', default => 'Desconhecido' } }}</x-filament::badge>
                                <span>{{ $event->occurred_at?->format('d/m/Y H:i:s') }}</span>
                                @if ($event->http_status)<span>HTTP {{ $event->http_status }}</span>@endif
                            </div>
                            @if ($event->final_url)<p class="mt-1 break-all text-gray-600 dark:text-gray-300">{{ $event->final_url }}</p>@endif
                            @if ($event->error)<p class="mt-1 text-danger-600 dark:text-danger-400">{{ $event->error }}</p>@endif
                        </article>
                    @empty
                        <p class="text-sm text-gray-500">Nenhum evento de acesso registrado.</p>
                    @endforelse
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
