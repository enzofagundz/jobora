<?php

namespace App\Filament\Resources\AgentIntegrations\Schemas;

use App\Enums\AgentIntegrationScope;
use App\Models\AgentIntegration;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AgentIntegrationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextEntry::make('name')
                    ->label('Integração'),
                TextEntry::make('status')
                    ->label('Situação')
                    ->badge()
                    ->state(fn (AgentIntegration $record): string => $record->isRevoked() ? 'Revogada' : 'Ativa')
                    ->color(fn (string $state): string => $state === 'Revogada' ? 'danger' : 'success'),
                TextEntry::make('created_at')
                    ->label('Conectada em')
                    ->dateTime('d/m/Y H:i'),
                TextEntry::make('revoked_on')
                    ->label('Revogada em')
                    ->state(fn (AgentIntegration $record) => $record->revoked_at)
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
                Section::make('Capacidades')
                    ->schema([
                        TextEntry::make('scopes')
                            ->hiddenLabel()
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => AgentIntegrationScope::tryFrom($state)?->label() ?? $state)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
