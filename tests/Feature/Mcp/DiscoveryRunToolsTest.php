<?php

use App\Enums\AgentIntegrationScope;
use App\Mcp\Servers\JoboraServer;
use App\Mcp\Tools\Discovery\AcquireDiscoveryRunsTool;
use App\Mcp\Tools\Discovery\GetDiscoveryRunTool;
use App\Mcp\Tools\Discovery\ListDiscoveryRunsTool;
use App\Mcp\Tools\Discovery\ListPendingDiscoverySourcesTool;
use App\Mcp\Tools\Discovery\ReportDiscoveryAccessTool;
use App\Models\DiscoveryRun;
use App\Models\DiscoverySource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;
use Tests\Feature\Mcp\McpTestHelpers;

uses(RefreshDatabase::class);

it('lists pending sources without reserving and then acquires idempotently', function () {
    $user = McpTestHelpers::user();
    $source = DiscoverySource::factory()->for($user, 'user')->create([
        'active' => true,
        'next_due_at' => now()->subMinute(),
    ]);

    JoboraServer::tool(ListPendingDiscoverySourcesTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.total', 1)
            ->where('data.items.0.id', $source->id)
            ->etc());

    expect(DiscoveryRun::query()->count())->toBe(0);

    JoboraServer::tool(AcquireDiscoveryRunsTool::class, ['idempotency_key' => 'idem-1'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.runs.0.source_id', $source->id)
            ->where('data.runs.0.status', 'active')
            ->etc());

    $run = DiscoveryRun::query()->sole();

    JoboraServer::tool(AcquireDiscoveryRunsTool::class, ['idempotency_key' => 'idem-1'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.runs.0.execution_id', $run->execution_id)
            ->etc());

    JoboraServer::tool(ListDiscoveryRunsTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 1)
            ->where('data.items.0.execution_id', $run->execution_id)
            ->etc());

    JoboraServer::tool(GetDiscoveryRunTool::class, ['execution_id' => $run->execution_id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.run.id', $run->id)
            ->etc());
});

it('reports access without declaring a capture and without touching last_success', function () {
    $user = McpTestHelpers::user();
    $source = DiscoverySource::factory()->for($user, 'user')->create([
        'active' => true,
        'next_due_at' => now()->subMinute(),
        'last_success_at' => now()->subDay(),
    ]);

    JoboraServer::tool(AcquireDiscoveryRunsTool::class, ['idempotency_key' => 'idem'])
        ->assertOk();

    $run = DiscoveryRun::query()->sole();

    JoboraServer::tool(ReportDiscoveryAccessTool::class, [
        'execution_id' => $run->execution_id,
        'event_key' => 'access-1',
        'outcome' => 'success',
        'final_url' => $source->url,
        'redirects' => ['https://www.linkedin.com/login'],
    ])->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.capture_created', false)
            ->where('data.event.outcome', 'success')
            ->etc());

    expect($run->refresh()->page_capture_id)->toBeNull()
        ->and($run->events()->count())->toBe(1)
        ->and($source->refresh()->last_success_at->toDateString())->toBe(now()->subDay()->toDateString());
});

it('accepts execution identifiers of 200 characters', function () {
    $user = McpTestHelpers::user();
    $executionId = str_repeat('e', 200);
    $run = DiscoveryRun::factory()->for($user, 'user')->create([
        'execution_id' => $executionId,
        'idempotency_key' => 'run-200',
    ]);

    JoboraServer::tool(GetDiscoveryRunTool::class, ['execution_id' => $executionId])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.run.id', $run->id)
            ->where('data.run.execution_id', $executionId)
            ->etc());

    JoboraServer::tool(ReportDiscoveryAccessTool::class, [
        'execution_id' => $executionId,
        'event_key' => 'access-200',
        'outcome' => 'success',
        'final_url' => $run->initial_url,
    ])->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.event.outcome', 'success')
            ->etc());

    expect($run->events()->count())->toBe(1);
});

it('fails closed when the integration lacks runs capabilities', function () {
    $user = User::factory()->create();
    $client = Helpers::client();
    Helpers::integration($user, $client, [AgentIntegrationScope::SourcesRead]);
    $token = Helpers::accessToken($user, $client);

    DiscoverySource::factory()->for($user, 'user')->create([
        'active' => true,
        'next_due_at' => now()->subMinute(),
    ]);

    $pending = $this->withToken($token)
        ->postJson('/mcp', Helpers::toolCall('list_pending_discovery_sources'))
        ->assertOk();

    $acquire = $this->withToken($token)
        ->postJson('/mcp', Helpers::toolCall('acquire_discovery_runs', ['idempotency_key' => 'blocked']))
        ->assertOk();

    expect(Helpers::decodeToolResult($pending->getContent())['ok'] ?? null)->toBeTrue()
        ->and(Helpers::decodeToolResult($acquire->getContent())['ok'] ?? null)->toBeFalse()
        ->and(Helpers::decodeToolResult($acquire->getContent())['error']['code'] ?? null)->toBe('forbidden');
});

it('allows runs tools with the internal runs capabilities', function () {
    $user = User::factory()->create();
    $client = Helpers::client();
    Helpers::integration($user, $client, [AgentIntegrationScope::RunsRead, AgentIntegrationScope::RunsWrite]);
    $token = Helpers::accessToken($user, $client);

    DiscoverySource::factory()->for($user, 'user')->create([
        'active' => true,
        'next_due_at' => now()->subMinute(),
    ]);

    $response = $this->withToken($token)
        ->postJson('/mcp', Helpers::toolCall('acquire_discovery_runs', ['idempotency_key' => 'idem']))
        ->assertOk();

    expect(Helpers::decodeToolResult($response->getContent())['ok'] ?? null)->toBeTrue()
        ->and(DiscoveryRun::query()->count())->toBe(1);
});

it('replays an acquired batch through the mcp tool without taking new sources', function () {
    $user = McpTestHelpers::user();
    DiscoverySource::factory()->for($user, 'user')->count(2)->create([
        'active' => true,
        'next_due_at' => now()->subMinute(),
    ]);

    JoboraServer::tool(AcquireDiscoveryRunsTool::class, ['idempotency_key' => 'mcp-batch', 'limit' => 2])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('data.runs', fn ($runs) => count($runs) === 2)->etc());

    $ids = DiscoveryRun::query()->orderBy('id')->pluck('id')->all();

    DiscoverySource::factory()->for($user, 'user')->create(['active' => true, 'next_due_at' => now()->subMinute()]);

    JoboraServer::tool(AcquireDiscoveryRunsTool::class, ['idempotency_key' => 'mcp-batch', 'limit' => 2])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('data.runs', fn ($runs) => count($runs) === 2)->etc());

    expect(DiscoveryRun::query()->orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and(DiscoveryRun::query()->count())->toBe(2);
});
