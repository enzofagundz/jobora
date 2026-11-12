<?php

namespace App\Filament\Pages;

use App\Enums\AiOperationType;
use App\Enums\ResumeLanguage;
use App\Models\AiOperation;
use App\Models\ProfileImportProposal;
use App\Models\User;
use App\Services\AiOperationService;
use App\Services\ProfessionalProfileConflictException;
use App\Services\ProfessionalProfileService;
use App\Services\ProfileImportService;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use LogicException;
use Throwable;
use UnitEnum;

class ProfessionalProfilePage extends Page
{
    protected string $view = 'filament.pages.professional-profile';

    protected static ?string $slug = 'professional-profile';

    protected static ?string $navigationLabel = 'Perfil profissional';

    protected static ?string $title = 'Perfil profissional';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Perfil';

    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    public int $version = 0;

    public ?int $confirmedVersion = null;

    public ?array $confirmedSnapshot = null;

    public ?string $confirmedAt = null;

    public bool $hasUnconfirmedChanges = false;

    public bool $showConfirmation = false;

    public array $persistedDraft = [];

    public string $importLanguage = 'pt-BR';

    public ?int $importOperationId = null;

    public ?string $importProposalId = null;

    public ?array $importOperation = null;

    public ?array $importProposal = null;

    public bool $showImportConfirmation = false;

