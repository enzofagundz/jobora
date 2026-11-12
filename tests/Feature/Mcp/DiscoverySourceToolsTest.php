<?php

use App\Enums\AgentIntegrationScope;
use App\Mcp\Servers\JoboraServer;
use App\Mcp\Tools\Discovery\CreateDiscoverySourceTool;
use App\Mcp\Tools\Discovery\DeleteDiscoverySourceTool;
use App\Mcp\Tools\Discovery\GetDiscoverySourceTool;
use App\Mcp\Tools\Discovery\ListDiscoverySourcesTool;
use App\Mcp\Tools\Discovery\UpdateDiscoverySourceTool;
use App\Models\DiscoverySource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;
use Tests\Feature\Mcp\McpTestHelpers;

uses(RefreshDatabase::class);

it('creates, lists and gets a discovery source through mcp tools', function () {
    $user = McpTestHelpers::user();

    $created = JoboraServer::tool(CreateDiscoverySourceTool::class, [
        'name' => 'LinkedIn Laravel',
        'source' => 'linkedin',
        'surface' => 'linkedin_jobs',
        'frequency_minutes' => 45,
        'keywords' => ['Laravel'],
        'published_within_days' => 3,
    ])->assertOk();

    $created->assertStructuredContent(fn ($json) => $json
        ->where('ok', true)
        ->where('data.source.source', 'linkedin')
        ->where('data.source.version', 1)
        ->where('data.source.active', true)
        ->where('data.source.frequency_minutes', 45)
        ->etc());

    $source = DiscoverySource::query()->where('user_id', $user->id)->sole();

    expect($source->url)->toContain('f_TPR=r259200');

    JoboraServer::tool(ListDiscoverySourcesTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.total', 1)
            ->where('data.items.0.id', $source->id)
            ->etc());

    JoboraServer::tool(GetDiscoverySourceTool::class, ['id' => $source->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.source.id', $source->id)
            ->etc());
});

it('requires expected version and confirm for update and delete', function () {
    $user = McpTestHelpers::user();
    $source = DiscoverySource::factory()->for($user, 'user')->create();

    JoboraServer::tool(UpdateDiscoverySourceTool::class, [
        'id' => $source->id,
        'expected_version' => 99,
        'name' => 'Nova',
    ])->assertHasErrors(['conflict']);

    JoboraServer::tool(UpdateDiscoverySourceTool::class, [
        'id' => $source->id,
        'expected_version' => 1,
        'name' => 'Nova',
    ])->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.source.name', 'Nova')
            ->where('data.source.version', 2)
            ->etc());

    JoboraServer::tool(DeleteDiscoverySourceTool::class, [
        'id' => $source->id,
        'expected_version' => 2,
    ])->assertHasErrors(['conflict']);

    JoboraServer::tool(DeleteDiscoverySourceTool::class, [
        'id' => $source->id,
        'expected_version' => 2,
        'confirm' => true,
    ])->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.source.active', false)
            ->etc());

    expect(DiscoverySource::withTrashed()->whereKey($source->id)->exists())->toBeTrue();
});

it('does not expose another user source through mcp tools', function () {
    $owner = User::factory()->create();
    $intruder = McpTestHelpers::user();
    $source = DiscoverySource::factory()->for($owner, 'user')->create();

    JoboraServer::tool(GetDiscoverySourceTool::class, ['id' => $source->id])
        ->assertHasErrors(['not_found']);

    JoboraServer::tool(UpdateDiscoverySourceTool::class, [
        'id' => $source->id,
        'expected_version' => 1,
        'name' => 'Hack',
    ])->assertHasErrors(['not_found']);
});

it('enforces source capabilities on remote integrations', function () {
    $user = User::factory()->create();
    $client = Helpers::client();
    Helpers::integration($user, $client, [AgentIntegrationScope::SourcesRead]);
    $token = Helpers::accessToken($user, $client);

    $list = $this->withToken($token)
        ->postJson('/mcp', Helpers::toolCall('list_discovery_sources'))
        ->assertOk();

    $create = $this->withToken($token)
        ->postJson('/mcp', Helpers::toolCall('create_discovery_source', [
            'name' => 'Bloqueada',
            'source' => 'linkedin',
            'surface' => 'linkedin_jobs',
            'frequency_minutes' => 60,
            'keywords' => ['PHP'],
        ]))
        ->assertOk();

    expect(Helpers::decodeToolResult($list->getContent())['ok'] ?? null)->toBeTrue()
        ->and(Helpers::decodeToolResult($create->getContent())['ok'] ?? null)->toBeFalse()
        ->and(Helpers::decodeToolResult($create->getContent())['error']['code'] ?? null)->toBe('forbidden');
});

it('denies source tools to integrations created before the new capabilities', function () {
    $user = User::factory()->create();
    $client = Helpers::client();
    Helpers::integration($user, $client, [AgentIntegrationScope::MarketRead]);
    $token = Helpers::accessToken($user, $client);

    $response = $this->withToken($token)
        ->postJson('/mcp', Helpers::toolCall('list_discovery_sources'))
        ->assertOk();

    expect(Helpers::decodeToolResult($response->getContent())['ok'] ?? null)->toBeFalse()
        ->and(Helpers::decodeToolResult($response->getContent())['error']['code'] ?? null)->toBe('forbidden');
});

it('rejects an invalid source url through the mcp tool', function () {
    McpTestHelpers::user();

    JoboraServer::tool(CreateDiscoverySourceTool::class, [
        'name' => 'Inválida',
        'source' => 'indeed',
        'surface' => 'indeed_jobs',
        'frequency_minutes' => 60,
        'url' => 'https://evil.example/jobs?q=PHP',
    ])->assertHasErrors(['validation']);
});

it('returns the requested window and next offset for non aligned offsets', function () {
    $user = McpTestHelpers::user();

    foreach (range(1, 4) as $index) {
        DiscoverySource::factory()->for($user, 'user')->create(['name' => 'Fonte '.$index]);
    }

    $expected = DiscoverySource::query()->orderByDesc('id')->pluck('id')->all();

    JoboraServer::tool(ListDiscoverySourcesTool::class, ['limit' => 2, 'offset' => 1])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.total', 4)
            ->where('data.next_offset', 3)
            ->where('data.items.0.id', $expected[1])
            ->where('data.items.1.id', $expected[2])
            ->etc());
});

it('merges a partial criteria update through the mcp tool', function () {
    $user = McpTestHelpers::user();
    $source = DiscoverySource::factory()->for($user, 'user')->create([
        'criteria' => [
            'keywords' => ['Laravel'],
            'excluded_terms' => [],
            'operator' => 'or',
            'seniority' => null,
            'location' => 'São Paulo',
            'work_mode' => 'remote',
            'published_within_days' => 7,
            'easy_apply' => false,
        ],
    ]);

    JoboraServer::tool(UpdateDiscoverySourceTool::class, [
        'id' => $source->id,
        'expected_version' => 1,
        'keywords' => ['PHP'],
    ])->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.source.criteria.keywords', ['PHP'])
            ->where('data.source.criteria.location', 'São Paulo')
            ->where('data.source.criteria.work_mode', 'remote')
            ->where('data.source.criteria.published_within_days', 7)
            ->where('data.source.criteria.operator', 'or')
            ->etc());
});
