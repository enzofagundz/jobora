<?php

namespace App\Services;

use App\Enums\AgentIntegrationScope;
use App\Mcp\Support\AgentIntegrationContext;
use App\Mcp\Support\ToolException;
use App\Models\AgentIntegration;
use App\Models\TelegramIdentityBinding;
use App\Models\TelegramLinkChallenge;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\AccessToken;

class TelegramIdentityService
{
    public const TRUST_STATEMENT = 'O Jobora confia no atestado da integração Hermes autorizada. Não existe prova independente de uma conversa Telegram nem proteção contra um Hermes comprometido. Este registro não autoriza envio.';

    /** @return array<string, mixed> */
    public function startChallenge(User $user, int $integrationId): array
    {
        $this->panel($user);
        $code = bin2hex(random_bytes(32));
        $challenge = DB::transaction(function () use ($user, $integrationId, $code): TelegramLinkChallenge {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->panel($user);
            $integration = AgentIntegration::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($integrationId);
            if (! $integration->can(AgentIntegrationScope::ApprovalsWrite)) {
                throw new AuthorizationException('Escolha uma integração ativa com approvals:write.');
            }

            return TelegramLinkChallenge::query()->create([
                'user_id' => $user->id, 'integration_id' => $integration->id, 'challenge_hash' => hash('sha256', $code),
                'expires_at' => now()->addMinutes(max(1, (int) config('application-approvals.challenge_ttl_minutes', 10))),
            ]);
        });

        return ['challenge_id' => $challenge->id, 'challenge' => $code, 'expires_at' => $challenge->expires_at->toISOString(),
            'integration_id' => $challenge->integration_id, 'trust_statement' => self::TRUST_STATEMENT];
    }

