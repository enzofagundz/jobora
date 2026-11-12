<?php

namespace App\Filament\Resources\JobPostings\Pages;

use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Models\AiOperation;
use App\Models\JobPosting;
use App\Models\MatchingEvaluation;
use App\Services\AiOperationService;
use App\Services\MatchingException;
use App\Services\MatchingService;
use App\Services\ProfessionalProfileService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\Locked;
use LogicException;

class MatchingPage extends ViewRecord
{
    protected static string $resource = JobPostingResource::class;

    protected string $view = 'filament.resources.job-postings.pages.matching';

    public ?array $matchingData = null;

    public ?array $activeEvaluation = null;

    public ?array $operationState = null;

    public ?int $operationId = null;

    public int $historyOffset = 0;

    public ?int $nextHistoryOffset = null;

    public ?int $nextHistoryCursor = null;

    #[Locked]
    public ?int $expectedOpportunityVersion = null;

    #[Locked]
    public ?int $expectedProfileConfirmedVersion = null;

    public bool $profileConfirmed = false;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->assertOwnedOpportunity();
        $this->loadDeclaredVersions();
        $this->refreshMatching();
    }

    public function requestMatching(AiOperationService $operations): void
    {
        $this->assertOwnedOpportunity();

        if (! $this->record->isStructuredOpportunity() || $this->record->readiness !== 'ready' || ! $this->profileConfirmed) {
            Notification::make()->warning()->title('Não é possível solicitar compatibilidade')->body('Confirme o perfil e use oportunidade pronta.')->send();

            return;
        }

        try {
            $operation = $operations->enqueueMatching(
                auth()->user(),
                (int) $this->record->getKey(),
                (int) $this->expectedOpportunityVersion,
                (int) $this->expectedProfileConfirmedVersion,
                'matching-v1',
                ['source' => 'filament'],
            );
            $this->operationId = $operation->id;
            $this->readOperation();
            Notification::make()->info()->title('Avaliação solicitada')->body('Resultado será carregado quando a operação terminar.')->send();
        } catch (MatchingException $exception) {
            $this->notifyMatchingError($exception->failureCode);
        } catch (LogicException $exception) {
            Notification::make()->warning()->title('Não foi possível solicitar avaliação')->body($exception->getMessage())->send();
        }
    }

    public function refreshMatching(): void
    {
        $this->assertOwnedOpportunity();

        if ($this->operationId !== null) {
            $this->readOperation();
        } else {
            $this->findLatestOperation();
        }

        $this->reloadEvaluationHistory();
    }

    public function showMoreHistory(): void
    {
        if ($this->nextHistoryCursor === null) {
            return;
        }

        $next = app(MatchingService::class)->forOpportunity(auth()->user(), (int) $this->record->getKey(), 10, beforeId: $this->nextHistoryCursor);
        $items = collect($this->matchingData['history']['items'] ?? [])
            ->concat($next['history']['items'] ?? [])
            ->unique('id')->values()->all();
        $this->matchingData['history']['items'] = $items;
        $this->nextHistoryOffset = $next['history']['next_offset'];
        $this->nextHistoryCursor = $next['history']['next_cursor'];
    }

    public function retryMatching(AiOperationService $operations, MatchingService $matching): void
    {
        $operation = $this->ownedMatchingOperation();

        if ($operation === null || ! $matching->retryAllowed(auth()->user(), $operation)) {
            Notification::make()->warning()->title('Nova tentativa indisponível')->body('A entrada mudou ou operação não permite retry. Atualize a página e revise versões.')->send();

            return;
        }

        try {
            $retry = $operations->enqueueMatching(
                auth()->user(),
                (int) $this->record->getKey(),
                (int) $this->expectedOpportunityVersion,
                (int) $this->expectedProfileConfirmedVersion,
                'matching-v1',
                ['source' => 'filament'],
                retry: true,
            );
            $this->operationId = $retry->id;
            $this->readOperation();
            Notification::make()->info()->title('Nova tentativa solicitada')->send();
        } catch (MatchingException $exception) {
            $this->notifyMatchingError($exception->failureCode);
        } catch (LogicException $exception) {
            Notification::make()->warning()->title('Nova tentativa indisponível')->body($exception->getMessage())->send();
        }
    }

    public function canRetryMatching(): bool
    {
        $operation = $this->ownedMatchingOperation();

        return $operation !== null && app(MatchingService::class)->retryAllowed(auth()->user(), $operation);
    }

    public function matchingStatusLabel(?string $status): string
    {
        return match ($status) {
            'met' => 'Atendido',
            'unsupported' => 'Sem evidência',
            'incompatible' => 'Incompatível',
            'uncertain' => 'Incerto',
            default => filled($status) ? $status : '—',
        };
    }

    public function reasonLabel(?string $reason): string
    {
        return match ($reason) {
            'explicit_support' => 'Há suporte explícito nas fontes.',
            'explicit_conflict' => 'As fontes mostram conflito explícito.',
            'criterion_not_stated' => 'Critério não informado nas fontes.',
            'profile_information_missing' => 'Perfil não contém informação suficiente.',
            'opportunity_information_missing' => 'Oportunidade não contém informação suficiente.',
            'bilateral_information_missing' => 'Faltam informações nos dois lados.',
            'ambiguous_evidence' => 'As evidências permitem mais de uma interpretação.',
            'partial_overlap' => 'Há sobreposição parcial entre as informações.',
            'incomparable_compensation' => 'Valores salariais não são diretamente comparáveis.',
            default => 'Motivo não especificado.',
        };
    }

    public function criterionLabel(?string $kind): string
    {
        return match ($kind) {
            'requirement' => 'Requisito',
            'technology' => 'Tecnologia',
            'experience' => 'Experiência',
            'seniority' => 'Senioridade',
            'education' => 'Formação',
            'location_modality' => 'Local e modalidade',
            'compensation' => 'Remuneração',
            'preference' => 'Preferência',
            'restriction' => 'Restrição',
            default => 'Critério',
        };
    }

    public function sourceLabel(?string $source): string
    {
        return match ($source) {
            'profile' => 'Perfil confirmado',
            'opportunity' => 'Oportunidade',
            'publication' => 'Publicação de origem',
            default => 'Fonte',
        };
    }

    private function assertOwnedOpportunity(): void
    {
        $this->record = JobPosting::query()->where('user_id', auth()->id())->findOrFail($this->record->getKey());
    }

    private function loadDeclaredVersions(): void
    {
        $profile = app(ProfessionalProfileService::class)->get(auth()->user());
        $this->profileConfirmed = $profile['confirmed_snapshot'] !== null && $profile['confirmed_version'] > 0;
        $this->expectedOpportunityVersion = $this->record->opportunity_version;
        $this->expectedProfileConfirmedVersion = $profile['confirmed_version'];
    }

    private function reloadEvaluationHistory(): void
    {
        $this->matchingData = app(MatchingService::class)->forOpportunity(
            auth()->user(), (int) $this->record->getKey(), 10, $this->historyOffset,
        );
        $this->activeEvaluation = $this->matchingData['current'] ?? ($this->matchingData['history']['items'][0] ?? null);
        $this->nextHistoryOffset = $this->matchingData['history']['next_offset'];
        $this->nextHistoryCursor = $this->matchingData['history']['next_cursor'];
    }

    private function findLatestOperation(): void
    {
        $operation = $this->ownedMatchingOperation();

        if ($operation !== null) {
            $this->operationId = $operation->id;
            $this->readOperation();
        } else {
            $this->operationState = null;
        }
    }

    private function ownedMatchingOperation(): ?AiOperation
    {
        return AiOperation::query()->where('user_id', auth()->id())
            ->where('target_type', 'opportunity')
            ->where('target_id', $this->record->getKey())
            ->where('operation_type', AiOperationType::Matching)
            ->latest('id')->first();
    }

    private function readOperation(): void
    {
        $operation = AiOperation::query()->where('user_id', auth()->id())
            ->where('target_type', 'opportunity')
            ->where('target_id', $this->record->getKey())
            ->where('operation_type', AiOperationType::Matching)
            ->find($this->operationId);

        if ($operation === null) {
            $this->operationState = null;
            $this->operationId = null;

            return;
        }

        $this->operationState = [
            'id' => $operation->id,
            'status' => $operation->status->value,
            'failure_code' => $operation->failure_code,
        ];

        if ($operation->status === AiOperationStatus::Completed) {
            $evaluationId = MatchingEvaluation::query()
                ->where('ai_operation_id', $operation->id)
                ->where('user_id', auth()->id())
                ->value('id');

            if ($evaluationId !== null) {
                $this->activeEvaluation = app(MatchingService::class)->get(auth()->user(), $evaluationId);
            }
        }
    }

    private function notifyMatchingError(string $code): void
    {
        $message = match ($code) {
            'profile_not_confirmed' => 'Confirme seu perfil antes de solicitar compatibilidade.',
            'opportunity_not_ready' => 'A oportunidade ainda não está pronta.',
            'conflict' => 'Perfil ou oportunidade mudou. Atualize e revise as versões antes de solicitar novamente.',
            'authorization_revoked' => 'A autorização da conta não está mais válida.',
            default => 'Solicitação não foi aceita. Atualize o estado e tente novamente.',
        };
        Notification::make()->warning()->title('Não foi possível solicitar compatibilidade')->body($message)->send();
    }
}
