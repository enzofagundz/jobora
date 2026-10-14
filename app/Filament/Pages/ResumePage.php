<?php

namespace App\Filament\Pages;

use App\Enums\ResumeLanguage;
use App\Services\AiOperationService;
use App\Services\ResumeService;
use App\Support\Staleness;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;
use UnitEnum;

class ResumePage extends Page
{
    protected string $view = 'filament.pages.resume';

    protected static ?string $slug = 'resume';

    protected static ?string $navigationLabel = 'Currículo';

    protected static ?string $title = 'Currículo';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Perfil';

    protected static ?int $navigationSort = 5;

    public ?array $data = [];

    public string $activeTab = 'pt-br';

    public function mount(): void
    {
        $service = app(ResumeService::class);
        $user = auth()->user();

        $this->form->fill([
            'content' => ResumeService::plainText($service->content($user, ResumeLanguage::PtBr)),
            'content_en' => ResumeService::plainText($service->content($user, ResumeLanguage::En)),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('language')
                    ->livewireProperty('activeTab')
                    ->tabs([
                        'pt-br' => Tab::make('PT-BR')->schema([
                            Textarea::make('content')
                                ->label('Texto do currículo (PT-BR)')
                                ->required(fn (): bool => $this->activeTab === 'pt-br')
                                ->rows(24)
                                ->helperText('Cole o texto do seu currículo. Um novo envio substitui a versão anterior (sem histórico).')
                                ->columnSpanFull(),
                        ]),
                        'en' => Tab::make('EN')->schema([
                            Textarea::make('content_en')
                                ->label('Texto do currículo (EN)')
                                ->required(fn (): bool => $this->activeTab === 'en')
                                ->rows(24)
                                ->helperText('A versão em inglês pode ser gerada pelo sistema e editada à mão.')
                                ->columnSpanFull(),
                        ]),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $service = app(ResumeService::class);

        if ($this->activeTab === 'en') {
            $service->save(auth()->user(), $data['content_en'] ?? '', ResumeLanguage::En);
            Notification::make()
                ->success()
                ->title('Versão em inglês salva')
                ->body('A versão EN foi atualizada com o seu texto.')
                ->send();

            return;
        }

        $service->save(auth()->user(), $data['content'], ResumeLanguage::PtBr);
        Notification::make()
            ->success()
            ->title('Currículo salvo')
            ->body('A comparação com o mercado já reflete o novo texto.')
            ->send();
    }

    public function generateEnglish(): void
    {
        try {
            app(AiOperationService::class)->enqueueResumeTranslation(auth()->user());

            Notification::make()
                ->info()
                ->title('Tradução em andamento')
                ->body('A versão em inglês será atualizada quando o processamento terminar.')
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Falha na tradução')
                ->body($e->getMessage())
                ->send();
        }
    }

    public function englishContent(): ?string
    {
        return app(ResumeService::class)->content(auth()->user(), ResumeLanguage::En);
    }

    public function isEnglishStale(): bool
    {
        $resume = app(ResumeService::class)->current(auth()->user(), ResumeLanguage::En);

        return $resume !== null && Staleness::isResumeTranslationStale($resume);
    }

    public function lastUpdatedAt(): ?string
    {
        return app(ResumeService::class)->current(auth()->user(), ResumeLanguage::PtBr)?->updated_at?->format('d/m/Y H:i');
    }

    public function lastUpdatedAtEn(): ?string
    {
        return app(ResumeService::class)->current(auth()->user(), ResumeLanguage::En)?->updated_at?->format('d/m/Y H:i');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateEnglish')
                ->label(fn (): string => filled($this->englishContent()) ? 'Gerar novamente' : 'Gerar versão em inglês')
                ->icon(Heroicon::OutlinedLanguage)
                ->color('primary')
                ->requiresConfirmation(fn (): bool => filled($this->englishContent()))
                ->modalHeading('Gerar versão em inglês')
                ->modalDescription('A versão em inglês atual será substituída pela nova tradução. Suas edições manuais serão perdidas.')
                ->action(fn () => $this->generateEnglish()),
        ];
    }
}
