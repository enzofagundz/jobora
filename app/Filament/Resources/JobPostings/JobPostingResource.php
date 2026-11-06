<?php

namespace App\Filament\Resources\JobPostings;

use App\Enums\JobPostingStatus;
use App\Filament\Resources\JobPostings\Pages\ApplicationPage;
use App\Filament\Resources\JobPostings\Pages\CreateJobPosting;
use App\Filament\Resources\JobPostings\Pages\ListJobPostings;
use App\Filament\Resources\JobPostings\Pages\MatchingPage;
use App\Filament\Resources\JobPostings\Pages\ViewJobPosting;
use App\Filament\Resources\JobPostings\Schemas\JobPostingForm;
use App\Filament\Resources\JobPostings\Schemas\OpportunityReviewForm;
use App\Filament\Resources\JobPostings\Tables\JobPostingsTable;
use App\Models\JobIaAttempt;
use App\Models\JobPosting;
use App\Models\OpportunityReviewRevision;
use App\Models\PublicationClefResult;
use App\Models\PublicationTriageResult;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
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
        /** @var array<string, JobIaAttempt|null> $latestAttemptsByRecord */
        $latestAttemptsByRecord = [];
        $latestAttempt = static function (JobPosting $record) use (&$latestAttemptsByRecord): ?JobIaAttempt {
            $recordKey = (string) $record->getKey();

            if (! array_key_exists($recordKey, $latestAttemptsByRecord)) {
                $latestAttemptsByRecord[$recordKey] = $record->latestAttempt();
            }

            return $latestAttemptsByRecord[$recordKey];
        };

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
                Section::make('Oportunidade estruturada')
                    ->visible(fn (JobPosting $record): bool => $record->isStructuredOpportunity())
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('readiness')
                            ->label('Estado')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => self::readinessLabel($state))
                            ->color(fn (?string $state): string => self::readinessColor($state)),
                        TextEntry::make('opportunity_version')
                            ->label('Versão'),
                        TextEntry::make('review_reasons')
                            ->label('Pendências')
                            ->state(fn (JobPosting $record): array => array_map(self::reviewReasonLabel(...), $record->review_reasons ?? []))
                            ->listWithLineBreaks()
                            ->placeholder('Nenhuma')
                            ->columnSpanFull(),
                        TextEntry::make('publication_url')
                            ->label('Publicação de origem')
                            ->state(fn (JobPosting $record): ?string => $record->sources->first()?->publication?->url)
                            ->url(fn (?string $state): ?string => filled($state) ? Str::sanitizeUrl($state) : null)
                            ->openUrlInNewTab()
                            ->placeholder('Sem publicação associada')
                            ->columnSpanFull(),
                        TextEntry::make('publication_content_version')
                            ->label('Versão da publicação')
                            ->state(fn (JobPosting $record): ?int => $record->sources->first()?->publication_content_version)
                            ->placeholder('—'),
                        TextEntry::make('clef_status')
                            ->label('Decisão Clef')
                            ->state(fn (JobPosting $record): ?string => self::clefLabel(self::latestClef($record)?->status))
                            ->placeholder('Não classificada'),
                        TextEntry::make('triage_status')
                            ->label('Última triagem')
                            ->state(fn (JobPosting $record): ?string => self::triageLabel(self::latestTriage($record)?->status))
                            ->placeholder('Não solicitada'),
                        TextEntry::make('triage_bypass')
                            ->label('Bypass do Clef')
                            ->state(function (JobPosting $record): ?string {
                                $triage = self::latestTriage($record);

                                return $triage?->clef_bypassed
                                    ? 'Sim — '.($triage->clef_bypass_reason ?: 'motivo não registrado')
                                    : null;
                            })
                            ->placeholder('Não')
                            ->columnSpanFull(),
                        TextEntry::make('evidence')
                            ->label('Evidências')
                            ->state(fn (JobPosting $record): array => collect($record->evidence ?? [])
                                ->filter(fn (mixed $item): bool => is_array($item))
                                ->map(function (array $item): string {
                                    $blocks = array_values(array_filter((array) ($item['block_ids'] ?? []), 'is_string'));
                                    $fields = array_map(
                                        OpportunityReviewForm::evidenceFieldLabel(...),
                                        array_values(array_filter((array) ($item['fields'] ?? []), 'is_string')),
                                    );

                                    return '“'.($item['quote'] ?? '').'” — blocos: '.(implode(', ', $blocks) ?: '—').' — campos: '.(implode(', ', $fields) ?: '—');
                                })
                                ->values()
                                ->all())
                            ->listWithLineBreaks()
                            ->placeholder('Nenhuma evidência registrada.')
                            ->columnSpanFull(),
                        RepeatableEntry::make('reviewRevisions')
                            ->label('Histórico de correções')
                            ->schema([
                                TextEntry::make('version_range')
                                    ->label('Versão')
                                    ->state(fn (OpportunityReviewRevision $record): string => $record->from_version.' → '.$record->to_version),
                                TextEntry::make('actor.name')
                                    ->label('Autor')
                                    ->placeholder('—'),
                                TextEntry::make('changes')
                                    ->label('Campos corrigidos')
                                    ->state(fn (OpportunityReviewRevision $record): string => collect($record->changes ?? [])
                                        ->map(fn (mixed $value, string $field): string => match (true) {
                                            $field === 'evidence' && is_array($value) => count($value).' evidência(s)',
                                            is_array($value) => count($value).' item(ns)',
                                            filled($value) => $field.': '.$value,
                                            default => $field.': —',
                                        })
                                        ->implode(' · ')),
                                TextEntry::make('created_at')
                                    ->label('Quando')
                                    ->dateTime('d/m/Y H:i'),
                            ])
                            ->columns(4)
                            ->placeholder('Nenhuma correção registrada.')
                            ->columnSpanFull(),
                    ]),
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
                            ->state(fn (JobPosting $record): ?string => $latestAttempt($record)?->created_at?->format('d/m/Y H:i')),
                    ]),
                Section::make('Detalhes técnicos')
                    ->collapsible()
                    ->visible(fn (JobPosting $record): bool => $latestAttempt($record) !== null)
                    ->schema([
                        TextEntry::make('operation')
                            ->label('Operação')
                            ->state(fn (JobPosting $record): ?string => $latestAttempt($record)?->operation),
                        TextEntry::make('provider')
                            ->label('Provedor')
                            ->state(fn (JobPosting $record): ?string => $latestAttempt($record)?->provider),
                        TextEntry::make('model')
                            ->label('Modelo')
                            ->state(fn (JobPosting $record): ?string => $latestAttempt($record)?->model),
                        TextEntry::make('error_class')
                            ->label('Classe do erro')
                            ->state(fn (JobPosting $record): ?string => $latestAttempt($record)?->error_class),
                        TextEntry::make('technical_error')
                            ->label('Erro técnico')
                            ->state(fn (JobPosting $record): ?string => $latestAttempt($record)?->error_message)
                            ->columnSpanFull(),
                        TextEntry::make('latency')
                            ->label('Latência (ms)')
                            ->state(fn (JobPosting $record): ?int => $latestAttempt($record)?->latency_ms),
                        TextEntry::make('attempt_id')
                            ->label('Attempt ID')
                            ->state(fn (JobPosting $record): ?int => $latestAttempt($record)?->id),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return JobPostingsTable::configure($table);
    }

    public static function readinessLabel(?string $readiness): string
    {
        return match ($readiness) {
            'ready' => 'Pronta',
            'needs_review' => 'Aguardando revisão',
            default => filled($readiness) ? $readiness : '—',
        };
    }

    public static function readinessColor(?string $readiness): string
    {
        return match ($readiness) {
            'ready' => 'success',
            'needs_review' => 'warning',
            default => 'gray',
        };
    }

    public static function reviewReasonLabel(string $reason): string
    {
        return match ($reason) {
            'missing_role' => 'Cargo ausente',
            'missing_publication_url' => 'Publicação sem link',
            'invalid_publication_url' => 'Link da publicação inválido',
            'missing_evidence' => 'Evidências ausentes',
            'missing_evidence_block' => 'Trecho de evidência ausente',
            'evidence_not_in_publication' => 'Evidência não encontrada na publicação',
            'unknown_evidence_block' => 'Trecho de evidência desconhecido',
            'salary_range_inconsistent' => 'Faixa salarial inconsistente',
            'application_url_inconsistent' => 'Link de candidatura inconsistente',
            'unsupported_field_evidence' => 'Campo sem evidência literal na publicação',
            'publication_content_unavailable' => 'Conteúdo da publicação indisponível; nova visita necessária',
            'missing_publication_source' => 'Publicação de origem ausente',
            default => $reason,
        };
    }

    public static function clefLabel(?string $status): ?string
    {
        return match ($status) {
            null => null,
            'eligible' => 'Elegível',
            'barred' => 'Barrada',
            'inconclusive' => 'Inconclusiva',
            'pending' => 'Pendente',
            'running' => 'Em andamento',
            default => $status,
        };
    }

    public static function triageLabel(?string $status): ?string
    {
        return match ($status) {
            null => null,
            'completed' => 'Concluída',
            'failed' => 'Falhou',
            'running' => 'Em andamento',
            'pending' => 'Pendente',
            default => $status,
        };
    }

    private static function latestClef(JobPosting $record): ?PublicationClefResult
    {
        $source = $record->sources->first();

        return $source?->publication?->clefResults()
            ->where('content_version', $source->publication_content_version)
            ->latest('attempt')
            ->first();
    }

    private static function latestTriage(JobPosting $record): ?PublicationTriageResult
    {
        $source = $record->sources->first();

        return $source?->publication?->triageResults()
            ->where('content_version', $source->publication_content_version)
            ->latest('id')
            ->first();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJobPostings::route('/'),
            'create' => CreateJobPosting::route('/create'),
            'view' => ViewJobPosting::route('/{record}'),
            'application' => ApplicationPage::route('/{record}/application'),
            'matching' => MatchingPage::route('/{record}/matching'),
        ];
    }
}
