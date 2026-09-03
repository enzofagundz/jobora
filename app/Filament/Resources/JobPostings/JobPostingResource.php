<?php

namespace App\Filament\Resources\JobPostings;

use App\Enums\JobPostingStatus;
use App\Filament\Resources\JobPostings\Pages\ApplicationPage;
use App\Filament\Resources\JobPostings\Pages\CreateJobPosting;
use App\Filament\Resources\JobPostings\Pages\ListJobPostings;
use App\Filament\Resources\JobPostings\Pages\ViewJobPosting;
use App\Filament\Resources\JobPostings\Schemas\JobPostingForm;
use App\Filament\Resources\JobPostings\Tables\JobPostingsTable;
use App\Models\JobPosting;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

class JobPostingResource extends Resource
{
    protected static ?string $model = JobPosting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $navigationLabel = 'Vagas';

    protected static string|UnitEnum|null $navigationGroup = 'Vagas';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'vaga';

    protected static ?string $pluralModelLabel = 'vagas';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereBelongsTo(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return JobPostingForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('description')
                    ->label('Descrição da vaga')
                    ->html()
                    ->columnSpanFull(),
                TextEntry::make('source_url')
                    ->label('Link de referência (opcional)')
                    ->url(fn (?string $state): ?string => filled($state) ? Str::sanitizeUrl($state) : null)
                    ->openUrlInNewTab()
                    ->placeholder('—')
                    ->columnSpanFull(),
                Section::make('Diagnóstico da análise')
                    ->visible(fn (JobPosting $record): bool => $record->status === JobPostingStatus::Failed)
                    ->schema([
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge(),
                        TextEntry::make('error_message')
                            ->label('O que aconteceu')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('attempts_count')
                            ->label('Tentativas')
                            ->state(fn (JobPosting $record): int => $record->attempts()->count()),
                        TextEntry::make('last_attempt')
                            ->label('Última tentativa')
                            ->state(fn (JobPosting $record): ?string => $record->lastAttemptAt()?->format('d/m/Y H:i')),
                    ]),
                Section::make('Detalhes técnicos')
                    ->collapsible()
                    ->visible(fn (JobPosting $record): bool => $record->latestAttempt() !== null)
                    ->schema([
                        TextEntry::make('operation')
                            ->label('Operação')
                            ->state(fn (JobPosting $record): ?string => $record->latestAttempt()?->operation),
                        TextEntry::make('provider')
                            ->label('Provedor')
                            ->state(fn (JobPosting $record): ?string => $record->latestAttempt()?->provider),
                        TextEntry::make('model')
                            ->label('Modelo')
                            ->state(fn (JobPosting $record): ?string => $record->latestAttempt()?->model),
                        TextEntry::make('error_class')
                            ->label('Classe do erro')
                            ->state(fn (JobPosting $record): ?string => $record->latestAttempt()?->error_class),
                        TextEntry::make('technical_error')
                            ->label('Erro técnico')
                            ->state(fn (JobPosting $record): ?string => $record->latestAttempt()?->error_message)
                            ->columnSpanFull(),
                        TextEntry::make('latency')
                            ->label('Latência (ms)')
                            ->state(fn (JobPosting $record): ?int => $record->latestAttempt()?->latency_ms),
                        TextEntry::make('attempt_id')
                            ->label('Attempt ID')
                            ->state(fn (JobPosting $record): ?int => $record->latestAttempt()?->id),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return JobPostingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJobPostings::route('/'),
            'create' => CreateJobPosting::route('/create'),
            'view' => ViewJobPosting::route('/{record}'),
            'application' => ApplicationPage::route('/{record}/application'),
        ];
    }
}
