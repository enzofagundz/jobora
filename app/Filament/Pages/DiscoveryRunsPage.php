<?php

namespace App\Filament\Pages;

use App\Enums\DiscoveryRunStatus;
use App\Models\DiscoveryRun;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Livewire\Attributes\Computed;
use UnitEnum;

class DiscoveryRunsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.discovery-runs-page';

    protected static ?string $slug = 'discovery-runs';

    protected static ?string $navigationLabel = 'Execuções de descoberta';

    protected static ?string $title = 'Execuções de descoberta';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = 'Vagas';

    protected static ?int $navigationSort = 4;

    public ?int $selectedRunId = null;

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()?->disabled_at === null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => DiscoveryRun::query()
                ->where('user_id', auth()->id())
                ->whereHas('source', fn (Builder $query): Builder => $query->withTrashed()->where('user_id', auth()->id()))
                ->with(['source' => function (BelongsTo $relation): void {
                    $relation->withTrashed()->where('user_id', auth()->id());
                }])
                ->latest('reserved_at'))
            ->columns([
                TextColumn::make('source.name')->label('Fonte')->placeholder('Fonte removida'),
                TextColumn::make('status')->label('Estado')->badge()
                    ->formatStateUsing(fn (DiscoveryRunStatus $state): string => match ($state) {
                        DiscoveryRunStatus::Active => 'Em andamento',
                        DiscoveryRunStatus::Completed => 'Concluída',
                        DiscoveryRunStatus::Expired => 'Expirada',
                        DiscoveryRunStatus::Cancelled => 'Cancelada',
                    }),
                TextColumn::make('surface')->label('Tipo de busca'),
                TextColumn::make('reserved_at')->label('Iniciada')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('completed_at')->label('Concluída')->dateTime('d/m/Y H:i')->placeholder('—'),
            ])
            ->recordActions([
                Action::make('inspect')
                    ->label('Inspecionar')
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->action(fn (DiscoveryRun $record) => $this->inspectRun((int) $record->getKey())),
            ])
            ->emptyStateHeading('Nenhuma execução de descoberta')
            ->emptyStateDescription('Execuções criadas pelo agente aparecerão aqui. Histórico permanece disponível após remover uma fonte.');
    }

    public function inspectRun(int $id): void
    {
        DiscoveryRun::query()
            ->where('user_id', auth()->id())
            ->whereHas('source', fn (Builder $query): Builder => $query->withTrashed()->where('user_id', auth()->id()))
            ->findOrFail($id);
        $this->selectedRunId = $id;
        unset($this->selectedRun);
    }

    #[Computed]
    public function selectedRun(): ?DiscoveryRun
    {
        if ($this->selectedRunId === null) {
            return null;
        }

        $run = DiscoveryRun::query()
            ->where('user_id', auth()->id())
            ->whereHas('source', fn (Builder $query): Builder => $query->withTrashed()->where('user_id', auth()->id()))
            ->with([
                'source' => function (BelongsTo $relation): void {
                    $relation->withTrashed()->where('user_id', auth()->id());
                },
                'integration' => function (BelongsTo $relation): void {
                    $relation->where('user_id', auth()->id());
                },
                'events' => function (HasMany $relation): void {
                    $relation->orderBy('occurred_at')->orderBy('id');
                },
            ])
            ->find($this->selectedRunId);

        return $run;
    }
}
