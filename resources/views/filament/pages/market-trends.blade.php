<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            {{ $this->headingLabel() }}
        </x-slot>

        <div class="mb-4 flex flex-wrap items-end gap-4">
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="period" label="Periodicidade">
                    @foreach ($this->periodOptions() as $value => $label)
                        <option value="{{ $value }}" @selected($this->selectedPeriod()->value === $value)>{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>

            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="category" label="Categoria">
                    <option value="">Todas as categorias</option>
                    @foreach ($this->categories() as $value => $label)
                        <option value="{{ $value }}" @selected($this->category === $value)>{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>

        @if ($this->isEmpty())
            <p class="text-sm text-warning-600 dark:text-warning-400">
                Nenhuma vaga confirmada neste escopo. Confirme vagas para começar a análise temporal.
            </p>
        @elseif (! $this->hasEnoughData())
            <p class="text-sm text-warning-600 dark:text-warning-400">
                {{ $this->insufficientMessage() }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-gray-500 dark:text-gray-400">
                            <th class="py-2 pr-4 font-medium">Item</th>
                            @if (blank($this->category))
                                <th class="py-2 pr-4 font-medium">Categoria</th>
                            @endif
                            @foreach ($this->buckets() as $bucket)
                                <th class="py-2 pr-4 text-right font-medium tabular-nums">{{ $bucket['label'] }}</th>
                            @endforeach
                            <th class="py-2 text-right font-medium">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->monthlyRows() as $row)
                            <tr class="border-b dark:border-white/5">
                                <td class="py-2 pr-4 font-medium text-gray-900 dark:text-gray-100">{{ $row['name'] }}</td>
                                @if (blank($this->category))
                                    <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ \App\Enums\ConceptCategory::tryFrom($row['category'])?->getLabel() ?? $row['category'] }}</td>
                                @endif
                                @foreach ($row['buckets'] as $count)
                                    @if ($count === null)
                                        <td class="py-2 pr-4 text-right tabular-nums text-gray-400 dark:text-gray-600">—</td>
                                    @else
                                        <td class="py-2 pr-4 text-right tabular-nums {{ $count > 0 ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400 dark:text-gray-600' }}">{{ $count > 0 ? $count : '·' }}</td>
                                    @endif
                                @endforeach
                                <td class="py-2 text-right font-medium tabular-nums">{{ $row['total'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($this->partialBucketNotice() !== null)
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    {{ $this->partialBucketNotice() }}
                </p>
            @endif

            <x-filament::section class="mt-6">
                <x-slot name="heading">
                    Comparação de períodos ({{ $this->comparisonLabels()['previous'] }} × {{ $this->comparisonLabels()['current'] }})
                </x-slot>

                @if ($this->comparison() === [])
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        @if ($this->selectedPeriod() === \App\Enums\AnalysisPeriod::Week)
                            Nenhum item nas últimas 2 semanas completas ou dados insuficientes para comparação neste escopo.
                        @elseif ($this->selectedPeriod() === \App\Enums\AnalysisPeriod::Month)
                            Nenhum item nos últimos 3 meses completos neste escopo.
                        @elseif ($this->selectedPeriod() === \App\Enums\AnalysisPeriod::Quarter)
                            Nenhum item no último trimestre completo ou dados insuficientes para comparação neste escopo.
                        @else
                            Nenhum item no último ano completo ou dados insuficientes para comparação neste escopo.
                        @endif
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-gray-500 dark:text-gray-400">
                                    <th class="py-2 pr-4 font-medium">Item</th>
                                    @if (blank($this->category))
                                        <th class="py-2 pr-4 font-medium">Categoria</th>
                                    @endif
                                    <th class="py-2 pr-4 text-right font-medium">Período anterior</th>
                                    <th class="py-2 pr-4 text-right font-medium">Período atual</th>
                                    <th class="py-2 pr-4 text-right font-medium">Δ</th>
                                    <th class="py-2 pr-4 text-right font-medium">Δ %</th>
                                    <th class="py-2 text-right font-medium">Tendência</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->comparison() as $row)
                                    <tr class="border-b dark:border-white/5">
                                        <td class="py-2 pr-4 font-medium text-gray-900 dark:text-gray-100">{{ $row['name'] }}</td>
                                        @if (blank($this->category))
                                            <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ \App\Enums\ConceptCategory::tryFrom($row['category'] ?? '')?->getLabel() ?? ($row['category'] ?? '—') }}</td>
                                        @endif
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ $row['previous'] }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ $row['current'] }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums {{ $row['delta_abs'] >= 0 ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                                            {{ $row['delta_abs'] >= 0 ? '+' : '' }}{{ $row['delta_abs'] }}
                                        </td>
                                        <td class="py-2 pr-4 text-right tabular-nums {{ $row['delta_rel'] !== null && $row['delta_rel'] >= 0 ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                                            {{ $row['delta_rel'] !== null ? ($row['delta_rel'] >= 0 ? '+' : '').$row['delta_rel'].'%' : 'nova' }}
                                        </td>
                                        <td class="py-2 text-right">
                                            @php
                                                $trendColor = match ($row['trend']) {
                                                    'subiu' => 'success',
                                                    'desceu' => 'danger',
                                                    'nova' => 'info',
                                                    default => 'gray',
                                                };
                                            @endphp
                                            <x-filament::badge :color="$trendColor">{{ $row['trend'] }}</x-filament::badge>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        @endif
    </x-filament::section>
</x-filament-panels::page>
