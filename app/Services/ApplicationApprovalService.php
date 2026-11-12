<?php

namespace App\Services;

use App\Enums\AgentIntegrationScope;
use App\Mcp\Support\ToolException;
use App\Models\AgentIntegration;
use App\Models\ApplicationApproval;
use App\Models\ApplicationPreparation;
use App\Models\TelegramIdentityBinding;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ApplicationApprovalService
{
    /** @param array<string, mixed> $input @param array{source?: string, integration_id?: int} $actorMetadata @return array<string, mixed> */
    public function record(User $user, array $input, array $actorMetadata): array
    {
        $input = Validator::validate(['input' => $input], [
            'input' => ['required', 'array:preparation_id,expected_fingerprint,telegram_user_id,chat_id,message_id,approved_at,expires_at'],
            'input.preparation_id' => ['required', 'uuid'], 'input.expected_fingerprint' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
            'input.telegram_user_id' => TelegramIdentityService::telegramIdRules(), 'input.chat_id' => TelegramIdentityService::telegramIdRules(), 'input.message_id' => TelegramIdentityService::telegramIdRules(),
            'input.approved_at' => ['required', 'string', 'date', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D'],
            'input.expires_at' => ['required', 'string', 'date', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D'],
        ])['input'];
        $input['approved_at'] = CarbonImmutable::parse($input['approved_at'])->utc()->toISOString();
        $input['expires_at'] = CarbonImmutable::parse($input['expires_at'])->utc()->toISOString();
        $integration = app(TelegramIdentityService::class)->authorizeRemote($user, $actorMetadata);
        $messageKey = hash('sha256', json_encode([$integration->id, $input['chat_id'], $input['message_id']], JSON_THROW_ON_ERROR));
        $requestHash = hash('sha256', json_encode(array_map(fn (string $key): mixed => $input[$key], [
            'preparation_id', 'expected_fingerprint', 'telegram_user_id', 'chat_id', 'message_id', 'approved_at', 'expires_at',
        ]), JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($user, $input, $actorMetadata, $integration, $messageKey, $requestHash): array {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                AgentIntegration::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($integration->id);
                app(TelegramIdentityService::class)->authorizeRemote($user, $actorMetadata);
                $existing = ApplicationApproval::query()->where('message_key', $messageKey)->lockForUpdate()->first();
                if ($existing !== null) {
                    if ($existing->user_id !== $user->id || ! hash_equals($existing->request_hash, $requestHash)) {
                        throw ToolException::conflict('A mensagem já foi usada para outro conteúdo de aprovação.');
                    }

                    return $this->present($user, $existing);
                }
                $binding = TelegramIdentityBinding::query()->where('active_user_id', $user->id)->where('integration_id', $integration->id)->lockForUpdate()->first();
                if ($binding === null || $binding->revoked_at !== null || ($binding->telegram_identity['chat_type'] ?? null) !== 'private'
                    || $binding->telegram_identity['telegram_user_id'] !== $input['telegram_user_id'] || $binding->telegram_identity['chat_id'] !== $input['chat_id']) {
                    throw ToolException::precondition('Confirme no painel um vínculo Telegram ativo para esta integração e conversa privada.');
                }
                $preparation = ApplicationPreparation::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($input['preparation_id']);
                $posting = $preparation->application->jobPosting()->lockForUpdate()->firstOrFail();
                $user->professionalProfile()->lockForUpdate()->first();
                $user->resumes()->orderBy('id')->lockForUpdate()->get();
                $posting->resumeAdaptations()->orderBy('id')->lockForUpdate()->get();
                $posting->items()->orderBy('id')->lockForUpdate()->get();
                $posting->applicationMethods()->orderBy('id')->lockForUpdate()->get();
                $artifacts = $preparation->artifacts()->orderBy('id')->lockForUpdate()->get();
                if (! hash_equals($preparation->fingerprint, $input['expected_fingerprint'])) {
                    throw ToolException::conflict('O fingerprint não corresponde ao material congelado.');
                }
                if ($preparation->state !== 'awaiting_approval' || $preparation->pending !== []
                    || ! app(ApplicationPreparationService::class)->isCurrentForApproval($user, $preparation)) {
                    throw ToolException::precondition('A preparação não está atual e disponível para aprovação.');
                }
                if ($artifacts->isEmpty() || $artifacts->contains(fn ($artifact): bool => $artifact->revoked_at !== null || $artifact->expires_at->lte(now()))) {
                    throw ToolException::precondition('Os artefatos expiraram ou foram revogados.');
                }
                $approvedAt = CarbonImmutable::parse($input['approved_at'])->utc();
                $expiresAt = CarbonImmutable::parse($input['expires_at'])->utc();
                $linkMessage = $binding->telegram_identity['message_id'];
                if ($approvedAt->lt($binding->confirmed_at) || $approvedAt->lt($preparation->created_at)
                    || strlen($input['message_id']) < strlen($linkMessage)
                    || (strlen($input['message_id']) === strlen($linkMessage) && strcmp($input['message_id'], $linkMessage) <= 0)) {
                    throw ToolException::precondition('A mensagem de consentimento deve ser posterior ao vínculo confirmado e ao material preparado.');
                }
                $limit = $approvedAt->addHours(max(1, (int) config('application-approvals.ttl_hours', 24)))->min($artifacts->min('expires_at'));
                if ($approvedAt->gt(now()->addMinutes(5)) || $expiresAt->lte(now()) || $expiresAt->lte($approvedAt) || $expiresAt->gt($limit)) {
                    throw ToolException::precondition('Prazo de aprovação inválido; respeite o TTL e a validade dos artefatos.');
                }
                $approval = ApplicationApproval::query()->create([
                    'user_id' => $user->id, 'integration_id' => $integration->id, 'preparation_id' => $preparation->id, 'binding_id' => $binding->id,
                    'fingerprint' => $preparation->fingerprint, 'message_key' => $messageKey, 'request_hash' => $requestHash,
                    'telegram_identity' => ['telegram_user_id' => $input['telegram_user_id'], 'chat_id' => $input['chat_id'], 'message_id' => $input['message_id'], 'chat_type' => 'private'],
                    'recorded_actor' => [...$actorMetadata, 'user_id' => $user->id],
                    'approved_at' => $approvedAt, 'received_at' => now(), 'expires_at' => $expiresAt,
                ]);

                return $this->present($user, $approval);
            });
        } catch (UniqueConstraintViolationException) {
            $existing = ApplicationApproval::query()->where('user_id', $user->id)->where('message_key', $messageKey)->first();
            if ($existing !== null && hash_equals($existing->request_hash, $requestHash)) {
                return $this->present($user, $existing);
            }
            throw ToolException::conflict('A mensagem já foi registrada.');
        }
    }

    /** @return array<string, mixed> */
    public function forPreparation(User $user, string $preparationId): array
    {
        app(ApplicationDocumentAccess::class)->authorize($user, scope: AgentIntegrationScope::ApprovalsRead);
        ApplicationPreparation::query()->where('user_id', $user->id)->findOrFail($preparationId);

        return ['preparation_id' => $preparationId, 'approvals' => ApplicationApproval::query()->where('user_id', $user->id)->where('preparation_id', $preparationId)
            ->orderBy('received_at')->orderBy('id')->get()->map(fn (ApplicationApproval $approval): array => $this->present($user, $approval))->all(),
            'trust_statement' => TelegramIdentityService::TRUST_STATEMENT, 'authorizes_submission' => false];
    }

    /** @param array{source?: string, integration_id?: int} $actorMetadata @return array<string, mixed> */
    public function revoke(User $user, string $approvalId, array $actorMetadata = []): array
    {
        app(ApplicationDocumentAccess::class)->authorize($user, $actorMetadata, AgentIntegrationScope::ApprovalsWrite);

        return DB::transaction(function () use ($user, $approvalId, $actorMetadata): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            app(ApplicationDocumentAccess::class)->authorize($user, $actorMetadata, AgentIntegrationScope::ApprovalsWrite);
            $approval = ApplicationApproval::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($approvalId);
            if ($approval->revoked_at === null) {
                $approval->forceFill(['revoked_at' => now(), 'revoked_actor' => [...$actorMetadata, 'user_id' => $user->id]])->save();
            }

            return $this->present($user, $approval);
        });
    }

    /** @return list<string> */
    public function invalidReasons(User $user, ApplicationApproval $approval): array
    {
        $reasons = [];
        $currentUser = $user->fresh();
        if ($user->id !== $approval->user_id || $currentUser === null || $currentUser->disabled_at !== null) {
            $reasons[] = 'account_inactive';
        }
        if ($approval->revoked_at !== null) {
            $reasons[] = 'approval_revoked';
        }
        if ($approval->expires_at->lte(now())) {
            $reasons[] = 'approval_expired';
        }
        $integration = AgentIntegration::query()->where('user_id', $user->id)->find($approval->integration_id);
        if (! $integration?->can(AgentIntegrationScope::ApprovalsWrite)) {
            $reasons[] = 'integration_inactive';
        }
        $binding = TelegramIdentityBinding::query()->where('user_id', $user->id)->find($approval->binding_id);
        if ($binding === null || $binding->revoked_at !== null || $binding->active_user_id !== $user->id
            || $binding->integration_id !== $approval->integration_id || $binding->telegram_identity['telegram_user_id'] !== $approval->telegram_identity['telegram_user_id']
            || $binding->telegram_identity['chat_id'] !== $approval->telegram_identity['chat_id']) {
            $reasons[] = 'binding_inactive';
        }
        $preparation = ApplicationPreparation::query()->where('user_id', $user->id)->find($approval->preparation_id);
        if ($preparation === null || ! hash_equals($preparation->fingerprint, $approval->fingerprint)
            || $preparation->state !== 'awaiting_approval' || $preparation->pending !== []
            || ! app(ApplicationPreparationService::class)->isCurrentForApproval($user, $preparation)) {
            $reasons[] = 'preparation_stale';
        }
        $artifacts = $preparation?->artifacts()->get();
        if ($artifacts === null || $artifacts->isEmpty() || $artifacts->contains(fn ($artifact): bool => $artifact->revoked_at !== null
            || $artifact->expires_at->lte(now()) || ! Storage::disk('local')->exists($artifact->path))) {
            $reasons[] = 'artifacts_unavailable';
        }
        if (($preparation?->snapshot['ats_consent'] ?? false) && ! Gate::forUser($currentUser ?? $user)->allows('useAtsOptimization')) {
            $reasons[] = 'ats_permission_revoked';
        }

        return $reasons;
    }

    /** @return array<string, mixed> */
    private function present(User $user, ApplicationApproval $approval): array
    {
        $reasons = $this->invalidReasons($user, $approval);
        $status = $approval->revoked_at !== null ? 'revoked' : ($approval->expires_at->lte(now()) ? 'expired' : ($reasons === [] ? 'active' : 'invalid'));

        return ['id' => $approval->id, 'preparation_id' => $approval->preparation_id, 'binding_id' => $approval->binding_id, 'integration_id' => $approval->integration_id,
            'fingerprint' => $approval->fingerprint, ...$approval->telegram_identity, 'approved_at' => $approval->approved_at->toISOString(),
            'received_at' => $approval->received_at->toISOString(), 'expires_at' => $approval->expires_at->toISOString(), 'revoked_at' => $approval->revoked_at?->toISOString(),
            'status' => $status, 'reasons' => $reasons, 'trust_statement' => TelegramIdentityService::TRUST_STATEMENT, 'authorizes_submission' => false];
    }
}
