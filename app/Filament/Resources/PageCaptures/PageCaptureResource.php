<?php

namespace App\Filament\Resources\PageCaptures;

use App\Enums\AiOperationStatus;
use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Filament\Resources\PageCaptures\Pages\ManagePageCaptures;
use App\Models\AiOperation;
use App\Models\OpportunitySource;
use App\Models\PageCapture;
use App\Models\PublicationClefResult;
use App\Models\PublicationObservation;
use App\Models\PublicationTriageResult;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use UnitEnum;

class PageCaptureResource extends Resource
{
    protected static bool $shouldCheckPolicyExistence = false;

    protected static ?string $model = PageCapture::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Capturas';

    protected static string|UnitEnum|null $navigationGroup = 'Vagas';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'captura V2';

    protected static ?string $pluralModelLabel = 'capturas';

    public static function canViewAny(): bool
    {
        return auth()->check() && auth()->user()?->disabled_at === null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->check() && (int) $record->user_id === (int) auth()->id();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereBelongsTo(auth()->user())
            ->withoutGlobalScope(SoftDeletingScope::class);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Captura recebida')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('source')->label('Plataforma'),
                        TextEntry::make('surface')->label('Tipo de página')->placeholder('—'),
                        TextEntry::make('url')->label('URL capturada')
                            ->url(fn (?string $state): ?string => filled($state) ? Str::sanitizeUrl($state) : null)
                            ->openUrlInNewTab()->placeholder('—')->columnSpanFull(),
                        TextEntry::make('captured_at')->label('Capturada em')->dateTime('d/m/Y H:i'),
                        TextEntry::make('execution_id')->label('ID da execução')->placeholder('Captura manual'),
                        TextEntry::make('ingest_status')->label('Ingestão')->badge(),
                        TextEntry::make('coverage_status')->label('Cobertura')->badge(),
                        TextEntry::make('coverage_reason')->label('Observação da cobertura')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('blocks_expected')->label('Blocos esperados')->placeholder('—'),
                        TextEntry::make('blocks_captured')->label('Blocos recebidos')->placeholder('—'),
                        TextEntry::make('raw_expires_at')->label('HTML retido até')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('raw_deleted_at')->label('HTML removido em')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('deleted_at')->label('Captura removida em')->dateTime('d/m/Y H:i')->placeholder('Ativa'),
                    ]),
                Section::make('Extração')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('extraction_status')->label('Estado')
                            ->state(fn (PageCapture $record): string => match (self::extractionOperation($record)?->status) {
                                AiOperationStatus::Queued => 'Na fila',
                                AiOperationStatus::Running => 'Em andamento',
                                AiOperationStatus::Completed => 'Concluída',
                                AiOperationStatus::Failed => 'Falhou',
                                null => 'Ainda não iniciada',
                            })
                            ->placeholder('Ainda não iniciada')->badge(),
                        TextEntry::make('extraction_attempts')->label('Tentativas')
                            ->state(fn (PageCapture $record): ?int => self::extractionOperation($record)?->attempts)
                            ->placeholder('—'),
                        TextEntry::make('extraction_failure')->label('Código da falha')
                            ->state(fn (PageCapture $record): ?string => self::extractionOperation($record)?->failure_code)
                            ->placeholder('—'),
                    ]),
                RepeatableEntry::make('inspected_publications')
                    ->label('Publicações observadas e resultados')
                    ->state(fn (PageCapture $record): array => self::publicationRows($record))
                    ->schema([
                        TextEntry::make('source')->label('Plataforma'),
                        TextEntry::make('external_id')->label('ID externo')->placeholder('—'),
                        TextEntry::make('content')->label('Conteúdo observado')->columnSpanFull(),
                        TextEntry::make('url')->label('Publicação')
                            ->url(fn (?string $state): ?string => filled($state) ? Str::sanitizeUrl($state) : null)
                            ->openUrlInNewTab()->placeholder('—')->columnSpanFull(),
                        TextEntry::make('observed_at')->label('Observada em'),
                        TextEntry::make('content_version')->label('Versão de conteúdo'),
                        TextEntry::make('clef_status')->label('Clef'),
                        TextEntry::make('triage_status')->label('Triagem'),
                        TextEntry::make('opportunities')->label('Oportunidades vinculadas')->listWithLineBreaks()->placeholder('Nenhuma oportunidade vinculada.')->columnSpanFull(),
                    ])
                    ->placeholder('Nenhuma publicação identificada nesta captura.')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('source')->label('Plataforma')->badge(),
                TextColumn::make('surface')->label('Tipo de página')->placeholder('—'),
                TextColumn::make('captured_at')->label('Capturada em')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('ingest_status')->label('Ingestão')->badge(),
                TextColumn::make('coverage_status')->label('Cobertura')->badge(),
                TextColumn::make('blocks_captured')->label('Blocos')->placeholder('—'),
                TextColumn::make('raw_expires_at')->label('HTML retido até')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextColumn::make('deleted_at')->label('Registro')->state(fn (PageCapture $record): string => $record->trashed() ? 'Removida' : 'Ativa')->badge(),
            ])
            ->filters([TrashedFilter::make()])
            ->recordActions([
                ViewAction::make()->label('Inspecionar')->modalHeading('Detalhes da captura')
                    ->authorize(fn (PageCapture $record): bool => self::canView($record)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePageCaptures::route('/'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->whereBelongsTo(auth()->user())
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    private static function extractionOperation(PageCapture $capture): ?AiOperation
    {
        return AiOperation::query()->where('user_id', auth()->id())->where('target_type', 'page_capture')
            ->where('target_id', $capture->getKey())->where('operation_type', 'publication_extraction')->latest('id')->first();
    }

    /** @return list<array{source: string, external_id: ?string, content: ?string, url: ?string, observed_at: string, content_version: int, clef_status: string, triage_status: string, opportunities: list<string>}> */
    private static function publicationRows(PageCapture $capture): array
    {
        $observations = $capture->observations()
            ->whereHas('publication', fn (Builder $query): Builder => $query->where('user_id', auth()->id()))
            ->with(['publication' => function (BelongsTo $relation): void {
                $relation->where('user_id', auth()->id());
            }])
            ->get();
        $publicationIds = $observations->pluck('publication.id')->filter()->unique()->values();
        $clefResults = PublicationClefResult::query()->whereIn('publication_id', $publicationIds)->orderByDesc('id')->get()
            ->unique(fn ($result): string => $result->publication_id.':'.$result->content_version)
            ->keyBy(fn ($result): string => $result->publication_id.':'.$result->content_version);
        $triageResults = PublicationTriageResult::query()->whereIn('publication_id', $publicationIds)->orderByDesc('id')->get()
            ->unique(fn ($result): string => $result->publication_id.':'.$result->content_version)
            ->keyBy(fn ($result): string => $result->publication_id.':'.$result->content_version);
        $opportunities = OpportunitySource::query()->whereIn('publication_id', $publicationIds)
            ->whereHas('jobPosting', fn (Builder $query): Builder => $query->where('user_id', auth()->id()))
            ->with(['jobPosting' => function (BelongsTo $relation): void {
                $relation->where('user_id', auth()->id());
            }])->get()->groupBy(fn (OpportunitySource $source): string => $source->publication_id.':'.$source->publication_content_version);

        return $observations->map(function (PublicationObservation $observation) use ($clefResults, $triageResults, $opportunities): ?array {
            $publication = $observation->publication;
            if ($publication === null) {
                return null;
            }

            $key = $publication->getKey().':'.$observation->content_version;
            $clef = $clefResults->get($key);
            $triage = $triageResults->get($key);

            return [
                'source' => $publication->source,
                'external_id' => $publication->external_id,
                'content' => $observation->content,
                'url' => Str::sanitizeUrl($publication->url),
                'observed_at' => $observation->observed_at?->format('d/m/Y H:i') ?? '—',
                'content_version' => $observation->content_version,
                'clef_status' => JobPostingResource::clefLabel($clef?->status) ?? 'Não processada nesta versão',
                'triage_status' => JobPostingResource::triageLabel($triage?->status) ?? 'Não processada nesta versão',
                'opportunities' => $opportunities->get($key, collect())
                    ->map(fn (OpportunitySource $source): ?string => $source->jobPosting?->role)
                    ->filter()
                    ->values()
                    ->all(),
            ];
        })
            ->filter()
            ->values()
            ->all();
    }
}
