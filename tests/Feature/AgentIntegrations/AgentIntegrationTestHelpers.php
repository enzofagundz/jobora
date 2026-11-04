<?php

namespace Tests\Feature\AgentIntegrations;

use App\Enums\AgentIntegrationScope;
use App\Models\AgentIntegration;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;

final class AgentIntegrationTestHelpers
{
    public static function ensureKeys(): void
    {
        if (! is_file(storage_path('oauth-private.key')) || ! is_file(storage_path('oauth-public.key'))) {
            Artisan::call('passport:keys', ['--force' => true]);
            Passport::loadKeysFrom(storage_path());
        }
    }

    public static function client(string $name = 'Hermes'): Client
    {
        return app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            name: $name,
            redirectUris: ['http://127.0.0.1:9988/callback'],
            confidential: false,
        );
    }

    public static function integration(
        User $user,
        Client $client,
        ?array $scopes = null,
    ): AgentIntegration {
        return AgentIntegration::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'oauth_client_id' => (string) $client->id,
            ],
            [
                'name' => $client->name,
                'scopes' => $scopes ?? AgentIntegrationScope::defaults(),
            ],
        );
    }

    /**
     * @param  array<int, string>  $scopes
     */
    public static function accessToken(
        User $user,
        Client $client,
        array $scopes = ['mcp:use'],
        ?\DateTimeImmutable $expiresAt = null,
        bool $revoked = false,
    ): string {
        self::ensureKeys();

        $issuer = config('app.url');
        $issuedAt = new \DateTimeImmutable('-1 minute');
        $expiresAt ??= new \DateTimeImmutable('+1 hour');

        $configuration = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::file(storage_path('oauth-private.key')),
            InMemory::file(storage_path('oauth-public.key')),
        );

        $jti = Str::random(40);

        $token = $configuration->builder()
            ->issuedBy($issuer)
            ->permittedFor((string) $client->id)
            ->identifiedBy($jti)
            ->relatedTo((string) $user->id)
            ->withClaim('scopes', $scopes)
            ->issuedAt($issuedAt)
            ->canOnlyBeUsedAfter($issuedAt)
            ->expiresAt($expiresAt)
            ->getToken($configuration->signer(), $configuration->signingKey());

        DB::table('oauth_access_tokens')->insert([
            'id' => $jti,
            'user_id' => $user->id,
            'client_id' => $client->id,
            'name' => $client->name,
            'scopes' => json_encode($scopes),
            'revoked' => $revoked,
            'created_at' => now(),
            'updated_at' => now(),
            'expires_at' => \DateTime::createFromImmutable($expiresAt)->format('Y-m-d H:i:s'),
        ]);

        return $token->toString();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function toolCall(string $name, array $arguments = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => $arguments,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function decodeToolResult(string $body): array
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return [];
        }

        if (isset($decoded['result']['structuredContent'])) {
            return $decoded['result']['structuredContent'];
        }

        $text = $decoded['result']['content'][0]['text'] ?? null;

        return is_string($text) ? (json_decode($text, true) ?: []) : $decoded;
    }
}
