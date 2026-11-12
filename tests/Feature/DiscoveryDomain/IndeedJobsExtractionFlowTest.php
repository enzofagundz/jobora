<?php

use App\Enums\AgentIntegrationScope;
use App\Mcp\Servers\JoboraServer;
use App\Mcp\Tools\Captures\GetPageCaptureTool;
use App\Mcp\Tools\Captures\ListPublicationsTool;
use App\Models\DiscoveryRun;
use App\Models\DiscoverySource;
use App\Models\User;
use App\Services\CaptureTokenService;
use App\Services\Discovery\DiscoveryRunService;
use App\Services\Discovery\DiscoverySourceUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as IntegrationHelpers;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function indeedJobsFixture(string $file): array
{
    return json_decode((string) file_get_contents(base_path('fixtures/capture-envelope/v1/'.$file)), true);
}

/**
 * @return array{0: DiscoverySource, 1: DiscoveryRun}
 */
function indeedJobsRun(User $user, ?DiscoverySource $source = null): array
{
    if ($source === null) {
        $url = 'https://br.indeed.com/jobs?q=desenvolvedor+laravel';
        $source = DiscoverySource::factory()->for($user, 'user')->create([
            'source' => 'indeed',
            'surface' => 'indeed_jobs',
            'url' => $url,
            'canonical_url_hash' => app(DiscoverySourceUrl::class)->hash($url),
            'active' => true,
            'next_due_at' => now()->subMinute(),
        ]);
    } else {
        $source->refresh()->forceFill(['next_due_at' => now()->subMinute()])->save();
    }

    $run = app(DiscoveryRunService::class)->acquire($user, null, 'indeed-'.uniqid())->firstOrFail();

    return [$source, $run];
}

/**
 * @param  array<string, mixed>  $payload
 */
function indeedJobsPost(User $user, array $payload): TestResponse
{
    return test()->withToken(app(CaptureTokenService::class)->issueToken($user))
        ->postJson('/api/page-captures', $payload);
}

/**
 * @param  array<string, mixed>  $captureOverrides
 * @return array<string, mixed>
 */
