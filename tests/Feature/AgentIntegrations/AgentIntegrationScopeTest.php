<?php

use App\Enums\AgentIntegrationScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;

uses(RefreshDatabase::class);

it('blocks a tool when the integration lacks its internal capability', function () {
    $user = User::factory()->create();
    $client = Helpers::client();
    Helpers::integration($user, $client, [AgentIntegrationScope::CapturesRead]);
    $token = Helpers::accessToken($user, $client);

    $publications = $this->withToken($token)
        ->postJson('/mcp', Helpers::toolCall('list_publications', ['clef_status' => 'eligible']))
        ->assertOk();

    $resume = $this->withToken($token)
        ->postJson('/mcp', Helpers::toolCall('get_resume'))
        ->assertOk();

    expect(Helpers::decodeToolResult($publications->getContent())['ok'] ?? null)->toBeTrue()
        ->and(Helpers::decodeToolResult($resume->getContent())['ok'] ?? null)->toBeFalse()
        ->and(Helpers::decodeToolResult($resume->getContent())['error']['code'] ?? null)->toBe('forbidden');
});

it('blocks a write tool when only its read capability is granted', function () {
    $user = User::factory()->create();
    $client = Helpers::client();
    Helpers::integration($user, $client, [AgentIntegrationScope::ResumeRead, AgentIntegrationScope::MarketRead]);
    $token = Helpers::accessToken($user, $client);

    $response = $this->withToken($token)
        ->postJson('/mcp', Helpers::toolCall('save_resume', ['content' => 'Texto novo']))
        ->assertOk();

    $body = Helpers::decodeToolResult($response->getContent());

    expect($body['ok'] ?? null)->toBeFalse()
        ->and($body['error']['code'] ?? null)->toBe('forbidden');
});

it('blocks a resource when the integration lacks its internal capability', function () {
    $user = User::factory()->create();
    $client = Helpers::client();
    Helpers::integration($user, $client, [AgentIntegrationScope::MarketRead]);
    $token = Helpers::accessToken($user, $client);

    $response = $this->withToken($token)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/read',
            'params' => ['uri' => 'jobora://resume'],
        ])
        ->assertOk();

    $decoded = json_decode($response->getContent(), true);
    $text = $decoded['result']['contents'][0]['text'] ?? '{}';
    $body = json_decode($text, true) ?: [];

    expect($body['ok'] ?? null)->toBeFalse()
        ->and($body['error']['code'] ?? null)->toBe('forbidden');
});