    public function mount(ProfessionalProfileService $profiles, ProfileImportService $imports): void
    {
        $this->applyProfile($profiles->get(Auth::user()));
        $this->refreshProfileImport($imports, $profiles);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Experiências')->schema([
                Repeater::make('experiences')->label('Experiências')->schema([
                    TextInput::make('company')->label('Empresa'),
                    TextInput::make('role')->label('Cargo'),
                    TextInput::make('period')->label('Período'),
                    Textarea::make('description')->label('Descrição')->columnSpanFull(),
                ])->columns(2)->defaultItems(0)->maxItems(100)->addActionLabel('Adicionar experiência')->columnSpanFull(),
            ]),
            Section::make('Formação')->schema([
                Repeater::make('education')->label('Formação')->schema([
                    TextInput::make('institution')->label('Instituição'),
                    TextInput::make('qualification')->label('Formação'),
                    TextInput::make('period')->label('Período'),
                ])->columns(3)->defaultItems(0)->maxItems(100)->addActionLabel('Adicionar formação')->columnSpanFull(),
            ]),
            Section::make('Competências')->schema([$this->listField('competencies', 'Competência', 'Adicionar competência')]),
            Section::make('Preferências')->schema([$this->listField('preferences', 'Preferência', 'Adicionar preferência')]),
            Section::make('Restrições')->schema([$this->listField('restrictions', 'Restrição', 'Adicionar restrição')]),
            Section::make('Pretensão')->schema([TextInput::make('compensation_expectation')->label('Pretensão salarial')->maxLength(255)]),
        ])->statePath('data');
    }

    public function saveDraft(ProfessionalProfileService $profiles): void
    {
        try {
            $profile = $profiles->saveDraft(Auth::user(), $this->document(), $this->version);
            $this->applyProfile($profile);
            Notification::make()->success()->title('Rascunho salvo')->send();
        } catch (Throwable $exception) {
            if (! $exception instanceof ProfessionalProfileConflictException) {
                throw $exception;
            }

            Notification::make()->danger()->title('Perfil alterado em outra sessão')->body('Seu formulário foi preservado. Recarregue a página antes de salvar novamente.')->send();
        }
    }

    public function confirm(ProfessionalProfileService $profiles): void
    {
        $this->showConfirmation = false;

        if ($this->document() !== $this->persistedDraft) {
            Notification::make()->warning()->title('Salve o rascunho antes de confirmar')->body('Suas alterações locais continuam no formulário.')->send();

            return;
        }

        try {
            $profile = $profiles->confirm(Auth::user(), $this->version, ['source' => 'filament']);
            $this->applyProfile($profile);
            Notification::make()->success()->title('Perfil confirmado')->send();
        } catch (Throwable $exception) {
            if (! $exception instanceof ProfessionalProfileConflictException) {
                throw $exception;
            }

            Notification::make()->danger()->title('Perfil alterado em outra sessão')->body('Seu formulário foi preservado. Recarregue a página antes de confirmar novamente.')->send();
        }
    }

    public function requestConfirmation(): void
    {
        $this->showConfirmation = true;
    }

    public function requestProfileImport(AiOperationService $operations, ProfileImportService $imports, ProfessionalProfileService $profiles): void
    {
        if ($this->document() !== $this->persistedDraft) {
            Notification::make()->warning()->title('Salve o rascunho antes de importar')->body('A importação usa o perfil salvo como base. Suas alterações locais continuam no formulário.')->send();

            return;
        }

        $this->validate(['importLanguage' => ['required', Rule::enum(ResumeLanguage::class)]]);

        try {
            $operation = $operations->enqueueProfileImport(Auth::user(), ResumeLanguage::from($this->importLanguage), $this->version, ['source' => 'filament']);
            $this->importOperationId = $operation->id;
            $this->refreshProfileImport($imports, $profiles);
            Notification::make()->success()->title('Importação solicitada')->body('Atualize o estado para acompanhar a proposta.')->send();
        } catch (ProfessionalProfileConflictException) {
            Notification::make()->danger()->title('Perfil alterado em outra sessão')->body('Recarregue a página antes de solicitar uma nova importação.')->send();
        } catch (LogicException $exception) {
            Notification::make()->warning()->title('Não foi possível importar')->body($exception->getMessage())->send();
        }
    }

    public function refreshProfileImport(ProfileImportService $imports, ProfessionalProfileService $profiles): void
    {
        $user = Auth::user();
        $this->version = $profiles->get($user)['version'];
        $operation = $this->importOperationId === null
            ? $this->latestImportOperation($user)
            : AiOperation::query()->where('user_id', $user->id)->whereKey($this->importOperationId)
                ->where('operation_type', AiOperationType::ProfileImport)->where('target_type', 'professional_profile')->first();

        if ($operation === null) {
            $this->clearImportState();

            return;
        }

        $this->importOperationId = $operation->id;
        $proposal = ProfileImportProposal::query()->where('user_id', $user->id)->where('ai_operation_id', $operation->id)->latest('id')->first();
        $this->importProposalId = $proposal === null ? null : (string) $proposal->id;
        $this->importOperation = [
            'id' => $operation->id,
            'status' => $operation->status?->value,
            'attempts' => $operation->attempts,
            'failure_code' => $operation->failure_code,
            'base_version' => $operation->input_snapshot['base_version'] ?? null,
            'retry_allowed' => $operation->status?->value === 'failed' && $operation->attempts < 3 && $operation->failure_code !== 'authorization_revoked'
                && ($operation->input_snapshot['base_version'] ?? null) === $this->version,
        ];
        $this->importProposal = $proposal === null ? null : $imports->get($user, (string) $proposal->id);
    }

    public function retryProfileImport(AiOperationService $operations, ProfileImportService $imports, ProfessionalProfileService $profiles): void
    {
        $this->refreshProfileImport($imports, $profiles);

        if (! ($this->importOperation['retry_allowed'] ?? false)) {
            return;
        }

        $ownedOperation = AiOperation::query()->where('user_id', Auth::id())->whereKey($this->importOperationId)
            ->where('operation_type', AiOperationType::ProfileImport)->where('target_type', 'professional_profile')->first();
        $language = ResumeLanguage::tryFrom((string) ($ownedOperation?->input_snapshot['source']['language'] ?? ''));

        if ($ownedOperation === null || $language === null) {
            $this->clearImportState();

            return;
        }

        try {
            $operation = $operations->enqueueProfileImport(Auth::user(), $language, $this->version, ['source' => 'filament'], retry: true);
            $this->importOperationId = $operation->id;
            $this->refreshProfileImport($imports, $profiles);
            Notification::make()->success()->title('Nova tentativa solicitada')->send();
        } catch (ProfessionalProfileConflictException) {
            $this->refreshProfileImport($imports, $profiles);
            Notification::make()->warning()->title('Não foi possível tentar novamente')->body('O perfil mudou. Atualize a página antes de tentar novamente.')->send();
        } catch (LogicException $exception) {
            Notification::make()->warning()->title('Não foi possível tentar novamente')->body($exception->getMessage())->send();
        }
    }

    public function requestImportConfirmation(ProfileImportService $imports, ProfessionalProfileService $profiles): void
    {
        $this->refreshProfileImport($imports, $profiles);

        if (! in_array('confirm', $this->importProposal['allowed_actions'] ?? [], true)) {
            Notification::make()->warning()->title(($this->importProposal['stale'] ?? false) ? 'Esta proposta ficou desatualizada' : 'Proposta indisponível')->body('Solicite uma nova importação para revisar a versão atual do perfil.')->send();

            return;
        }

        if ($this->document() !== $this->persistedDraft) {
            Notification::make()->warning()->title('Salve o rascunho antes de confirmar')->body('Suas alterações locais continuam no formulário.')->send();

            return;
        }

        $this->showImportConfirmation = true;
    }

    public function confirmProfileImport(ProfileImportService $imports, ProfessionalProfileService $profiles): void
    {
        $this->showImportConfirmation = false;
        $this->refreshProfileImport($imports, $profiles);

        if (! in_array('confirm', $this->importProposal['allowed_actions'] ?? [], true) || $this->document() !== $this->persistedDraft) {
            $title = ($this->importProposal['stale'] ?? false)
                ? 'Esta proposta ficou desatualizada'
                : 'Proposta indisponível ou perfil alterado';
            Notification::make()->warning()->title($title)->body('Revise a proposta e salve o rascunho antes de confirmar.')->send();

            return;
        }

        try {
            $result = $imports->confirm(Auth::user(), $this->importProposal['id'], $this->importProposal['base_version'], ['source' => 'filament']);
            $this->importProposal = $result;
            if (isset($result['profile'])) {
                $this->applyProfile($result['profile']);
            }
            Notification::make()->success()->title('Proposta confirmada')->send();
        } catch (ProfessionalProfileConflictException) {
            $this->refreshProfileImport($imports, $profiles);
            Notification::make()->warning()->title('Proposta desatualizada')->body('Atualize a proposta antes de decidir.')->send();
        }
    }

    public function rejectProfileImport(ProfileImportService $imports, ProfessionalProfileService $profiles): void
    {
        $this->refreshProfileImport($imports, $profiles);

        if (! in_array('reject', $this->importProposal['allowed_actions'] ?? [], true)) {
            return;
        }

        try {
            $this->importProposal = $imports->reject(Auth::user(), $this->importProposal['id'], ['source' => 'filament']);
            Notification::make()->success()->title('Proposta rejeitada')->send();
        } catch (ProfessionalProfileConflictException) {
            $this->refreshProfileImport($imports, $profiles);
            Notification::make()->warning()->title('Proposta desatualizada')->body('Atualize a proposta antes de decidir.')->send();
        }
    }

    private function latestImportOperation(User $user): ?AiOperation
    {
        return AiOperation::query()->where('user_id', $user->id)
            ->where('operation_type', AiOperationType::ProfileImport)
            ->where('target_type', 'professional_profile')
            ->latest('id')->first();
    }

    private function clearImportState(): void
    {
        $this->importOperationId = null;
        $this->importProposalId = null;
        $this->importOperation = null;
        $this->importProposal = null;
    }

    private function listField(string $name, string $label, string $action): Repeater
    {
        return Repeater::make($name)->label($label)->schema([
            TextInput::make('value')->label($label)->required()->maxLength(100),
        ])->defaultItems(0)->maxItems(100)->addActionLabel($action)->columnSpanFull();
    }

    private function document(): array
    {
        $data = $this->form->getState();

        foreach (['competencies', 'preferences', 'restrictions'] as $field) {
            $data[$field] = array_values(array_filter(array_column($data[$field] ?? [], 'value'), static fn (?string $value): bool => $value !== null && $value !== ''));
        }

        return $data;
    }

    private function applyProfile(array $profile): void
    {
        $this->version = $profile['version'];
        $this->confirmedVersion = $profile['confirmed_version'];
        $this->confirmedSnapshot = $profile['confirmed_snapshot'];
        $this->confirmedAt = isset($profile['confirmed_at']) ? (string) $profile['confirmed_at'] : null;
        $this->hasUnconfirmedChanges = $profile['has_unconfirmed_changes'];
        $draft = $profile['draft'] ?? [];

        foreach (['competencies', 'preferences', 'restrictions'] as $field) {
            $draft[$field] = array_map(static fn (string $value): array => ['value' => $value], $draft[$field] ?? []);
        }

        $this->form->fill($draft);
        $this->persistedDraft = $this->document();
    }
}
