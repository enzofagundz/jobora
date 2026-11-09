<?php

namespace App\Filament\Resources\DiscoverySources\Schemas;

use App\Enums\SearchPlatform;
use App\Filament\Resources\DiscoverySources\DiscoverySourceResource;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class DiscoverySourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificação')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome da fonte')
                            ->required()
                            ->maxLength(120)
                            ->columnSpanFull(),
                        Select::make('source')
                            ->label('Plataforma')
                            ->options(SearchPlatform::class)
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('surface', null)),
                        Select::make('surface')
                            ->label('Tipo de busca')
                            ->options(fn (Get $get): array => DiscoverySourceResource::surfaceOptionsFor($get('source')))
                            ->required(),
                        Textarea::make('purpose')
                            ->label('Finalidade')
                            ->maxLength(500)
                            ->rows(2)
                            ->columnSpanFull(),
                        TextInput::make('frequency_minutes')
                            ->label('Frequência')
                            ->numeric()
                            ->required()
                            ->minValue(5)
                            ->maxValue(10080)
                            ->suffix('minutos')
                            ->helperText('Intervalo entre verificações desta fonte.'),
                        Hidden::make('expected_version'),
                    ])
                    ->columnSpanFull(),
                Section::make('Busca')
                    ->columns(2)
                    ->schema([
                        ToggleButtons::make('search_mode')
                            ->label('Como definir a busca')
                            ->options([
                                'criteria' => 'Critérios',
                                'url' => 'URL pronta',
                            ])
                            ->default('criteria')
                            ->inline()
                            ->live()
                            ->columnSpanFull(),
                        TextInput::make('url')
                            ->label('URL da busca')
                            ->url()
                            ->maxLength(2000)
                            ->required(fn (Get $get): bool => $get('search_mode') === 'url')
                            ->visible(fn (Get $get): bool => $get('search_mode') === 'url')
                            ->helperText('Use a URL de busca da plataforma. A URL precisa usar HTTPS.')
                            ->columnSpanFull(),
                        TagsInput::make('criteria.keywords')
                            ->label('Palavras-chave')
                            ->required(fn (Get $get): bool => $get('search_mode') === 'criteria')
                            ->visible(fn (Get $get): bool => $get('search_mode') === 'criteria')
                            ->helperText('Até 20 termos. A plataforma recebe a busca com estas palavras.')
                            ->columnSpanFull(),
                        TagsInput::make('criteria.excluded_terms')
                            ->label('Termos excluídos')
                            ->visible(fn (Get $get): bool => $get('search_mode') === 'criteria')
                            ->helperText('Opcional. Termos que não devem aparecer no resultado.')
                            ->columnSpanFull(),
                        Select::make('criteria.operator')
                            ->label('Combinação das palavras')
                            ->options([
                                'all' => 'Todas as palavras',
                                'any' => 'Qualquer palavra',
                            ])
                            ->default('all')
                            ->visible(fn (Get $get): bool => $get('search_mode') === 'criteria'),
                        Select::make('criteria.seniority')
                            ->label('Senioridade')
                            ->options([
                                'internship' => 'Estágio',
                                'junior' => 'Júnior',
                                'mid_level' => 'Pleno',
                                'senior' => 'Sênior',
                            ])
                            ->placeholder('Qualquer')
                            ->visible(fn (Get $get): bool => $get('search_mode') === 'criteria'),
                        TextInput::make('criteria.location')
                            ->label('Localização')
                            ->maxLength(255)
                            ->placeholder('Ex.: São Paulo')
                            ->visible(fn (Get $get): bool => $get('search_mode') === 'criteria'),
                        Select::make('criteria.work_mode')
                            ->label('Modalidade')
                            ->options([
                                'any' => 'Qualquer',
                                'remote' => 'Remoto',
                                'hybrid' => 'Híbrido',
                                'onsite' => 'Presencial',
                            ])
                            ->default('any')
                            ->visible(fn (Get $get): bool => $get('search_mode') === 'criteria'),
                        Select::make('criteria.published_within_days')
                            ->label('Período de publicação')
                            ->options([
                                1 => 'Últimas 24 horas',
                                3 => 'Últimos 3 dias',
                                7 => 'Últimos 7 dias',
                                14 => 'Últimos 14 dias',
                                30 => 'Últimos 30 dias',
                            ])
                            ->placeholder('Sem filtro')
                            ->visible(fn (Get $get): bool => $get('search_mode') === 'criteria'),
                        Toggle::make('criteria.easy_apply')
                            ->label('Apenas candidatura fácil')
                            ->visible(fn (Get $get): bool => $get('search_mode') === 'criteria'),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
