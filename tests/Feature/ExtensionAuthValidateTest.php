<?php

use App\Models\User;
use App\Services\CaptureTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;

uses(RefreshDatabase::class);

it('returns only the authenticated owner id and valid flag for a capture token', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $token = app(CaptureTokenService::class)->issueToken($owner);

    $this->getJson('/api/extension/auth/validate?user_id='.$other->id, [
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertOk()
        ->assertExactJson([
            'valid' => true,
            'owner_user_id' => $owner->id,
        ]);
});

it('returns each capture token owner without leaking the other user', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $service = app(CaptureTokenService::class);
    $firstToken = $service->issueToken($first);
    $secondToken = $service->issueToken($second);

    $this->getJson('/api/extension/auth/validate', [
        'Authorization' => 'Bearer '.$firstToken,
    ])->assertOk()->assertExactJson([
        'valid' => true,
        'owner_user_id' => $first->id,
    ]);

    $this->getJson('/api/extension/auth/validate', [
        'Authorization' => 'Bearer '.$secondToken,
    ])->assertOk()->assertExactJson([
        'valid' => true,
        'owner_user_id' => $second->id,
    ]);
});

it('rejects invalid capture tokens without exposing an owner id', function () {
    $this->getJson('/api/extension/auth/validate', [
        'Authorization' => 'Bearer token-invalido',
    ])
        ->assertStatus(401)
        ->assertExactJson(['message' => 'Token inválido.']);
});

it('does not accept an oauth token as capture authentication', function () {
    $user = User::factory()->create();
    $client = Helpers::client();
    $oauthToken = Helpers::accessToken($user, $client);

    $this->getJson('/api/extension/auth/validate', [
        'Authorization' => 'Bearer '.$oauthToken,
    ])
        ->assertStatus(401)
        ->assertExactJson(['message' => 'Token inválido.']);
});
