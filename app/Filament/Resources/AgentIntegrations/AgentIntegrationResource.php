<?php

namespace App\Filament\Resources\AgentIntegrations;

use App\Filament\Resources\AgentIntegrations\Pages\ListAgentIntegrations;
use App\Filament\Resources\AgentIntegrations\Schemas\AgentIntegrationInfolist;
use App\Filament\Resources\AgentIntegrations\Tables\AgentIntegrationsTable;
use App\Models\AgentIntegration;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AgentIntegrationResource extends Resource
{
    protected static ?string $model = AgentIntegration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?string $navigationLabel = 'Integrações';

    protected static string|UnitEnum|null $navigationGroup = 'Perfil';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'integração';

    protected static ?string $pluralModelLabel = 'integrações';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereBelongsTo(auth()->user());
    }

    public static function infolist(Schema $schema): Schema
    {
        return AgentIntegrationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AgentIntegrationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentIntegrations::route('/'),
        ];
    }
}