    /** @param array<string, mixed> $input @param array{source?: string, integration_id?: int} $actorMetadata @return array<string, mixed> */
    public function submitChallenge(User $user, array $input, array $actorMetadata): array
    {
        $input = Validator::validate(['input' => $input], [
            'input' => ['required', 'array:challenge,telegram_user_id,chat_id,message_id,chat_type'],
            'input.challenge' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
            'input.telegram_user_id' => self::telegramIdRules(), 'input.chat_id' => self::telegramIdRules(), 'input.message_id' => self::telegramIdRules(),
            'input.chat_type' => ['required', 'in:private'],
        ])['input'];
        $integration = $this->authorizeRemote($user, $actorMetadata);

        return DB::transaction(function () use ($user, $input, $actorMetadata, $integration): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            AgentIntegration::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($integration->id);
            $this->authorizeRemote($user, $actorMetadata);
            $challenge = TelegramLinkChallenge::query()->where('user_id', $user->id)->where('integration_id', $integration->id)
                ->where('challenge_hash', hash('sha256', $input['challenge']))->lockForUpdate()->firstOrFail();
            if ($challenge->consumed_at !== null) {
                throw ToolException::conflict('O desafio já foi consumido.');
            }
            if ($challenge->expires_at->lte(now())) {
                throw ToolException::precondition('O desafio expirou.');
            }
            unset($input['challenge']);
            $challenge->forceFill(['telegram_identity' => $input, 'consumed_at' => now()])->save();

            return $this->presentChallenge($challenge);
        });
    }

    /** @return list<array<string, mixed>> */
    public function pendingChallenges(User $user): array
    {
        $this->panel($user);

        return TelegramLinkChallenge::query()->where('user_id', $user->id)->whereNotNull('consumed_at')->whereNull('confirmed_at')
            ->where('expires_at', '>', now())->orderByDesc('created_at')->get()->map(fn (TelegramLinkChallenge $challenge): array => $this->presentChallenge($challenge))->all();
    }

    /** @return array<string, mixed> */
    public function confirmChallenge(User $user, string $challengeId, string $password): array
    {
        $this->panel($user);
        try {
            return DB::transaction(function () use ($user, $challengeId, $password): array {
                $currentUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $this->panel($user);
                if (! Hash::check($password, $currentUser->password)) {
                    throw ValidationException::withMessages(['password' => 'A senha informada está incorreta.']);
                }
                $challenge = TelegramLinkChallenge::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($challengeId);
                $integration = AgentIntegration::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($challenge->integration_id);
                if (! $integration->can(AgentIntegrationScope::ApprovalsWrite)) {
                    throw new AuthorizationException('A integração não está autorizada.');
                }
                if ($challenge->expires_at->lte(now()) || $challenge->consumed_at === null || $challenge->telegram_identity === null) {
                    throw ToolException::precondition('O desafio expirou ou ainda não foi apresentado pelo Hermes.');
                }
                $existing = TelegramIdentityBinding::query()->where('challenge_id', $challenge->id)->first();
                if ($existing !== null) {
                    if ($existing->revoked_at !== null) {
                        throw ToolException::conflict('O vínculo já foi revogado. Inicie outro desafio.');
                    }

                    return $this->presentBinding($existing);
                }
                $identityKey = hash('sha256', $challenge->telegram_identity['telegram_user_id']);
                if (TelegramIdentityBinding::query()->where('active_user_id', $user->id)->orWhere('active_identity_key', $identityKey)->exists()) {
                    throw ToolException::conflict('Já existe um vínculo ativo para o usuário ou a identidade Telegram. Revogue-o explicitamente.');
                }
                $binding = TelegramIdentityBinding::query()->create([
                    'user_id' => $user->id, 'integration_id' => $integration->id, 'challenge_id' => $challenge->id,
                    'telegram_identity' => $challenge->telegram_identity, 'active_user_id' => $user->id, 'active_identity_key' => $identityKey, 'confirmed_at' => now(),
                ]);
                $challenge->forceFill(['confirmed_at' => now()])->save();

                return $this->presentBinding($binding);
            });
        } catch (UniqueConstraintViolationException) {
            throw ToolException::conflict('Outro vínculo já ocupa esta identidade ou usuário.');
        }
    }

    /** @return array<string, mixed>|null */
    public function currentBinding(User $user): ?array
    {
        $this->panel($user);
        $binding = TelegramIdentityBinding::query()->where('active_user_id', $user->id)->first();

        return $binding === null ? null : $this->presentBinding($binding);
    }

    /** @return array<string, mixed> */
    public function revokeBinding(User $user, string $bindingId): array
    {
        $this->panel($user);

        return DB::transaction(function () use ($user, $bindingId): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->panel($user);
            $binding = TelegramIdentityBinding::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($bindingId);
            if ($binding->revoked_at === null) {
                $binding->forceFill(['revoked_at' => now(), 'active_user_id' => null, 'active_identity_key' => null])->save();
            }

            return $this->presentBinding($binding);
        });
    }

    /** @param array{source?: string, integration_id?: int} $actorMetadata */
    public function authorizeRemote(User $user, array $actorMetadata): AgentIntegration
    {
        if (! app()->bound(AgentIntegrationContext::class) || auth('api')->id() !== $user->id || ! $user->currentAccessToken() instanceof AccessToken) {
            throw new AuthorizationException('O atestado Telegram exige transporte MCP remoto autenticado.');
        }
        $context = app(AgentIntegrationContext::class);
        app(ApplicationDocumentAccess::class)->authorize($user, $actorMetadata, AgentIntegrationScope::ApprovalsWrite);
        if ($context->user->id !== $user->id || ($actorMetadata['source'] ?? null) !== 'mcp'
            || ($actorMetadata['integration_id'] ?? null) !== $context->integration->id) {
            throw new AuthorizationException;
        }

        return AgentIntegration::query()->where('user_id', $user->id)->findOrFail($context->integration->id);
    }

    /** @return list<string> */
    public static function telegramIdRules(): array
    {
        return ['required', 'string', 'regex:/\A[1-9][0-9]{0,19}\z/D'];
    }

    private function panel(User $user): void
    {
        $currentUser = $user->fresh();
        if (app()->bound(AgentIntegrationContext::class) || auth('web')->id() !== $user->id || $currentUser === null || $currentUser->disabled_at !== null) {
            throw new AuthorizationException('Esta operação exige a sessão do proprietário no painel.');
        }
    }

    /** @return array<string, mixed> */
    private function presentChallenge(TelegramLinkChallenge $challenge): array
    {
        return ['challenge_id' => $challenge->id, 'integration_id' => $challenge->integration_id, 'status' => 'pending_confirmation',
            ...($challenge->telegram_identity ?? []), 'expires_at' => $challenge->expires_at->toISOString(), 'trust_statement' => self::TRUST_STATEMENT];
    }

    /** @return array<string, mixed> */
    private function presentBinding(TelegramIdentityBinding $binding): array
    {
        $integration = AgentIntegration::query()->where('user_id', $binding->user_id)->find($binding->integration_id);

        return ['id' => $binding->id, 'integration_id' => $binding->integration_id, ...$binding->telegram_identity,
            'status' => $binding->revoked_at !== null ? 'revoked' : ($integration?->can(AgentIntegrationScope::ApprovalsWrite) ? 'active' : 'inactive'),
            'confirmed_at' => $binding->confirmed_at->toISOString(), 'revoked_at' => $binding->revoked_at?->toISOString(), 'trust_statement' => self::TRUST_STATEMENT];
    }
}
