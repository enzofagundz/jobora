<?php

use App\Enums\AgentIntegrationScope;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\AgentIntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;
use Tests\Feature\Mcp\McpTestHelpers;

uses(RefreshDatabase::class);

it('requires an explicit submit grant and preserves it across registration', function () {
    $user = User::factory()->create();
    $client = Helpers::client();
    $service = app(AgentIntegrationService::class);
    $integration = $service->register($user, (string) $client->id, 'Hermes');
    $token = Helpers::accessToken($user, $client);

    expect($integration->can(AgentIntegrationScope::ApplicationsSubmit))->toBeFalse()
        ->and(AgentIntegrationScope::values())->toContain('applications:submit')
        ->and(AgentIntegrationScope::defaults())->not->toContain('applications:submit');

    $response = $this->withToken($token)->postJson('/mcp', Helpers::toolCall('reserve_application_submission'))->assertOk();
    expect(Helpers::decodeToolResult($response->getContent())['error']['code'] ?? null)->toBe('forbidden');

    $integration->update(['scopes' => [...$integration->scopes, AgentIntegrationScope::ApplicationsSubmit->value]]);
    expect($service->register($user, (string) $client->id, 'Hermes')->can(AgentIntegrationScope::ApplicationsSubmit))->toBeTrue();
});

it('separates resume and adaptation capabilities when reading resource uris', function (AgentIntegrationScope $scope, bool $adaptation, bool $allowed) {
    $user = User::factory()->create();
    $client = Helpers::client();
    Helpers::integration($user, $client, [$scope]);
    $token = Helpers::accessToken($user, $client);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::adaptation($user, $posting);

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'resources/read',
        'params' => ['uri' => $adaptation ? 'jobora://resume-adaptations/'.$posting->id : 'jobora://resume'],
    ])->assertOk();

    $body = json_decode($response->json('result.contents.0.text'), true);

    expect($body['ok'])->toBe($allowed);

    if (! $allowed) {
        expect($body['error']['code'])->toBe('forbidden');
    }
})->with([
    'ats reads adaptations' => [AgentIntegrationScope::AtsRead, true, true],
    'resume cannot read adaptations' => [AgentIntegrationScope::ResumeRead, true, false],
    'resume reads resume' => [AgentIntegrationScope::ResumeRead, false, true],
    'ats cannot read resume' => [AgentIntegrationScope::AtsRead, false, false],
]);

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
