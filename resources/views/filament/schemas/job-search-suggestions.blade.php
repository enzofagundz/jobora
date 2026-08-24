@php
    use Filament\Support\Icons\Heroicon;

    $livewire = $schemaComponent->getLivewire();
@endphp
<div class="flex flex-col gap-2">
    <div>
        <p class="text-sm font-medium">{{ __('Preferências') }}</p>
        <div class="mt-1 flex flex-wrap gap-2">
            @foreach ($livewire->suggestedFixedPreferences as $index => $term)
                @php $active = $livewire->isSuggestionActive('fixed_keywords', $index); @endphp
                @if ($active)
                    <x-filament::badge
                        color="success"
                        size="sm"
                        :icon="Heroicon::Check"
                        wire:key="suggestion-fixed_keywords-{{ $index }}"
                        aria-pressed="true"
                        class="max-w-full whitespace-normal break-words"
                    >
                        {{ $term }}

                        <x-slot name="deleteButton"
                            wire:click="removeSuggestion('fixed_keywords', {{ $index }})"
                            label="Remover preferência fixa {{ $term }}"
                            wire:loading.attr="disabled"
                        ></x-slot>
                    </x-filament::badge>
                @else
                    <x-filament::badge
                        color="success"
                        size="sm"
                        tag="button"
                        type="button"
                        wire:click="applySuggestion('fixed_keywords', {{ $index }})"
                        wire:key="suggestion-fixed_keywords-{{ $index }}"
                        aria-pressed="false"
                        class="max-w-full whitespace-normal break-words"
                    >
                        {{ $term }}
                    </x-filament::badge>
                @endif
            @endforeach
            @foreach ($livewire->suggestedPreferences as $index => $term)
                @php $active = $livewire->isSuggestionActive('keywords', $index); @endphp
                @if ($active)
                    <x-filament::badge
                        color="success"
                        size="sm"
                        :icon="Heroicon::Check"
                        wire:key="suggestion-keywords-{{ $index }}"
                        aria-pressed="true"
                        class="max-w-full whitespace-normal break-words"
                    >
                        {{ $term }}

                        <x-slot name="deleteButton"
                            wire:click="removeSuggestion('keywords', {{ $index }})"
                            label="Remover preferência {{ $term }}"
                            wire:loading.attr="disabled"
                        ></x-slot>
                    </x-filament::badge>
                @else
                    <x-filament::badge
                        color="success"
                        size="sm"
                        tag="button"
                        type="button"
                        wire:click="applySuggestion('keywords', {{ $index }})"
                        wire:key="suggestion-keywords-{{ $index }}"
                        aria-pressed="false"
                        class="max-w-full whitespace-normal break-words"
                    >
                        {{ $term }}
                    </x-filament::badge>
                @endif
            @endforeach
        </div>
    </div>

    <div>
        <p class="text-sm font-medium">{{ __('Rejeições') }}</p>
        <div class="mt-1 flex flex-wrap gap-2">
            @forelse ($livewire->suggestedRejections as $index => $term)
                @php $active = $livewire->isSuggestionActive('excluded_terms', $index); @endphp
                @if ($active)
                    <x-filament::badge
                        color="danger"
                        size="sm"
                        :icon="Heroicon::Check"
                        wire:key="suggestion-excluded_terms-{{ $index }}"
                        aria-pressed="true"
                        class="max-w-full whitespace-normal break-words"
                    >
                        {{ $term }}

                        <x-slot name="deleteButton"
                            wire:click="removeSuggestion('excluded_terms', {{ $index }})"
                            label="Remover rejeição {{ $term }}"
                            wire:loading.attr="disabled"
                        ></x-slot>
                    </x-filament::badge>
                @else
                    <x-filament::badge
                        color="danger"
                        size="sm"
                        tag="button"
                        type="button"
                        wire:click="applySuggestion('excluded_terms', {{ $index }})"
                        wire:key="suggestion-excluded_terms-{{ $index }}"
                        aria-pressed="false"
                        class="max-w-full whitespace-normal break-words"
                    >
                        {{ $term }}
                    </x-filament::badge>
                @endif
            @empty
                <p class="text-sm text-gray-400 dark:text-gray-500">Nenhuma rejeição salva.</p>
            @endforelse
        </div>
    </div>
</div>