function indeedJobsEnvelope(string $file, DiscoverySource $source, DiscoveryRun $run, array $captureOverrides = []): array
{
    $payload = indeedJobsFixture($file);
    $payload['capture']['idempotency_key'] = 'indeed-'.uniqid('', true);
    $payload['capture']['execution_id'] = $run->execution_id;
    $payload['capture']['initial_url'] = $source->url;
    $payload['capture']['captured_at'] = now()->toIso8601String();
    $payload['capture'] = array_merge($payload['capture'], $captureOverrides);

    return $payload;
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function indeedJobsWithoutPlatformIds(array $payload): array
{
    foreach ($payload['blocks'] as &$block) {
        $block['platform_id'] = null;
    }
    unset($block);

    $payload['capture']['html'] = preg_replace('/\sdata-platform-id="[^"]*"/', '', $payload['capture']['html']);

    return $payload;
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function indeedJobsWithPermalink(array $payload, string $permalink): array
{
    foreach ($payload['blocks'] as &$block) {
        if ($block['kind'] === 'card' || $block['kind'] === 'detail') {
            $block['permalink'] = $permalink;
        }
    }
    unset($block);

    $payload['capture']['html'] = preg_replace('/data-permalink="[^"]*"/', 'data-permalink="'.$permalink.'"', $payload['capture']['html']);

    return $payload;
}

beforeEach(function (): void {
    Storage::fake('local');
});

it('ingests the Indeed V1 fixture through the public API, worker, and MCP', function () {
    $user = User::factory()->create();
    config(['jobora.mcp.user' => $user->email]);
    [$source, $run] = indeedJobsRun($user);
    $payload = indeedJobsEnvelope('indeed-jobs.json', $source, $run);

    indeedJobsPost($user, $payload)
        ->assertStatus(201)
        ->assertJsonPath('execution_id', $run->execution_id);

    JoboraServer::tool(GetPageCaptureTool::class, ['execution_id' => $run->execution_id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.capture.surface', 'indeed_jobs')
            ->where('data.capture.extraction.status', 'completed')
            ->where('data.capture.extraction.counts.publications', 1)
            ->etc());

    JoboraServer::tool(ListPublicationsTool::class, ['execution_id' => $run->execution_id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 1)
            ->where('data.items.0.publication.external_id', 'exemplo0001')
            ->where('data.items.0.publication.url', 'https://br.indeed.com/viewjob?jk=exemplo0001')
            ->where('data.items.0.publication.content', fn (string $content): bool => str_contains($content, 'Descrição sintética da vaga de exemplo no Indeed.'))
            ->where('data.items.0.observation.evidence_block_ids', ['card-1', 'detail-1'])
            ->etc());
});

it('ingests an extension-shaped Indeed card detail and partial coverage envelope', function () {
    $user = User::factory()->create();
    [$source, $run] = indeedJobsRun($user);
    $payload = indeedJobsEnvelope('indeed-jobs-partial.json', $source, $run);

    indeedJobsPost($user, $payload)
        ->assertStatus(201)
        ->assertJsonPath('execution_id', $run->execution_id);

    $client = IntegrationHelpers::client();
    IntegrationHelpers::integration($user, $client, [AgentIntegrationScope::CapturesRead]);
    $token = IntegrationHelpers::accessToken($user, $client);
    $captureResponse = $this->withToken($token)
        ->postJson('/mcp', IntegrationHelpers::toolCall('get_page_capture', ['execution_id' => $run->execution_id]))
        ->assertOk();
    $captureResult = IntegrationHelpers::decodeToolResult($captureResponse->getContent());
    $publicationsResponse = $this->withToken($token)
        ->postJson('/mcp', IntegrationHelpers::toolCall('list_publications', ['execution_id' => $run->execution_id]))
        ->assertOk();
    $publicationsResult = IntegrationHelpers::decodeToolResult($publicationsResponse->getContent());

    expect($captureResult['ok'] ?? false)->toBeTrue()
        ->and($captureResult['data']['capture']['coverage'] ?? null)->toBe('partial')
        ->and($captureResult['data']['capture']['coverage_reason'] ?? null)->toBe('timeout')
        ->and($captureResult['data']['capture']['extraction']['status'] ?? null)->toBe('completed')
        ->and($captureResult['data']['capture']['extraction']['counts']['blocks'] ?? null)->toBe(3)
        ->and($captureResult['data']['capture']['extraction']['counts']['publications'] ?? null)->toBe(2)
        ->and($publicationsResult['ok'] ?? false)->toBeTrue()
        ->and($publicationsResult['data']['total'] ?? null)->toBe(2)
        ->and($publicationsResult['data']['items'][0]['observation']['evidence_block_ids'] ?? null)->toBe(['card-1', 'detail-1'])
        ->and($publicationsResult['data']['items'][0]['observation']['content'] ?? '')->toContain('Descrição sintética da vaga de exemplo no Indeed.')
        ->and($publicationsResult['data']['items'][1]['observation']['evidence_block_ids'] ?? null)->toBe(['card-2']);
});

it('distinguishes Indeed jobs by jk when blocks have no platform id', function () {
    $user = User::factory()->create();
    config(['jobora.mcp.user' => $user->email]);
    [$source, $run] = indeedJobsRun($user);
    $payload = indeedJobsWithoutPlatformIds(indeedJobsEnvelope('indeed-jobs-partial.json', $source, $run));

    indeedJobsPost($user, $payload)
        ->assertStatus(201)
        ->assertJsonPath('execution_id', $run->execution_id);

    $client = IntegrationHelpers::client();
    IntegrationHelpers::integration($user, $client, [AgentIntegrationScope::CapturesRead]);
    $token = IntegrationHelpers::accessToken($user, $client);
    $response = $this->withToken($token)
        ->postJson('/mcp', IntegrationHelpers::toolCall('get_page_capture', ['execution_id' => $run->execution_id]))
        ->assertOk();
    $result = IntegrationHelpers::decodeToolResult($response->getContent());

    expect($result['ok'] ?? false)->toBeTrue()
        ->and($result['data']['capture']['coverage'] ?? null)->toBe('partial')
        ->and($result['data']['capture']['coverage_reason'] ?? null)->toBe('timeout')
        ->and($result['data']['capture']['extraction']['status'] ?? null)->toBe('completed')
        ->and($result['data']['capture']['extraction']['counts']['publications'] ?? null)->toBe(2);

    JoboraServer::tool(ListPublicationsTool::class, ['execution_id' => $run->execution_id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 2)
            ->where('data.items.0.publication.external_id', null)
            ->where('data.items.0.publication.url', 'https://br.indeed.com/viewjob?jk=exemplo0001')
            ->where('data.items.0.observation.evidence_block_ids', ['card-1', 'detail-1'])
            ->where('data.items.1.publication.external_id', null)
            ->where('data.items.1.publication.url', 'https://br.indeed.com/viewjob?jk=exemplo0002')
            ->where('data.items.1.observation.evidence_block_ids', ['card-2'])
            ->etc());
});

it('reports malformed Indeed decupagem without undoing the received capture', function () {
    $user = User::factory()->create();
    config(['jobora.mcp.user' => $user->email]);
    [$source, $run] = indeedJobsRun($user);
    $payload = indeedJobsWithPermalink(
        indeedJobsEnvelope('indeed-jobs.json', $source, $run),
        'https://br.indeed.com/jobs?q=desenvolvedor&jk=exemplo0001',
    );

    indeedJobsPost($user, $payload)
        ->assertStatus(201)
        ->assertJsonPath('execution_id', $run->execution_id);

    $client = IntegrationHelpers::client();
    IntegrationHelpers::integration($user, $client, [AgentIntegrationScope::CapturesRead]);
    $token = IntegrationHelpers::accessToken($user, $client);
    $response = $this->withToken($token)
        ->postJson('/mcp', IntegrationHelpers::toolCall('get_page_capture', ['execution_id' => $run->execution_id]))
        ->assertOk();
    $result = IntegrationHelpers::decodeToolResult($response->getContent());

    expect($result['ok'] ?? false)->toBeTrue()
        ->and($result['data']['capture']['ingest_status'] ?? null)->toBe('received')
        ->and($result['data']['capture']['extraction']['status'] ?? null)->toBe('failed')
        ->and($result['data']['capture']['extraction']['error_code'] ?? null)->toBe('parse_failed')
        ->and($result['data']['capture']['extraction']['counts']['publications'] ?? null)->toBe(0);
});

it('reports Indeed access failures through an authorized integration', function () {
    $user = User::factory()->create();
    [$source, $run] = indeedJobsRun($user);
    $client = IntegrationHelpers::client();
    IntegrationHelpers::integration($user, $client, [AgentIntegrationScope::RunsWrite]);
    $token = IntegrationHelpers::accessToken($user, $client);
    $response = $this->withToken($token)
        ->postJson('/mcp', IntegrationHelpers::toolCall('report_discovery_access', [
            'execution_id' => $run->execution_id,
            'event_key' => 'indeed-access-failure',
            'outcome' => 'failure',
            'http_status' => 403,
            'error' => 'login_required',
            'final_url' => $source->url,
        ]))
        ->assertOk();
    $result = IntegrationHelpers::decodeToolResult($response->getContent());

    expect($result['ok'] ?? false)->toBeTrue()
        ->and($result['data']['event']['outcome'] ?? null)->toBe('failure')
        ->and($result['data']['capture_created'] ?? null)->toBeFalse();
});

it('isolates Indeed captures and publications between users', function () {
    $owner = User::factory()->create();
    config(['jobora.mcp.user' => $owner->email]);
    [$source, $run] = indeedJobsRun($owner);

    indeedJobsPost($owner, indeedJobsEnvelope('indeed-jobs.json', $source, $run))
        ->assertStatus(201);

    $other = User::factory()->create();
    config(['jobora.mcp.user' => $other->email]);

    JoboraServer::tool(GetPageCaptureTool::class, ['execution_id' => $run->execution_id])
        ->assertHasErrors();
    JoboraServer::tool(ListPublicationsTool::class, ['execution_id' => $run->execution_id])
        ->assertHasErrors();
});

it('uses jk for recaptures and preserves prior observation when Indeed content changes', function () {
    $user = User::factory()->create();
    config(['jobora.mcp.user' => $user->email]);
    [$source, $firstRun] = indeedJobsRun($user);
    $firstPayload = indeedJobsWithPermalink(
        indeedJobsWithoutPlatformIds(indeedJobsEnvelope('indeed-jobs.json', $source, $firstRun)),
        'https://br.indeed.com/viewjob?utm_source=jobora&jk=exemplo0001',
    );

    indeedJobsPost($user, $firstPayload)
        ->assertStatus(201)
        ->assertJsonPath('replay', false);
    indeedJobsPost($user, $firstPayload)
        ->assertStatus(200)
        ->assertJsonPath('replay', true);

    $firstPublicationId = null;
    JoboraServer::tool(ListPublicationsTool::class, ['execution_id' => $firstRun->execution_id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 1)
            ->where('data.items.0.publication.id', function (int $id) use (&$firstPublicationId): bool {
                $firstPublicationId = $id;

                return $id > 0;
            })
            ->where('data.items.0.publication.url', 'https://br.indeed.com/viewjob?utm_source=jobora&jk=exemplo0001')
            ->where('data.items.0.observation.content_version', 1)
            ->etc());

    [$source, $secondRun] = indeedJobsRun($user, $source);
    $secondPayload = indeedJobsWithPermalink(
        indeedJobsWithoutPlatformIds(indeedJobsEnvelope('indeed-jobs.json', $source, $secondRun)),
        'https://br.indeed.com/viewjob?jk=exemplo0001&utm_campaign=refresh',
    );
    $secondPayload['capture']['html'] = str_replace(
        'Descrição sintética da vaga de exemplo no Indeed.',
        'Descrição Indeed atualizada.',
        $secondPayload['capture']['html'],
    );

    indeedJobsPost($user, $secondPayload)->assertStatus(201);

    JoboraServer::tool(ListPublicationsTool::class, ['execution_id' => $firstRun->execution_id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 1)
            ->where('data.items.0.publication.content_version', 2)
            ->where('data.items.0.observation.content', fn (string $content): bool => str_contains($content, 'Descrição sintética da vaga de exemplo no Indeed.'))
            ->where('data.items.0.observation.content_version', 1)
            ->etc());

    $secondPublicationId = null;
    JoboraServer::tool(ListPublicationsTool::class, ['execution_id' => $secondRun->execution_id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 1)
            ->where('data.items.0.publication.id', function (int $id) use (&$secondPublicationId): bool {
                $secondPublicationId = $id;

                return $id > 0;
            })
            ->where('data.items.0.publication.url', 'https://br.indeed.com/viewjob?utm_source=jobora&jk=exemplo0001')
            ->where('data.items.0.publication.content', 'Vaga Exemplo Indeed Descrição Indeed atualizada. PHPLaravel')
            ->where('data.items.0.publication.content_version', 2)
            ->where('data.items.0.observation.content_version', 2)
            ->etc());

    expect($secondPublicationId)->toBe($firstPublicationId);
});
