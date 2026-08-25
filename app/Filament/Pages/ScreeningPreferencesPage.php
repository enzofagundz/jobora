<?php

namespace App\Filament\Pages;

use App\Models\ScreeningPreference;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class ScreeningPreferencesPage extends Page
{
    protected string $view = 'filament.pages.screening-preferences';

    protected static ?string $slug = 'screening-preferences';

    protected static ?string $navigationLabel = 'Critérios de triagem';

    protected static ?string $title = 'Critérios de triagem';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedFunnel;

    protected static string|UnitEnum|null $navigationGroup = 'Perfil';

    protected static ?int $navigationSort = 6;

    public ?array $data = [];

    public function mount(): void
    {
        $model = ScreeningPreference::firstOrNew(['user_id' => auth()->id()]);

        $this->form->fill([
            'preferences' => collect($model->preferences ?? [])->map(fn (string $value): array => ['value' => $value])->all(),
            'rejections' => collect($model->rejections ?? [])->map(fn (string $value): array => ['value' => $value])->all(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Repeater::make('preferences')
                    ->label('Preferências')
                    ->schema([
                        TextInput::make('value')
                            ->label('Preferência')
                            ->required()
                            ->placeholder('Ex.: remoto, PHP, vendas consultivas'),
                    ])
                    ->addActionLabel('Adicionar preferência')
                    ->defaultItems(0)
                    ->itemLabel(fn (array $state): ?string => $state['value'] ?? null)
                    ->helperText('Critérios que você deseja em uma oportunidade. Texto livre, sem vocabulário fixo.')
                    ->columnSpanFull(),
                Repeater::make('rejections')
                    ->label('Rejeições')
                    ->schema([
                        TextInput::make('value')
                            ->label('Rejeição')
                            ->required()
                            ->placeholder('Ex.: presencial, estágio, liderança'),
                    ])
                    ->addActionLabel('Adicionar rejeição')
                    ->defaultItems(0)
                    ->itemLabel(fn (array $state): ?string => $state['value'] ?? null)
                    ->helperText('Condições que você não deseja. Rejeições têm prioridade sobre preferências.')
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        ScreeningPreference::updateOrCreate(
            ['user_id' => auth()->id()],
            [
                'preferences' => array_values(array_column($data['preferences'] ?? [], 'value')),
                'rejections' => array_values(array_column($data['rejections'] ?? [], 'value')),
            ],
        );

        Notification::make()
            ->success()
            ->title('Critérios salvos')
            ->body('As próximas triagens usarão seus critérios.')
            ->send();
    }
}
