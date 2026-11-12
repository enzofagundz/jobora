<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers;

uses(RefreshDatabase::class);

it('publishes protected resource metadata for the MCP endpoint', function () {
    $this->getJson('/.well-known/oauth-protected-resource')
        ->assertOk()
        ->assertJsonPath('scopes_supported.0', 'mcp:use')
        ->assertJsonStructure(['resource', 'authorization_servers']);
});

it('publishes authorization server metadata with pkce and registration endpoints', function () {
    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonCount(1, 'scopes_supported')
        ->assertJsonPath('response_types_supported.0', 'code')
        ->assertJsonPath('code_challenge_methods_supported.0', 'S256')
        ->assertJsonStructure([
            'issuer',
            'authorization_endpoint',
            'token_endpoint',
            'registration_endpoint',
            'grant_types_supported',
        ]);
});

it('advertises the protected resource in the www-authenticate header', function () {
    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ])->assertStatus(401);

    expect((string) $response->headers->get('WWW-Authenticate'))
        ->toContain('resource_metadata=')
        ->toContain('/.well-known/oauth-protected-resource/mcp');
});

it('registers a dynamic client with a loopback redirect uri', function () {
    $this->postJson('/oauth/register', [
        'client_name' => 'Hermes',
        'redirect_uris' => ['http://127.0.0.1:9999/callback'],
    ])
        ->assertStatus(201)
        ->assertJsonPath('scope', 'mcp:use')
        ->assertJsonStructure(['client_id', 'redirect_uris', 'grant_types']);
});

it('rejects a dynamic client with a non loopback redirect uri', function () {
    $this->postJson('/oauth/register', [
        'client_name' => 'Hermes',
        'redirect_uris' => ['https://evil.example/callback'],
    ])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_redirect_uri');
});

it('throttles dynamic client registration', function () {
    $payload = [
        'client_name' => 'Hermes',
        'redirect_uris' => ['http://127.0.0.1:9999/callback'],
    ];

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->postJson('/oauth/register', $payload)->assertStatus(201);
    }

    $this->postJson('/oauth/register', $payload)->assertStatus(429);
});

it('rejects redirect uris with userinfo or host spoofing variants', function (string $redirect) {
    $this->postJson('/oauth/register', [
        'client_name' => 'Hermes',
        'redirect_uris' => [$redirect],
    ])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_redirect_uri');
})->with([
    'userinfo host spoof' => ['http://127.0.0.1:9998@attacker.tld/cb'],
    'localhost suffix' => ['http://localhost.evil/cb'],
    'percent encoded host' => ['http://127.0.0.1%40attacker.tld/cb'],
    'credentials' => ['http://user:pass@127.0.0.1:9998/cb'],
    'fragment' => ['http://127.0.0.1:9998/cb#fragment'],
    'invalid port' => ['http://127.0.0.1:99999/cb'],
    'backslash' => ['http://127.0.0.1/cb\\evil'],
    'traversal' => ['http://127.0.0.1/../callback'],
    'control character' => ["http://127.0.0.1/cb\nother"],
]);

it('accepts loopback redirect uris with dynamic ports and hosts', function (string $redirect) {
    $this->postJson('/oauth/register', [
        'client_name' => 'Hermes',
        'redirect_uris' => [$redirect],
    ])->assertStatus(201);
})->with([
    '127.0.0.1' => ['http://127.0.0.1:9998/callback'],
    'localhost' => ['http://localhost:3000/callback'],
    'ipv6 loopback' => ['http://[::1]:9999/callback'],
]);

it('protects the consent page from framing', function () {
    $user = User::factory()->create();
    $client = AgentIntegrationTestHelpers::client();

    $response = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'response_type' => 'code',
        'client_id' => $client->id,
        'redirect_uri' => $client->redirect_uris[0],
        'scope' => 'mcp:use',
        'state' => 'state-frame',
        'code_challenge' => str_repeat('c', 43),
        'code_challenge_method' => 'S256',
    ]));

    $response->assertOk();

    expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and((string) $response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
});

it('honors configured redirect domains and custom schemes', function (array $domains, array $schemes, string $redirect, bool $allowed) {
    config(['mcp.redirect_domains' => $domains, 'mcp.custom_schemes' => $schemes]);

    $response = $this->postJson('/oauth/register', [
        'client_name' => 'Configured client',
        'redirect_uris' => [$redirect],
    ])->assertStatus($allowed ? 201 : 400);

    if ($allowed) {
        $response->assertJsonPath('redirect_uris.0', $redirect);
    } else {
        $response->assertJsonPath('error', 'invalid_redirect_uri');
    }
})->with([
    'remote origin' => [['https://client.example'], [], 'https://client.example/callback', true],
    'remote explicit port' => [['https://client.example:8443'], [], 'https://client.example:8443/callback', true],
    'remote wrong port' => [['https://client.example'], [], 'https://client.example:8443/callback', false],
    'remote suffix spoof' => [['https://client.example'], [], 'https://client.example.evil/callback', false],
    'remote userinfo spoof' => [['https://client.example'], [], 'https://client.example@evil.example/callback', false],
    'remote credentials' => [['https://client.example'], [], 'https://user:pass@client.example/callback', false],
    'remote fragment' => [['https://client.example'], [], 'https://client.example/callback#token', false],
    'remote wrong scheme' => [['https://client.example'], [], 'http://client.example/callback', false],
    'loopback removed' => [[], [], 'http://127.0.0.1:9999/callback', false],
    'loopback host not configured' => [['http://localhost'], [], 'http://127.0.0.1:9999/callback', false],
    'wildcard explicit opt in' => [['*'], [], 'https://other.example/callback', true],
    'wildcard does not allow custom schemes' => [['*'], [], 'cursor://client/callback', false],
    'custom scheme' => [[], ['cursor'], 'cursor://client/callback', true],
    'custom scheme host only' => [[], ['cursor'], 'cursor://callback', true],
    'custom scheme not configured' => [[], [], 'cursor://client/callback', false],
    'custom scheme fragment' => [[], ['cursor'], 'cursor://client/callback#token', false],
    'custom scheme credentials' => [[], ['cursor'], 'cursor://user:pass@client/callback', false],
    'custom scheme missing host' => [[], ['cursor'], 'cursor:/callback', false],
]);
