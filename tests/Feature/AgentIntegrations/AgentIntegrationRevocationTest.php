<?php

use App\Enums\AgentIntegrationScope;
use App\Models\User;
use App\Services\AgentIntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;

uses(RefreshDatabase::class);

it('revokes one integration tokens without affecting other integrations of the same account', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $firstClient = Helpers::client('Hermes primeira');
    $secondClient = Helpers::client('Hermes segunda');
    $foreignClient = Helpers::client('Outra conta');

    $first = Helpers::integration($user, $firstClient);
    $second = Helpers::integration($user, $secondClient);
    Helpers::integration($otherUser, $foreignClient);

    $firstToken = Helpers::accessToken($user, $firstClient);
    $secondToken = Helpers::accessToken($user, $secondClient);
    $foreignToken = Helpers::accessToken($otherUser, $foreignClient);

    app(AgentIntegrationService::class)->revoke($first);

    $this->withToken($firstToken)
        ->postJson('/mcp', Helpers::toolCall('get_ai_operation', ['id' => 1]))
        ->assertStatus(401);

    $this->withToken($secondToken)
        ->postJson('/mcp', Helpers::toolCall('get_ai_operation', ['id' => 1]))
        ->assertOk();

    $this->withToken($foreignToken)
        ->postJson('/mcp', Helpers::toolCall('get_ai_operation', ['id' => 1]))
        ->assertOk();

    expect($first->refresh()->revoked_at)->not->toBeNull()
        ->and($second->refresh()->revoked_at)->toBeNull()
        ->and(Token::query()->where('client_id', $firstClient->id)->where('revoked', false)->exists())->toBeFalse()
        ->and(Token::query()->where('client_id', $secondClient->id)->where('revoked', false)->exists())->toBeTrue();
});

it('revokes the refresh tokens of the revoked integration only', function () {
    $user = User::factory()->create();
    $firstClient = Helpers::client('Hermes primeira');
    $secondClient = Helpers::client('Hermes segunda');
    $first = Helpers::integration($user, $firstClient);
    Helpers::integration($user, $secondClient);

    $firstToken = Helpers::accessToken($user, $firstClient);
    $secondToken = Helpers::accessToken($user, $secondClient);

    $firstTokenId = Token::query()->where('client_id', $firstClient->id)->value('id');
    $secondTokenId = Token::query()->where('client_id', $secondClient->id)->value('id');

    DB::table('oauth_refresh_tokens')->insert([
        ['id' => 'refresh-first', 'access_token_id' => $firstTokenId, 'revoked' => false, 'expires_at' => now()->addDay()],
        ['id' => 'refresh-second', 'access_token_id' => $secondTokenId, 'revoked' => false, 'expires_at' => now()->addDay()],
    ]);

    app(AgentIntegrationService::class)->revoke($first);

    expect(RefreshToken::query()->whereKey('refresh-first')->value('revoked'))->toBeTrue()
        ->and(RefreshToken::query()->whereKey('refresh-second')->value('revoked'))->toBeFalse()
        ->and($firstToken)->not->toBeEmpty()
        ->and($secondToken)->not->toBeEmpty();
});

it('grants every internal capability explicitly at integration registration', function () {
    $user = User::factory()->create();
    $client = Helpers::client();

    $integration = app(AgentIntegrationService::class)->register($user, (string) $client->id, $client->name);

    expect($integration->scopes)->toEqualCanonicalizing(AgentIntegrationScope::values())
        ->and(AgentIntegrationScope::labels())->toHaveCount(count(AgentIntegrationScope::cases()))
        ->and(AgentIntegrationScope::labels())->toHaveKeys(AgentIntegrationScope::values());
});
