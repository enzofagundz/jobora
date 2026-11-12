<?php

use App\Enums\AgentIntegrationScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;

uses(RefreshDatabase::class);

it('registers only mcp:use as an oauth scope', function () {
    expect(Passport::scopes()->pluck('id')->all())->toBe(['mcp:use'])
        ->and(AgentIntegrationScope::labels())->toHaveCount(28)
        ->and(AgentIntegrationScope::labels())->toHaveKey('applications:submit')
        ->and(AgentIntegrationScope::labels())->toHaveKeys(['approvals:read', 'approvals:write'])
        ->and(AgentIntegrationScope::labels())->toHaveKeys(['matching:read', 'matching:write'])
        ->and(AgentIntegrationScope::labels())->toHaveKeys(['profile:read', 'profile:write'])
        ->and(AgentIntegrationScope::labels())->not->toHaveKey('mcp:use');
});

it('advertises only mcp:use in the authorization server metadata', function () {
    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonCount(1, 'scopes_supported')
        ->assertJsonPath('scopes_supported.0', 'mcp:use');
});

it('rejects an internal capability requested as an oauth scope', function () {
    $user = User::factory()->create();
    $client = Helpers::client();

    $response = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'response_type' => 'code',
        'client_id' => $client->id,
        'redirect_uri' => $client->redirect_uris[0],
        'scope' => 'captures:read',
        'state' => 'state-1',
        'code_challenge' => str_repeat('a', 43),
        'code_challenge_method' => 'S256',
    ]));

    expect($response->getStatusCode())->toBeIn([302, 400])
        ->and($response->headers->get('Location').$response->getContent())->toContain('invalid_scope');
});

it('shows consent for the mcp:use scope only', function () {
    $user = User::factory()->create();
    $client = Helpers::client();

    $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'response_type' => 'code',
        'client_id' => $client->id,
        'redirect_uri' => $client->redirect_uris[0],
        'scope' => 'mcp:use',
        'state' => 'state-2',
        'code_challenge' => str_repeat('b', 43),
        'code_challenge_method' => 'S256',
    ]))
        ->assertOk()
        ->assertSee($client->name);
});
