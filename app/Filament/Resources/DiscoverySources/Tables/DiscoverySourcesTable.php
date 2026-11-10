<?php

namespace App\Filament\Resources\DiscoverySources\Tables;

use App\Enums\SearchPlatform;
use App\Filament\Resources\DiscoverySources\DiscoverySourceResource;
use App\Models\DiscoverySource;
use App\Services\Discovery\DiscoverySourceConflictException;
use App\Services\Discovery\DiscoverySourceService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DiscoverySourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Fonte')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('source')
                    ->label('Plataforma')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => SearchPlatform::tryFrom($state)?->getLabel() ?? $state)
                    ->sortable()
                    ->visibleFrom('md'),
                TextColumn::make('surface')
                    ->label('Tipo de busca')
                    ->formatStateUsing(fn (string $state): string => DiscoverySourceResource::surfaceOptions()[$state] ?? $state)
                    ->sortable()
                    ->visibleFrom('md'),
                TextColumn::make('purpose')
                    ->label('Finalidade')
                    ->limit(50)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visibleFrom('md'),
                TextColumn::make('frequency_minutes')
                    ->label('Frequência')
                    ->formatStateUsing(fn (int $state): string => $state.' min')
                    ->sortable()
                    ->visibleFrom('md'),
                TextColumn::make('active')
                    ->label('Situação')
                    ->badge()
                    ->state(fn (DiscoverySource $record): string => $record->active ? 'Ativa' : 'Inativa')
                    ->color(fn (string $state): string => $state === 'Ativa' ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('next_due_at')
                    ->label('Próxima execução')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->visibleFrom('md'),
                TextColumn::make('last_success_at')
                    ->label('Último sucesso')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('url')
                    ->label('URL')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->label('Plataforma')
                    ->options(SearchPlatform::class),
                SelectFilter::make('surface')
                    ->label('Tipo de busca')
                    ->options(DiscoverySourceResource::surfaceOptions()),
                Filter::make('purpose')
                    ->label('Finalidade')
                    ->form([
                        TextInput::make('value')
                            ->label('Contém'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'] ?? null, fn (Builder $query, string $value): Builder => $query->where('purpose', 'like', '%'.$value.'%'))),
                Filter::make('frequency')
                    ->label('Frequência')
                    ->form([
                        TextInput::make('min')
                            ->label('Mínimo (minutos)')
                            ->numeric(),
                        TextInput::make('max')
                            ->label('Máximo (minutos)')
                            ->numeric(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['min'] ?? null, fn (Builder $query, int|string $min): Builder => $query->where('frequency_minutes', '>=', (int) $min))
                        ->when($data['max'] ?? null, fn (Builder $query, int|string $max): Builder => $query->where('frequency_minutes', '<=', (int) $max))),
                SelectFilter::make('state')
                    ->label('Situação')
                    ->options([
                        'active' => 'Ativa',
                        'inactive' => 'Inativa',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'] === 'active', fn (Builder $query): Builder => $query->where('active', true))
                        ->when($data['value'] === 'inactive', fn (Builder $query): Builder => $query->where('active', false))),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Editar')
                    ->iconButton(),
                Action::make('deactivate')
                    ->label('Desativar')
                    ->iconButton()
                    ->icon(Heroicon::OutlinedPauseCircle)
                    ->color('warning')
                    ->authorize('update')
                    ->visible(fn (DiscoverySource $record): bool => $record->active)
                    ->requiresConfirmation()
                    ->modalHeading('Desativar fonte')
                    ->modalDescription('A fonte deixa de ser consultada pelo agendamento. O histórico existente é preservado.')
                    ->modalSubmitActionLabel('Desativar fonte')
                    ->action(function (Action $action, DiscoverySource $record): void {
                        try {
                            app(DiscoverySourceService::class)->deactivate(auth()->user(), $record->id, $record->version);
                        } catch (DiscoverySourceConflictException) {
                            static::notifyConflict($action);

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Fonte desativada')
                            ->body('A fonte não será mais consultada. O histórico existente foi preservado.')
                            ->send();
                    }),
                Action::make('delete')
                    ->label('Remover')
                    ->iconButton()
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->authorize('delete')
                    ->requiresConfirmation()
                    ->modalHeading('Remover fonte')
                    ->modalDescription('A fonte sai do agendamento e não será mais consultada. O histórico existente é preservado.')
                    ->modalSubmitActionLabel('Remover fonte')
                    ->action(function (Action $action, DiscoverySource $record): void {
                        try {
                            app(DiscoverySourceService::class)->delete(auth()->user(), $record->id, $record->version);
                        } catch (DiscoverySourceConflictException) {
                            static::notifyConflict($action);

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Fonte removida')
                            ->body('A fonte foi removida do agendamento. O histórico existente foi preservado.')
                            ->send();
                    }),
            ])
            ->emptyStateHeading('Nenhuma fonte de descoberta')
            ->emptyStateDescription('Cadastre uma busca do LinkedIn ou Indeed para o agente consultar.')
            ->defaultSort('id', 'desc');
    }

    private static function notifyConflict(Action $action): void
    {
        Notification::make()
            ->warning()
            ->title('Fonte alterada em outra sessão')
            ->body('A fonte foi atualizada em outra janela. Recarregue a lista e tente novamente.')
            ->send();

        $action->halt();
    }
}
