<?php

namespace App\Filament\Resources\AgentIntegrations\Tables;

use App\Models\AgentIntegration;
use App\Services\AgentIntegrationService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AgentIntegrationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Integração')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('capabilities')
                    ->label('Capacidades')
                    ->badge()
                    ->color('info')
                    ->state(fn (AgentIntegration $record): string => count($record->scopes ?? []).' capacidades')
                    ->visibleFrom('md'),
                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->state(fn (AgentIntegration $record): string => $record->isRevoked() ? 'Revogada' : 'Ativa')
                    ->color(fn (string $state): string => $state === 'Revogada' ? 'danger' : 'success'),
                TextColumn::make('created_at')
                    ->label('Conectada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->visibleFrom('md'),
                TextColumn::make('revoked_at')
                    ->label('Revogada em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Situação')
                    ->options([
                        'active' => 'Ativa',
                        'revoked' => 'Revogada',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'] === 'active', fn (Builder $query): Builder => $query->whereNull('revoked_at'))
                        ->when($data['value'] === 'revoked', fn (Builder $query): Builder => $query->whereNotNull('revoked_at'))),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Ver')
                    ->iconButton()
                    ->modalHeading('Integração')
                    ->modalAutofocus(false)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar'),
                Action::make('revoke')
                    ->label('Revogar')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->authorize('revoke')
                    ->visible(fn (AgentIntegration $record): bool => ! $record->isRevoked())
                    ->requiresConfirmation()
                    ->modalHeading('Revogar integração')
                    ->modalDescription('As chamadas desta integração param imediatamente e os tokens de acesso são revogados. Esta integração não pode ser reativada. Para voltar a usar, registre um novo cliente no Hermes e autorize uma nova integração.')
                    ->modalSubmitActionLabel('Revogar integração')
                    ->action(function (AgentIntegration $record): void {
                        app(AgentIntegrationService::class)->revoke($record);

                        Notification::make()
                            ->success()
                            ->title('Integração revogada')
                            ->body('A integração perdeu acesso e os tokens foram invalidados.')
                            ->send();
                    }),
            ])
            ->emptyStateHeading('Nenhuma integração conectada')
            ->emptyStateDescription('Quando um agente autorizar acesso ao Jobora, a integração aparece aqui.')
            ->defaultSort('created_at', 'desc');
    }
}
