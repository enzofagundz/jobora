<x-filament-panels::page>
    <form wire:submit="generate" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-filament::button type="submit" icon="heroicon-o-magnifying-glass">
                Gerar busca
            </x-filament::button>
        </div>
    </form>

    @if($this->preview !== null && $this->generatedUrl !== null)
        <x-filament::section>
            <x-slot name="heading">
                Sua busca
            </x-slot>

            <div class="space-y-3 text-sm">
                <p>
                    <strong>{{ $this->preview['platform_label'] }}</strong> —
                    {{ $this->preview['operator_label'] }}:
                    {{ implode(', ', $this->preview['keywords']) }}@if(count($this->preview['exclusions']) > 0)
 — Excluir: {{ implode(', ', $this->preview['exclusions']) }}@endif
                </p>
                <p>
                    @if($this->preview['seniority_label'] !== null)
                        Senioridade: {{ $this->preview['seniority_label'] }} ·
                    @endif
                    Modalidade: {{ $this->preview['work_mode_label'] }}
                    @if($this->preview['location'] !== null)
                        · Localização: {{ $this->preview['location'] }}
                    @endif
                    @if($this->preview['published_within_label'] !== null)
                        · Publicada nos últimos: {{ $this->preview['published_within_label'] }}
                    @endif
                </p>
            </div>

            <x-slot name="footer">
                <div class="flex flex-wrap items-center gap-3">
                    <a href="{{ $this->generatedUrl }}"
                       target="_blank"
                       rel="noopener noreferrer"
                       class="fi-btn fi-btn-size-md fi-btn-color-primary">
                        Abrir no {{ $this->preview['platform_label'] }}
                    </a>
                </div>
            </x-slot>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                URL gerada (avançado)
            </x-slot>

            <code class="block break-all rounded-lg bg-gray-100 p-3 text-xs dark:bg-gray-900">{{ $this->generatedUrl }}</code>
        </x-filament::section>
    @endif
</x-filament-panels::page>
