<?php

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class ManageAtsUsers extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.manage-ats-users';

    protected static ?string $slug = 'manage-ats-users';

    protected static ?string $navigationLabel = 'Usuários ATS';

    protected static ?string $title = 'Gestão ATS';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Perfil';

    protected static ?int $navigationSort = 99;

    public static function canAccess(): bool
    {
        return Gate::allows('admin');
    }

    public function toggleAdmin(int $userId): void
    {
        if (! Gate::allows('admin')) {
            return;
        }

        $record = User::query()->findOrFail($userId);
        if ($record->is(auth()->user())) {
            return;
        }

        $record->update(['is_admin' => ! $record->is_admin]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(User::query()->orderBy('email'))
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('is_admin')
                    ->label('Admin')
                    ->badge()
                    ->state(fn (User $record): string => $record->is_admin ? 'Sim' : 'Não')
                    ->color(fn (string $state): string => $state === 'Sim' ? 'success' : 'gray'),
                TextColumn::make('can_use_ats_optimization')
                    ->label('Acesso ATS')
                    ->badge()
                    ->state(fn (User $record): string => $record->can_use_ats_optimization ? 'Ativo' : 'Inativo')
                    ->color(fn (string $state): string => $state === 'Ativo' ? 'success' : 'gray'),
            ])
            ->recordActions([
                Action::make('toggleAdmin')
                    ->label(fn (User $record): string => $record->is_admin ? 'Remover admin' : 'Tornar admin')
                    ->color(fn (User $record): string => $record->is_admin ? 'warning' : 'primary')
                    ->visible(fn (User $record): bool => ! $record->is(auth()->user()))
                    ->authorize(fn (): bool => Gate::allows('admin'))
                    ->requiresConfirmation()
                    ->action(fn (User $record) => $this->toggleAdmin((int) $record->getKey())),
                Action::make('toggleAtsAccess')
                    ->label(fn (User $record): string => $record->can_use_ats_optimization ? 'Remover acesso ATS' : 'Conceder acesso ATS')
                    ->authorize(fn (): bool => Gate::allows('admin'))
                    ->action(function (User $record): void {
                        Gate::authorize('admin');

                        $record->update(['can_use_ats_optimization' => ! $record->can_use_ats_optimization]);
                    }),
            ])
            ->emptyStateHeading('Nenhum usuário encontrado');
    }
}
