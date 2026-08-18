<?php

namespace App\Filament\Resources\JobPostings\Pages;

use App\Enums\ConceptCategory;
use App\Enums\ItemSource;
use App\Enums\JobPostingStatus;
use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Jobs\AnalyzeJobPosting;
use App\Services\ConceptNormalizer;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ReviewJobPosting extends Page
{
    use InteractsWithRecord;

    protected static string $resource = JobPostingResource::class;

    protected string $view = 'filament.resources.job-postings.pages.review-job-posting';

    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        Gate::authorize('update', $this->record);

        if ($this->record->status !== JobPostingStatus::NeedsReview) {
            $this->redirect(ListJobPostings::getUrl());
        }

        $this->fillForm();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                RichEditor::make('description')
                    ->label('Descrição original (imutável)')
                    ->disabled()
                    ->columnSpanFull(),
                TextInput::make('role')
                    ->label('Cargo')
                    ->maxLength(255),
                TextInput::make('company')
                    ->label('Empresa')
                    ->maxLength(255),
                Repeater::make('items')
                    ->label('Itens extraídos')
                    ->helperText('Itens mantidos como vieram da IA são marcados como "ai"; itens editados ou adicionados, como "user".')
                    ->schema([
                        Select::make('category')
                            ->label('Categoria')
                            ->options(collect(ConceptCategory::all())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all())
                            ->required(),
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(100),
                        TextInput::make('source')
                            ->label('Origem')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    protected function fillForm(): void
    {
        $items = collect($this->record->suggested_data['items'] ?? [])
            ->map(fn (array $item): array => $item + ['source' => ItemSource::Ai->value])
            ->all();

        $this->form->fill([
            'description' => $this->record->description,
            'role' => $this->record->role,
            'company' => $this->record->company,
            'items' => $items,
        ]);
    }

    public function confirm(): void
    {
        Gate::authorize('update', $this->record);

        $data = $this->form->getState();

        $normalizer = app(ConceptNormalizer::class);
        $suggestedPairs = collect($this->record->suggested_data['items'] ?? [])
            ->map(fn (array $item): string => $item['category'].'|'.$item['name'])
            ->all();

        DB::transaction(function () use ($data, $normalizer, $suggestedPairs): void {
            $this->record->items()->delete();

            foreach ($data['items'] as $item) {
                $name = $normalizer->normalize($item['name']);

                if ($name === '') {
                    continue;
                }

                $this->record->items()->create([
                    'category' => $item['category'],
                    'name' => $name,
                    'source' => in_array($item['category'].'|'.$name, $suggestedPairs, true)
                        ? ItemSource::Ai
                        : ItemSource::User,
                ]);
            }

            $this->record->forceFill([
                'role' => blank($data['role']) ? null : $data['role'],
                'company' => blank($data['company']) ? null : $data['company'],
                'status' => JobPostingStatus::Confirmed,
                'suggested_data' => null,
                'error_message' => null,
            ])->save();
        });

        Notification::make()
            ->success()
            ->title('Vaga confirmada')
            ->body('Os itens confirmados agora compõem as análises.')
            ->send();

        $this->redirect(ListJobPostings::getUrl());
    }

    public function discard(): void
    {
        Gate::authorize('update', $this->record);

        $this->record->forceFill([
            'status' => JobPostingStatus::Pending,
            'suggested_data' => null,
            'role' => null,
            'company' => null,
            'error_message' => null,
        ])->save();

        Notification::make()
            ->warning()
            ->title('Sugestão descartada')
            ->body('A vaga voltou para "pendente" e pode ser analisada novamente.')
            ->send();

        $this->redirect(ListJobPostings::getUrl());
    }

    public function analyzeAgain(): void
    {
        Gate::authorize('update', $this->record);

        AnalyzeJobPosting::dispatch($this->record);

        Notification::make()
            ->info()
            ->title('Análise em andamento')
            ->body('A vaga será reanalisada em segundo plano. O status será atualizado na listagem.')
            ->send();

        $this->redirect(ListJobPostings::getUrl());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('analyzeAgain')
                ->label('Analisar com IA novamente')
                ->icon('heroicon-o-sparkles')
                ->color('info')
                ->action(fn () => $this->analyzeAgain()),
            Action::make('discard')
                ->label('Descartar sugestão')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Descartar sugestão')
                ->modalDescription('A sugestão da IA será removida e a vaga voltará para "pendente".')
                ->action(fn () => $this->discard()),
        ];
    }
}
