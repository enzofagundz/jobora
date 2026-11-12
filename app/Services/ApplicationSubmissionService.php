<?php

namespace App\Services;

use App\Enums\AgentIntegrationScope;
use App\Enums\ApplicationSubmissionState as State;
use App\Mcp\Support\AgentIntegrationContext;
use App\Mcp\Support\ToolException;
use App\Models\AgentIntegration;
use App\Models\Application;
use App\Models\ApplicationApproval;
use App\Models\ApplicationPreparation;
use App\Models\ApplicationSubmissionAttempt;
use App\Models\ApplicationSubmissionEvent;
use App\Models\TelegramIdentityBinding;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Passport;

class ApplicationSubmissionService
{
    /**
     * @param  array{approval_id: string, expected_fingerprint: string, idempotency_key: string}  $input
     * @return array<string, mixed>
     */
    public function reserve(User $user, array $input): array
    {
        $input = Validator::validate($input, [
            'approval_id' => ['required', 'uuid'], 'expected_fingerprint' => ['required', 'regex:/\A[a-f0-9]{64}\z/D'],
            'idempotency_key' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/D'],
        ]);
        $hash = $this->hash($input);

        return DB::transaction(function () use ($user, $input, $hash): array {
            $integration = $this->lockActor($user);
            $existing = ApplicationSubmissionAttempt::query()->where('user_id', $user->id)->where('integration_id', $integration->id)
                ->where('idempotency_key', $input['idempotency_key'])->first();
            if ($existing !== null) {
                $this->assertHash($existing->request_hash, $hash);

                return $this->present($existing);
            }
            $approval = $this->lockApproval($user, $input['approval_id'], $integration);
            $preparation = ApplicationPreparation::query()->findOrFail($approval->preparation_id);
            $this->assertApproval($user, $approval, $input['expected_fingerprint']);
            $active = ApplicationSubmissionAttempt::query()->where('active_application_id', $preparation->application_id)->lockForUpdate()->first();
            if ($active !== null && $active->state === State::Reserved && $active->reservation_expires_at->lte(now())) {
                $active->update(['state' => State::Expired, 'active_application_id' => null, 'resolved_at' => now(), 'revision' => $active->revision + 1]);
                $this->event($active, 'reservation_expired', 'expired', $this->hash(['attempt_id' => $active->id, 'transition' => 'expired']),
                    $user, $integration, occurredAt: $active->reservation_expires_at);
                $active = null;
            }
            if ($active !== null || ApplicationSubmissionAttempt::query()->where('application_id', $preparation->application_id)->where('state', State::Succeeded)->exists()) {
                throw ToolException::conflict('Existe envio ativo, incerto ou já concluído para esta candidatura.');
            }
            if (ApplicationSubmissionAttempt::query()->where('consumed_approval_id', $approval->id)->exists()) {
                throw ToolException::precondition('A aprovação já foi consumida.');
            }
            $failed = ApplicationSubmissionAttempt::query()->where('application_id', $preparation->application_id)->where('state', State::Failed)->whereNotNull('consumed_at')->latest('resolved_at')->first();
            if ($failed !== null && ($approval->approved_at->lte($failed->resolved_at) || $approval->received_at->lte($failed->resolved_at))) {
                throw ToolException::precondition('Uma nova aprovação posterior à resolução é necessária.');
            }
            $attempt = ApplicationSubmissionAttempt::query()->create([
                'user_id' => $user->id, 'integration_id' => $integration->id, 'application_id' => $preparation->application_id,
                'approval_id' => $approval->id, 'preparation_id' => $preparation->id, 'fingerprint' => $approval->fingerprint,
                'idempotency_key' => $input['idempotency_key'], 'request_hash' => $hash, 'state' => State::Reserved, 'revision' => 1,
                'active_application_id' => $preparation->application_id, 'reserved_at' => now(),
                'reservation_expires_at' => now()->addSeconds(max(1, (int) config('application-submissions.reservation_seconds')))->min($approval->expires_at),
            ]);
            $this->event($attempt, 'reservation', 'reserved', $hash, $user, $integration);

            return $this->present($attempt);
        }, attempts: 5);
    }

