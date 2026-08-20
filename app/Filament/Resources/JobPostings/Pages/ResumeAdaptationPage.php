<?php

namespace App\Filament\Resources\JobPostings\Pages;

use App\Enums\JobPostingStatus;
use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Models\ResumeAdaptation;
use App\Services\ResumeAdaptationService;
use App\Services\ResumeService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;

/**
 * Página de resultado da adequação de currículo (F6) — não é CRUD:
 * mostra o currículo original, a versão adaptada e os resultados
 * (alterações, contemplados, ausentes, alertas), com ações para
 * adequar novamente, copiar e baixar o PDF.
 */
class ResumeAdaptationPage extends Page
{
    use InteractsWithRecord;

    protected static string $resource = JobPostingResource::class;

    protected static ?string $title = 'Adequação de currículo';

    protected string $view = 'filament.resources.job-postings.pages.resume-adaptation';

    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        Gate::authorize('view', $this->record);

        if ($this->record->status !== JobPostingStatus::Confirmed) {
            $this->redirect(ListJobPostings::getUrl());
        }

        $this->form->fill([
            'original_resume' => ResumeService::plainText(app(ResumeService::class)->content()),
        ]);

        $this->fillAdaptedForm();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('original_resume')
                    ->label('Currículo original')
                    ->hiddenLabel()
                    ->disabled()
                    ->rows(24)
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function adaptedForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('adapted_resume')
                    ->label('Currículo adaptado')
                    ->hiddenLabel()
                    ->disabled()
                    ->rows(24)
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function getSubheading(): ?string
    {
        return 'Compare o currículo original com a versão adaptada para esta vaga.';
    }

    private function fillAdaptedForm(): void
    {
        // fillPartially preserva data.original_resume (fill() substituiria o array data inteiro).
        $this->adaptedForm->fillPartially(
            [
                'adapted_resume' => $this->adaptation()?->adapted_content ?? '',
            ],
            ['adapted_resume'],
        );
    }

    public function originalResume(): ?string
    {
        return app(ResumeService::class)->content();
    }

    public function adaptation(): ?ResumeAdaptation
    {
        return ResumeAdaptation::where('job_posting_id', $this->record->id)->first();
    }

    public function adapt(): void
    {
        Gate::authorize('update', $this->record);

        try {
            app(ResumeAdaptationService::class)->adapt($this->record);

            $this->fillAdaptedForm();

            Notification::make()
                ->success()
                ->title('Currículo adequado')
                ->body('A versão adaptada para esta vaga foi gerada.')
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Falha na adequação')
                ->body($e->getMessage())
                ->send();
        }
    }

    public function downloadPdf(): ?string
    {
        return $this->adaptation() === null ? null : route('filament.admin.resume-pdf.download', $this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('adapt')
                ->label(fn (): string => $this->adaptation() === null ? 'Adequar currículo' : 'Adequar novamente')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->action(fn () => $this->adapt()),
            Action::make('downloadPdf')
                ->label('Baixar PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->visible(fn (): bool => $this->adaptation() !== null)
                ->url(fn (): string => route('filament.admin.resume-pdf.download', $this->record)),
        ];
    }
}
