<?php

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
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
                ToggleColumn::make('is_admin')
                    ->label('Admin')
                    ->disabled(fn (User $record): bool => $record->is(auth()->user())),
                ToggleColumn::make('can_use_ats_optimization')
                    ->label('Acesso ATS'),
            ])
            ->emptyStateHeading('Nenhum usuário encontrado');
    }
}