    /**
     * @param  array{attempt_id: string, expected_revision: int, expected_fingerprint: string, event_key: string}  $input
     * @return array<string, mixed>
     */
    public function consume(User $user, array $input): array
    {
        $input = Validator::validate($input, [
            'attempt_id' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:1'],
            'expected_fingerprint' => ['required', 'regex:/\A[a-f0-9]{64}\z/D'], 'event_key' => ['required', 'string', 'max:128'],
        ]);
        $hash = $this->hash($input);

        return DB::transaction(function () use ($user, $input, $hash): array {
            $integration = $this->lockActor($user);
            $attempt = $this->owned($user, $integration, $input['attempt_id']);
            $approval = $this->lockApproval($user, $attempt->approval_id, $integration);
            $attempt = ApplicationSubmissionAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $event = $attempt->events()->where('event_key', $input['event_key'])->first();
            if ($event !== null) {
                $this->assertHash($event->request_hash, $hash);

                return [...$this->present($attempt), 'already_consumed' => $attempt->consumed_at !== null];
            }
            if ($attempt->consumed_at !== null) {
                return [...$this->present($attempt), 'already_consumed' => true];
            }
            if ($attempt->revision !== $input['expected_revision']) {
                throw ToolException::conflict('A revisão da tentativa mudou.');
            }
            if ($attempt->state !== State::Reserved || $attempt->reservation_expires_at->lte(now())) {
                throw ToolException::precondition('A reserva não está disponível para consumo.');
            }
            $this->assertApproval($user, $approval, $input['expected_fingerprint']);
            $consumedAt = CarbonImmutable::now();
            $executeBefore = $consumedAt->addSeconds(max(1, (int) config('application-submissions.execution_seconds')))->min($approval->expires_at);
            $attempt->update(['state' => State::Sending, 'revision' => $attempt->revision + 1, 'consumed_approval_id' => $approval->id,
                'consumed_at' => $consumedAt, 'execute_before' => $executeBefore,
                'result_due_at' => $consumedAt->addSeconds(max(1, (int) config('application-submissions.result_seconds')))->max($executeBefore)]);
            $this->event($attempt, $input['event_key'], 'consumed', $hash, $user, $integration);

            return [...$this->present($attempt), 'authorizes_submission' => true, 'already_consumed' => false];
        }, attempts: 5);
    }

    private function lockActor(User $user): AgentIntegration
    {
        User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
        if (! app()->bound(AgentIntegrationContext::class) || auth('api')->id() !== $user->id || ! $user->currentAccessToken() instanceof AccessToken) {
            throw new AuthorizationException('O envio exige transporte MCP remoto autenticado.');
        }
        $context = app(AgentIntegrationContext::class);
        $integration = AgentIntegration::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($context->integration->id);
        $accessToken = $user->currentAccessToken();
        $token = Passport::token()->newQuery()->whereKey($accessToken->oauth_access_token_id)->lockForUpdate()->first();
        if ($context->user->id !== $user->id || $token === null || $token->revoked || $token->expires_at->lte(now())
            || (int) $token->user_id !== $user->id || ! $accessToken->can('mcp:use')
            || (string) $token->client_id !== (string) $integration->oauth_client_id) {
            throw new AuthorizationException;
        }
        app(ApplicationDocumentAccess::class)->authorize($user, ['source' => 'mcp', 'integration_id' => $integration->id], AgentIntegrationScope::ApplicationsSubmit);

        return $integration;
    }

