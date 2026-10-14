<?php

namespace App\Filament\Pages;

use App\Enums\AiOperationType;
use App\Enums\ProfessionalCompetencySource;
use App\Enums\ResumeLanguage;
use App\Enums\StrategicRole;
use App\Models\AiOperation;
use App\Services\AiOperationService;
use App\Services\ResumeService;
use App\Services\StrategicCompetencyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Throwable;
use UnitEnum;

/**
 * Classificação estratégica das competências do perfil (papel core,
 * expansion ou differentiation). A IA sugere; o usuário mantém/edita
 * manualmente. Situação (Adquirida/Lacuna) é derivada do currículo na
 * leitura, nunca gravada.
 */
class ProfessionalCompetenciesPage extends Page implements HasSchemas, HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.professional-competencies';

    protected static ?string $slug = 'professional-competencies';

    protected static ?string $navigationLabel = 'Competências estratégicas';

    protected static ?string $title = 'Competências estratégicas';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Perfil';

    protected static ?int $navigationSort = 9;

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => app(StrategicCompetencyService::class)->rows(auth()->user()))
            ->columns([
                TextColumn::make('concept')
                    ->label('Competência')
                    ->wrap()
                    ->width('38%'),
                TextColumn::make('strategic_role')
                    ->label('Papel estratégico')
                    ->badge()
                    ->color(fn (StrategicRole $state): string => match ($state) {
                        StrategicRole::Core => 'primary',
                        StrategicRole::Expansion => 'info',
                        StrategicRole::Differentiation => 'success',
                    })
                    ->width('22%'),
                TextColumn::make('source')
                    ->label('Origem')
                    ->badge()
                    ->color(fn (ProfessionalCompetencySource $state): string => match ($state) {
                        ProfessionalCompetencySource::Ai => 'gray',
                        ProfessionalCompetencySource::Manual => 'warning',
                    })
                    ->width('14%'),
                TextColumn::make('present')
                    ->label('Situação')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Adquirida' : 'Lacuna')
                    ->width('14%'),
            ])
            ->recordActions([
                Action::make('editRole')
                    ->label('Editar papel')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    ->modalHeading(fn (array $record): string => 'Editar papel de '.$record['concept'])
                    ->schema([
                        Select::make('strategic_role')
                            ->label('Papel estratégico')
                            ->options(StrategicRole::class)
                            ->required()
                            ->default(fn (array $record): string => $record['strategic_role']->value),
                    ])
                    ->action(function (array $record, array $data): void {
                        app(StrategicCompetencyService::class)->saveManual(
                            auth()->user(),
                            $record['concept'],
                            $this->roleFromState($data['strategic_role']),
                        );

                        Notification::make()
                            ->success()
                            ->title('Papel atualizado')
                            ->body('A classificação agora é manual e não será sobrescrita pela IA.')
                            ->send();
                    }),
                Action::make('remove')
                    ->label('Remover')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Remover competência')
                    ->modalDescription('A competência deixa de ter papel estratégico no seu perfil.')
                    ->action(function (array $record): void {
                        app(StrategicCompetencyService::class)->remove(auth()->user(), $record['id']);

                        Notification::make()
                            ->success()
                            ->title('Competência removida')
                            ->send();
                    }),
            ])
            ->emptyStateHeading('Nenhuma competência classificada')
            ->emptyStateDescription('Classifique com IA ou adicione manualmente para definir o papel estratégico das suas competências.');
    }

    public function activeAiOperation(): ?AiOperation
    {
        return AiOperation::query()
            ->where('user_id', auth()->id())
            ->where('operation_type', AiOperationType::StrategicCompetencyClassification)
            ->active()
            ->latest('queued_at')
            ->first();
    }

    public function refreshAiOperation(): void {}

    /**
     * Select com opções de enum devolve o estado já como StrategicRole
     * (EnumStateCast); aceita a string crua como fallback defensivo.
     */
    private function roleFromState(mixed $state): StrategicRole
    {
        return $state instanceof StrategicRole ? $state : StrategicRole::from($state);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('classifyWithAi')->label('Classificar com IA')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Classificar com IA')
                ->modalDescription('O currículo e os itens do mercado confirmado serão enviados ao modelo de linguagem. Custo de uma chamada de IA por execução. Classificações manuais não são alteradas.')
                ->disabled(fn (): bool => blank(app(ResumeService::class)->content(auth()->user(), ResumeLanguage::PtBr)) || $this->activeAiOperation() !== null)
                ->tooltip('Cadastre o currículo antes de classificar com IA.')
                ->action(function (): void {
                    try {
                        app(AiOperationService::class)->enqueueStrategicCompetencyClassification(auth()->user());

                        Notification::make()
                            ->info()
                            ->title('Classificação com IA em andamento')
                            ->body('As competências serão atualizadas quando o processamento terminar.')
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('Falha na classificação com IA')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),
            Action::make('addManual')
                ->label('Adicionar competência')
                ->icon('heroicon-o-plus')
                ->schema([
                    TextInput::make('concept')
                        ->label('Competência')
                        ->required()
                        ->maxLength(100)
                        ->placeholder('Ex.: PostgreSQL')
                        ->helperText('Aliases conhecidos são normalizados para o nome canônico.'),
                    Select::make('strategic_role')
                        ->label('Papel estratégico')
                        ->options(StrategicRole::class)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        app(StrategicCompetencyService::class)->saveManual(
                            auth()->user(),
                            $data['concept'],
                            $this->roleFromState($data['strategic_role']),
                        );

                        Notification::make()
                            ->success()
                            ->title('Competência classificada')
                            ->body('O papel estratégico foi salvo no seu perfil.')
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('Não foi possível classificar')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),
        ];
    }
}
