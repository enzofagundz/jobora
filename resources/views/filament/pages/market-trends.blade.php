<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            Evolução mensal das frequências
        </x-slot>

        <div class="mb-4 flex flex-wrap items-end gap-4">
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="category" label="Categoria">
                    <option value="">Todas as categorias</option>
                    @foreach ($this->categories() as $value => $label)
                        <option value="{{ $value }}" @selected($this->category === $value)>{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>

        @if (! $this->hasEnoughData())
            <p class="text-sm text-warning-600 dark:text-warning-400">
                Dados insuficientes para análise temporal: são necessárias vagas confirmadas em pelo menos 2 meses diferentes.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-gray-500 dark:text-gray-400">
                            <th class="py-2 pr-4 font-medium">Item</th>
                            @foreach ($this->months() as $month)
                                <th class="py-2 pr-4 text-right font-medium tabular-nums">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->translatedFormat('M/y') }}</th>
                            @endforeach
                            <th class="py-2 text-right font-medium">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->monthlyRows() as $row)
                            <tr class="border-b dark:border-white/5">
                                <td class="py-2 pr-4 font-medium text-gray-900 dark:text-gray-100">{{ $row['name'] }}</td>
                                @foreach ($row['months'] as $count)
                                    <td class="py-2 pr-4 text-right tabular-nums {{ $count > 0 ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400 dark:text-gray-600' }}">{{ $count > 0 ? $count : '·' }}</td>
                                @endforeach
                                <td class="py-2 text-right font-medium tabular-nums">{{ $row['total'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-filament::section class="mt-6">
                <x-slot name="heading">
                    Comparação de períodos ({{ $this->comparisonLabels()['previous'] }} × {{ $this->comparisonLabels()['current'] }})
                </x-slot>

                @if ($this->comparison() === [])
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Nenhum item nos últimos 3 meses completos neste escopo.
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-gray-500 dark:text-gray-400">
                                    <th class="py-2 pr-4 font-medium">Item</th>
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
                                                $trendBadge = match ($row['trend']) {
                                                    'subiu' => 'bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-300',
                                                    'desceu' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/20 dark:text-danger-300',
                                                    default => 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300',
                                                };
                                            @endphp
                                            <span class="rounded px-2 py-0.5 text-xs font-medium {{ $trendBadge }}">{{ $row['trend'] }}</span>
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
