<?php

namespace App\Filament\Pages;

use App\Services\ResumeService;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ResumePage extends Page
{
    protected string $view = 'filament.pages.resume';

    protected static ?string $slug = 'resume';

    protected static ?string $navigationLabel = 'Currículo';

    protected static ?string $title = 'Currículo';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 5;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'content' => ResumeService::plainText(app(ResumeService::class)->content()),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('content')
                    ->label('Texto do currículo')
                    ->required()
                    ->rows(24)
                    ->helperText('Cole o texto do seu currículo. Um novo envio substitui a versão anterior (sem histórico).')
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        app(ResumeService::class)->save($data['content']);

        Notification::make()
            ->success()
            ->title('Currículo salvo')
            ->body('A comparação com o mercado já reflete o novo texto.')
            ->send();
    }

    public function lastUpdatedAt(): ?string
    {
        return app(ResumeService::class)->current()?->updated_at?->format('d/m/Y H:i');
    }
}