    /**
     * @param  array{attempt_id: string, event_key: string, outcome: 'succeeded'|'failed'|'unknown', occurred_at: string, evidence: array{kind: 'submission_receipt'|'not_submitted'|'ambiguous', reference?: string, details?: string}, error_category?: 'rejected'|'validation'|'timeout'|'connection'|'executor'}  $input
     * @return array<string, mixed>
     */
    public function report(User $user, array $input): array
    {
        $input = Validator::validate($input, [
            'attempt_id' => ['required', 'uuid'], 'event_key' => ['required', 'string', 'max:128'],
            'outcome' => ['required', 'in:succeeded,failed,unknown'],
            'occurred_at' => ['required', 'string', 'date', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D'],
            'evidence' => ['required', 'array:kind,reference,details'], 'evidence.kind' => ['required', 'in:submission_receipt,not_submitted,ambiguous'],
            'evidence.reference' => ['sometimes', 'required', 'string', 'max:255'], 'evidence.details' => ['sometimes', 'required', 'string', 'max:2000'],
            'error_category' => ['sometimes', 'required', 'in:rejected,validation,timeout,connection,executor'],
        ]);
        $input['occurred_at'] = CarbonImmutable::parse($input['occurred_at'])->utc()->toISOString();
        $hash = $this->hash($input);

        return DB::transaction(function () use ($user, $input, $hash): array {
            $integration = $this->lockActor($user);
            $attempt = $this->owned($user, $integration, $input['attempt_id']);
            Application::query()->whereKey($attempt->application_id)->lockForUpdate()->firstOrFail();
            $attempt = ApplicationSubmissionAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $event = $attempt->events()->where('event_key', $input['event_key'])->first();
            if ($event !== null) {
                $this->assertHash($event->request_hash, $hash);

                return $this->present($attempt);
            }
            if ($attempt->state !== State::Sending || $attempt->result_due_at->lte(now())) {
                throw ToolException::conflict('O resultado exige reconciliação ou a tentativa já foi resolvida.');
            }
            $occurredAt = CarbonImmutable::parse($input['occurred_at'])->utc();
            if ($occurredAt->lt($attempt->consumed_at) || $occurredAt->gt(now()->addMinutes(5))) {
                throw ToolException::precondition('A ocorrência deve ser posterior ao consumo e não pode estar no futuro.');
            }
            $this->assertEvidence($input);
            $state = State::from($input['outcome']);
            $terminal = in_array($state, [State::Succeeded, State::Failed], true);
            $attempt->update(['state' => $state, 'revision' => $attempt->revision + 1,
                'active_application_id' => $terminal ? null : $attempt->application_id, 'resolved_at' => $terminal ? now() : null]);
            $this->event($attempt, $input['event_key'], 'reported', $hash, $user, $integration,
                ['outcome' => $input['outcome'], 'evidence' => $input['evidence'], 'error_category' => $input['error_category'] ?? null], $occurredAt);

            return $this->present($attempt);
        }, attempts: 5);
    }

    /** @param array{outcome: 'succeeded'|'failed'|'unknown', evidence: array{kind: 'submission_receipt'|'not_submitted'|'ambiguous', reference?: string, details?: string}, error_category?: string} $input */
    private function assertEvidence(array $input): void
    {
        $kind = $input['evidence']['kind'];
        if (($input['outcome'] === 'succeeded' && ($kind !== 'submission_receipt' || empty($input['evidence']['reference'])))
            || ($input['outcome'] === 'failed' && ($kind !== 'not_submitted' || empty($input['evidence']['reference'])
                || in_array($input['error_category'] ?? null, ['timeout', 'connection'], true)))) {
            throw ToolException::precondition('Resultado definitivo exige evidência positiva; timeout e conexão ambígua exigem unknown.');
        }
    }

    /**
     * @param  array{attempt_id: string, expected_revision: int, event_key: string, outcome: 'succeeded'|'failed'|'unknown', evidence: array{kind: 'submission_receipt'|'not_submitted'|'ambiguous', reference?: string, details?: string}, executor_stopped?: bool}  $input
     * @return array<string, mixed>
     */
    public function reconcile(User $user, array $input): array
    {
        return $this->reconcileAuthenticated($user, $input, ownerSession: false);
    }

    /**
     * @param  array{attempt_id: string, expected_revision: int, event_key: string, outcome: 'succeeded'|'failed'|'unknown', evidence: array{kind: 'submission_receipt'|'not_submitted'|'ambiguous', reference?: string, details?: string}, executor_stopped?: bool}  $input
     * @return array<string, mixed>
     */
    public function reconcileForOwner(User $user, array $input): array
    {
        return $this->reconcileAuthenticated($user, $input, ownerSession: true);
    }

    /**
     * @param  array{attempt_id: string, expected_revision: int, event_key: string, outcome: 'succeeded'|'failed'|'unknown', evidence: array{kind: 'submission_receipt'|'not_submitted'|'ambiguous', reference?: string, details?: string}, executor_stopped?: bool}  $input
     * @return array<string, mixed>
     */
    private function reconcileAuthenticated(User $user, array $input, bool $ownerSession): array
    {
        $input = Validator::validate($input, [
            'attempt_id' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:1'], 'event_key' => ['required', 'string', 'max:128'],
            'outcome' => ['required', 'in:succeeded,failed,unknown'], 'executor_stopped' => ['sometimes', 'boolean:strict'],
            'evidence' => ['required', 'array:kind,reference,details'], 'evidence.kind' => ['required', 'in:submission_receipt,not_submitted,ambiguous'],
            'evidence.reference' => ['sometimes', 'required', 'string', 'max:255'], 'evidence.details' => ['sometimes', 'required', 'string', 'max:2000'],
        ]);
        $hash = $this->hash($input);

        return DB::transaction(function () use ($user, $input, $hash, $ownerSession): array {
            if ($ownerSession) {
                $currentUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                if (app()->bound(AgentIntegrationContext::class) || auth('web')->id() !== $user->id || $currentUser->disabled_at !== null) {
                    throw new AuthorizationException('A reconciliação humana exige a sessão do proprietário no painel.');
                }
                $attempt = ApplicationSubmissionAttempt::query()->where('user_id', $user->id)->findOrFail($input['attempt_id']);
                AgentIntegration::query()->where('user_id', $user->id)->whereKey($attempt->integration_id)->lockForUpdate()->firstOrFail();
                $integration = null;
            } else {
                $integration = $this->lockActor($user);
                $attempt = $this->owned($user, $integration, $input['attempt_id']);
            }
            Application::query()->whereKey($attempt->application_id)->lockForUpdate()->firstOrFail();
            $attempt = ApplicationSubmissionAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $event = $attempt->events()->where('event_key', $input['event_key'])->first();
            if ($event !== null) {
                $this->assertHash($event->request_hash, $hash);

                return $this->present($attempt);
            }
            if ($attempt->revision !== $input['expected_revision']) {
                throw ToolException::conflict('A revisão da tentativa mudou.');
            }
            if ($this->present($attempt)['state'] !== State::Unknown->value) {
                throw ToolException::conflict('Somente resultado incerto pode ser reconciliado.');
            }
            $this->assertEvidence($input);
            if ($input['outcome'] === 'failed' && ! ($input['executor_stopped'] ?? false)) {
                throw ToolException::precondition('Confirme o encerramento do executor antigo além da prova de ausência de envio.');
            }
            $terminal = $input['outcome'] !== 'unknown';
            $attempt->update(['state' => State::from($input['outcome']), 'revision' => $attempt->revision + 1,
                'active_application_id' => $terminal ? null : $attempt->application_id, 'resolved_at' => $terminal ? now() : null]);
            $this->event($attempt, $input['event_key'], 'reconciled', $hash, $user, $integration,
                ['outcome' => $input['outcome'], 'evidence' => $input['evidence'], 'executor_stopped' => $input['executor_stopped'] ?? false]);

            return $this->present($attempt);
        }, attempts: 5);
    }

    /** @return array<string, mixed> */
    public function get(User $user, string $id, int $limit = 20, int $offset = 0): array
    {
        app(ApplicationDocumentAccess::class)->authorize($user, scope: AgentIntegrationScope::ApplicationsRead);
        $attempt = ApplicationSubmissionAttempt::query()->where('user_id', $user->id)->findOrFail($id);
        $events = $attempt->events()->orderBy('received_at')->orderBy('id');
        $total = $events->count();

        return [...$this->present($attempt), 'events' => $events->offset($offset)->limit($limit)->get()->map(function (ApplicationSubmissionEvent $event): array {
            $record = $event->evidence;

            return ['event_id' => $event->id, 'event_key' => $event->event_key, 'kind' => $event->kind,
                'evidence' => Arr::only($record['evidence'] ?? [], ['kind', 'reference', 'details']),
                'outcome' => $record['outcome'] ?? null, 'error_category' => $record['error_category'] ?? null,
                'executor_stopped' => $record['executor_stopped'] ?? null,
                'occurred_at' => $event->occurred_at->toISOString(), 'received_at' => $event->received_at->toISOString()];
        })->all(), 'total' => $total, 'next_offset' => $offset + $limit < $total ? $offset + $limit : null];
    }

    /** @return array<string, mixed>|null */
    public function summary(User $user, int $applicationId): ?array
    {
        $attempt = ApplicationSubmissionAttempt::query()->where('user_id', $user->id)->where('application_id', $applicationId)
            ->orderByDesc('reserved_at')->orderByDesc('id')->first();

        return $attempt === null ? null : $this->present($attempt);
    }

    private function owned(User $user, AgentIntegration $integration, string $id): ApplicationSubmissionAttempt
    {
        return ApplicationSubmissionAttempt::query()->where('user_id', $user->id)->where('integration_id', $integration->id)->findOrFail($id);
    }

    private function lockApproval(User $user, string $id, AgentIntegration $integration): ApplicationApproval
    {
        $approval = ApplicationApproval::query()->where('user_id', $user->id)->where('integration_id', $integration->id)->lockForUpdate()->findOrFail($id);
        TelegramIdentityBinding::query()->whereKey($approval->binding_id)->lockForUpdate()->firstOrFail();
        $preparation = ApplicationPreparation::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($approval->preparation_id);
        $posting = $preparation->application->jobPosting()->lockForUpdate()->firstOrFail();
        $user->professionalProfile()->lockForUpdate()->first();
        $user->resumes()->orderBy('id')->lockForUpdate()->get();
        $posting->resumeAdaptations()->orderBy('id')->lockForUpdate()->get();
        $posting->items()->orderBy('id')->lockForUpdate()->get();
        $posting->applicationMethods()->orderBy('id')->lockForUpdate()->get();
        $preparation->artifacts()->orderBy('id')->lockForUpdate()->get();
        Application::query()->whereKey($preparation->application_id)->lockForUpdate()->firstOrFail();

        return $approval;
    }

    private function assertApproval(User $user, ApplicationApproval $approval, string $fingerprint): void
    {
        if (! hash_equals($approval->fingerprint, $fingerprint)) {
            throw ToolException::conflict('O fingerprint não corresponde à aprovação.');
        }
        if (app(ApplicationApprovalService::class)->invalidReasons($user, $approval) !== []) {
            throw ToolException::precondition('A aprovação ou o material não está vigente.');
        }
    }

    /** @return array<string, mixed> */
    public function present(ApplicationSubmissionAttempt $attempt): array
    {
        $state = $attempt->state;
        if ($state === State::Sending && $attempt->result_due_at->lte(now())) {
            $state = State::Unknown;
        } elseif ($state === State::Reserved && $attempt->reservation_expires_at->lte(now())) {
            $state = State::Expired;
        }

        return ['attempt_id' => $attempt->id, 'application_id' => $attempt->application_id, 'approval_id' => $attempt->approval_id,
            'preparation_id' => $attempt->preparation_id, 'fingerprint' => $attempt->fingerprint, 'state' => $state->value,
            'revision' => $attempt->revision, 'authorizes_submission' => false, 'reserved_at' => $attempt->reserved_at->toISOString(),
            'blocked' => in_array($state, [State::Reserved, State::Sending, State::Unknown, State::Succeeded], true),
            'blockers' => match ($state) {
                State::Reserved => ['reservation_requires_consumption'], State::Sending => ['execution_in_progress'],
                State::Unknown => ['reconciliation_required'], State::Succeeded => ['submission_already_succeeded'],
                State::Failed => ['new_approval_after_resolution_required'], default => [],
            },
            'reservation_expires_at' => $attempt->reservation_expires_at->toISOString(), 'consumed_at' => $attempt->consumed_at?->toISOString(),
            'execute_before' => $attempt->execute_before?->toISOString(), 'result_due_at' => $attempt->result_due_at?->toISOString(),
            'resolved_at' => $attempt->resolved_at?->toISOString()];
    }

    /** @param array{outcome?: 'succeeded'|'failed'|'unknown', evidence?: array{kind: 'submission_receipt'|'not_submitted'|'ambiguous', reference?: string, details?: string}, error_category?: string|null, executor_stopped?: bool} $evidence */
    private function event(ApplicationSubmissionAttempt $attempt, string $key, string $kind, string $hash, User $user, ?AgentIntegration $integration, array $evidence = [], ?CarbonImmutable $occurredAt = null): void
    {
        $attempt->events()->create(['event_key' => $key, 'kind' => $kind, 'request_hash' => $hash, 'evidence' => $evidence,
            'actor' => $integration === null ? ['source' => 'filament', 'user_id' => $user->id]
                : ['source' => 'mcp', 'user_id' => $user->id, 'integration_id' => $integration->id],
            'occurred_at' => $occurredAt ?? now(), 'received_at' => now()]);
    }

    /** @param array{approval_id?: string, expected_fingerprint?: string, idempotency_key?: string, attempt_id?: string, expected_revision?: int, event_key?: string, outcome?: 'succeeded'|'failed'|'unknown', occurred_at?: string, evidence?: array{kind: 'submission_receipt'|'not_submitted'|'ambiguous', reference?: string, details?: string}, error_category?: string, executor_stopped?: bool, transition?: 'expired'} $input */
    private function hash(array $input): string
    {
        if (isset($input['evidence'])) {
            ksort($input['evidence']);
        }
        ksort($input);

        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    }

    private function assertHash(string $expected, string $actual): void
    {
        if (! hash_equals($expected, $actual)) {
            throw ToolException::conflict('A chave já foi usada para outro conteúdo.');
        }
    }
}
