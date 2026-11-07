<?php

namespace App\Filament\Pages;

use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Models\AiOperation;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AiOperationsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.ai-operations-page';

    protected static ?string $slug = 'ai-operations';

    protected static ?string $navigationLabel = 'Operações de IA';

    protected static ?string $title = 'Operações de IA';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = 'Vagas';

    protected static ?int $navigationSort = 6;

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()?->disabled_at === null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => AiOperation::query()->where('user_id', auth()->id())->latest('queued_at'))
            ->columns([
                TextColumn::make('operation_type')->label('Operação')->badge()
                    ->formatStateUsing(fn (AiOperationType $state): string => self::operationTypeLabel($state)),
                TextColumn::make('status')->label('Estado')->badge()
                    ->color(fn (AiOperationStatus $state): string => match ($state) {
                        AiOperationStatus::Queued => 'gray',
                        AiOperationStatus::Running => 'info',
                        AiOperationStatus::Completed => 'success',
                        AiOperationStatus::Failed => 'danger',
                    })
                    ->formatStateUsing(fn (AiOperationStatus $state): string => match ($state) {
                        AiOperationStatus::Queued => 'Na fila',
                        AiOperationStatus::Running => 'Em andamento',
                        AiOperationStatus::Completed => 'Concluída',
                        AiOperationStatus::Failed => 'Falhou',
                    }),
                TextColumn::make('target_type')->label('Alvo')->formatStateUsing(fn (string $state): string => match ($state) {
                    'job_posting' => 'Vaga', 'page_capture' => 'Captura', 'publication' => 'Publicação', 'user' => 'Perfil', default => $state,
                }),
                TextColumn::make('target_id')->label('ID do alvo'),
                TextColumn::make('attempts')->label('Tentativas')->numeric(),
                TextColumn::make('queued_at')->label('Na fila desde')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('last_attempt_started_at')->label('Última tentativa')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextColumn::make('completed_at')->label('Concluída em')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextColumn::make('failure_code')->label('Código do erro')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options([
                    'queued' => 'Na fila', 'running' => 'Em andamento', 'completed' => 'Concluída', 'failed' => 'Falhou',
                ]),
                SelectFilter::make('operation_type')->label('Tipo')->options(collect(AiOperationType::cases())
                    ->mapWithKeys(fn (AiOperationType $type): array => [$type->value => self::operationTypeLabel($type)])
                    ->all()),
            ])
            ->emptyStateHeading('Nenhuma operação registrada')
            ->emptyStateDescription('Operações de processamento aparecerão aqui, sem expor conteúdo privado enviado aos provedores.');
    }

    private static function operationTypeLabel(AiOperationType $type): string
    {
        return match ($type) {
            AiOperationType::ResumeAdaptation => 'Adequação de currículo',
            AiOperationType::ResumeTranslation => 'Tradução de currículo',
            AiOperationType::StrategicCompetencyClassification => 'Classificação estratégica',
            AiOperationType::AtsOptimization => 'Otimização ATS',
            AiOperationType::AtsScore => 'Simulação ATS',
            AiOperationType::PublicationExtraction => 'Extração de publicação',
            AiOperationType::PublicationTriage => 'Triagem de publicação',
            AiOperationType::ProfileImport => 'Importação de perfil',
            AiOperationType::Matching => 'Compatibilidade',
        };
    }
}
