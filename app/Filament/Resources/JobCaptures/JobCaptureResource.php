<?php

namespace App\Filament\Resources\JobCaptures;

use App\Filament\Resources\JobCaptures\Pages\ListJobCaptures;
use App\Filament\Resources\JobCaptures\Tables\JobCapturesTable;
use App\Models\JobCapture;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

class JobCaptureResource extends Resource
{
    protected static ?string $model = JobCapture::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $navigationLabel = 'Capturas';

    protected static string|UnitEnum|null $navigationGroup = 'Vagas';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'captura';

    protected static ?string $pluralModelLabel = 'capturas';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereBelongsTo(auth()->user());
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextEntry::make('description')
                    ->label('Descrição')
                    ->columnSpanFull(),
                TextEntry::make('title')
                    ->label('Título')
                    ->placeholder('—'),
                TextEntry::make('company')
                    ->label('Empresa')
                    ->placeholder('—'),
                TextEntry::make('location')
                    ->label('Localização')
                    ->placeholder('—'),
                TextEntry::make('source')
                    ->label('Origem'),
                TextEntry::make('external_id')
                    ->label('ID externo')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('url')
                    ->label('URL')
                    ->url(fn (?string $state): ?string => filled($state) ? Str::sanitizeUrl($state) : null)
                    ->openUrlInNewTab()
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('search_url')
                    ->label('URL de busca')
                    ->url(fn (string $state): ?string => $state ? Str::sanitizeUrl($state) : null)
                    ->openUrlInNewTab()
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('author_name')
                    ->label('Autor')
                    ->placeholder('—'),
                TextEntry::make('author_profile_url')
                    ->label('Perfil do autor')
                    ->url(fn (string $state): ?string => $state ? Str::sanitizeUrl($state) : null)
                    ->openUrlInNewTab()
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('captured_at')
                    ->label('Capturada em')
                    ->dateTime('d/m/Y H:i'),
                TextEntry::make('triage_status')
                    ->label('Triagem'),
                TextEntry::make('triage_result')
                    ->label('Resultado')
                    ->placeholder('—'),
                TextEntry::make('triage_reason')
                    ->label('Justificativa')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('triage_error')
                    ->label('Erro')
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return JobCapturesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJobCaptures::route('/'),
        ];
    }
}
