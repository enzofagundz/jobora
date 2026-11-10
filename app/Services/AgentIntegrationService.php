<?php

namespace App\Services;

use App\Enums\AgentIntegrationScope;
use App\Models\AgentIntegration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

class AgentIntegrationService
{
    public function register(User $user, string $clientId, string $name): AgentIntegration
    {
        return AgentIntegration::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'oauth_client_id' => $clientId,
            ],
            [
                'name' => $name,
                'scopes' => AgentIntegrationScope::defaults(),
            ],
        );
    }

    public function for(User $user, string $clientId): ?AgentIntegration
    {
        return AgentIntegration::query()
            ->where('user_id', $user->id)
            ->where('oauth_client_id', $clientId)
            ->first();
    }

    public function revoke(AgentIntegration $integration): void
    {
        DB::transaction(function () use ($integration): void {
            $integration->forceFill(['revoked_at' => now()])->save();

            $tokens = Token::query()
                ->where('user_id', $integration->user_id)
                ->where('client_id', $integration->oauth_client_id)
                ->get();

            foreach ($tokens as $token) {
                $token->revoke();
            }

            RefreshToken::query()
                ->whereIn('access_token_id', $tokens->modelKeys())
                ->update(['revoked' => true]);
        });
    }

    public function handleAccessTokenCreated(AccessTokenCreated $event): void
    {
        if ($event->userId === null) {
            return;
        }

        $user = User::query()->find($event->userId);

        if ($user === null) {
            return;
        }

        $client = Passport::client()->newQuery()->find($event->clientId);

        $this->register($user, $event->clientId, $client?->name ?? 'Integração');
    }
}
