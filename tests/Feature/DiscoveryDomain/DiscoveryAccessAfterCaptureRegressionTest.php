<?php

use App\Enums\AgentIntegrationScope;
use App\Enums\DiscoveryRunStatus;
use App\Models\DiscoveryRun;
use App\Models\DiscoverySource;
use App\Models\PageCapture;
use App\Models\User;
use App\Services\CaptureTokenService;
use App\Services\Discovery\DiscoverySourceUrl;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as IntegrationHelpers;

uses(RefreshDatabase::class);

function discoveryAccessRegressionMcpToken(User $user): string
{
    $client = IntegrationHelpers::client();
    IntegrationHelpers::integration($user, $client, [
        AgentIntegrationScope::RunsRead,
        AgentIntegrationScope::RunsWrite,
    ]);

    return IntegrationHelpers::accessToken($user, $client);
}

function discoveryAccessRegressionSource(User $user, CarbonInterface $dueAt): DiscoverySource
{
    $url = 'https://www.linkedin.com/search/results/content/?keywords=synthetic-smoke';

    return DiscoverySource::factory()->for($user, 'user')->create([
        'source' => 'linkedin',
        'surface' => 'linkedin_publications',
        'url' => $url,
        'canonical_url_hash' => app(DiscoverySourceUrl::class)->hash($url),
        'active' => true,
        'next_due_at' => $dueAt,
    ]);
}

/**
 * @return array<string, mixed>
 */
function discoveryAccessRegressionEnvelope(string $executionId, CarbonInterface $capturedAt): array
{
    return [
        'format' => 'jobora.capture-envelope',
        'version' => 1,
        'capture' => [
            'idempotency_key' => 'smoke-access-'.str()->uuid(),
            'execution_id' => $executionId,
            'source' => 'linkedin',
            'surface' => 'linkedin_publications',
            'initial_url' => 'https://www.linkedin.com/search/results/content/?keywords=synthetic-smoke',
            'final_url' => 'https://www.linkedin.com/search/results/content/?keywords=synthetic-smoke',
            'captured_at' => $capturedAt->toIso8601String(),
            'coverage' => [
                'status' => 'complete',
                'reason' => null,
                'blocks_expected' => 1,
                'blocks_captured' => 1,
            ],
            'html' => '<main data-capture-surface="linkedin_publications" data-capture-version="1"><article data-block-id="post-1" data-kind="post"><p>Synthetic smoke content.</p></article></main>',
        ],
        'blocks' => [[
            'block_id' => 'post-1',
            'kind' => 'post',
            'platform_id' => null,
            'permalink' => null,
            'card_block_id' => null,
            'detail_block_id' => null,
        ]],
    ];
}

/**
 * @return array{DiscoverySource, PageCapture, DiscoveryRun}
 */
function discoveryAccessRegressionCompletedRun(
    User $user,
    CarbonInterface $reservedAt,
    CarbonInterface $expiresAt,
): array {
    $source = discoveryAccessRegressionSource($user, $reservedAt);
    $capture = PageCapture::factory()->for($user, 'user')->create();
    $run = DiscoveryRun::factory()
        ->for($user, 'user')
        ->for($source, 'source')
        ->create([
            'status' => DiscoveryRunStatus::Completed,
            'active_key' => null,
            'reserved_at' => $reservedAt,
            'expires_at' => $expiresAt,
            'completed_at' => $reservedAt->copy()->addMinute(),
            'page_capture_id' => $capture->id,
        ]);

    return [$source, $capture, $run];
}

beforeEach(function (): void {
    Storage::fake('local');
    Queue::fake();
    Http::fake();
});

it('records same-run access observed before expiry after durable capture completes the run', function (): void {
    $reservedAt = now()->copy()->setTime(13, 1, 47);
    $accessAt = $reservedAt->copy()->addSeconds(32);
    $capturedAt = $accessAt->copy()->addSecond();
    $this->travelTo($reservedAt);

    $user = User::factory()->create();
    $mcpToken = discoveryAccessRegressionMcpToken($user);
    $source = discoveryAccessRegressionSource($user, $reservedAt);

    $acquisition = $this->withToken($mcpToken)
        ->postJson('/mcp', IntegrationHelpers::toolCall('acquire_discovery_runs', [
            'idempotency_key' => 'same-run-after-capture',
        ]))
        ->assertOk();
    $acquisitionResult = IntegrationHelpers::decodeToolResult($acquisition->getContent());
    $executionId = $acquisitionResult['data']['runs'][0]['execution_id'];
    $run = DiscoveryRun::query()->where('execution_id', $executionId)->sole();

    $this->travelTo($capturedAt);

    $upload = $this->withToken(app(CaptureTokenService::class)->issueToken($user))
        ->postJson('/api/page-captures', discoveryAccessRegressionEnvelope($executionId, $capturedAt))
        ->assertCreated()
        ->assertJsonPath('execution_id', $executionId);
    $capture = PageCapture::query()->findOrFail($upload->json('id'));
    $completedRun = $run->fresh();
    $completedAt = $completedRun->completed_at->copy();

    expect($acquisitionResult['ok'] ?? false)->toBeTrue()
        ->and($completedRun->status)->toBe(DiscoveryRunStatus::Completed)
        ->and($completedRun->page_capture_id)->toBe($capture->id)
        ->and($completedRun->expires_at->isAfter($accessAt))->toBeTrue()
        ->and($completedRun->last_access_at)->toBeNull()
        ->and(Storage::disk('local')->exists($capture->html_path))->toBeTrue();

    $nextDueAt = $source->fresh()->next_due_at->copy();
    $this->travelTo($capturedAt->copy()->addMinute());
    $reportAccess = function (array $payload) use ($mcpToken): array {
        $response = $this->withToken($mcpToken)
            ->postJson('/mcp', IntegrationHelpers::toolCall('report_discovery_access', $payload))
            ->assertOk();

        return IntegrationHelpers::decodeToolResult($response->getContent());
    };
    $successPayload = [
        'execution_id' => $executionId,
        'event_key' => 'same-run-http-200',
        'outcome' => 'success',
        'final_url' => $source->url,
        'http_status' => 200,
        'details' => ['source' => 'extension'],
        'occurred_at' => $accessAt->toIso8601String(),
    ];
    $reportResult = $reportAccess($successPayload);
    $sameReplayResult = $reportAccess($successPayload);
    $changedDetailsResult = $reportAccess([
        ...$successPayload,
        'details' => ['source' => 'other'],
    ]);
    $changedOccurredAtResult = $reportAccess([
        ...$successPayload,
        'occurred_at' => $accessAt->copy()->addSeconds(5)->toIso8601String(),
    ]);
    $failureResult = $reportAccess([
        'execution_id' => $executionId,
        'event_key' => 'same-run-http-500',
        'outcome' => 'failure',
        'http_status' => 500,
        'error' => 'request failed after capture upload',
        'occurred_at' => $accessAt->copy()->addSeconds(20)->toIso8601String(),
    ]);
    $olderResult = $reportAccess([
        'execution_id' => $executionId,
        'event_key' => 'same-run-older-observation',
        'outcome' => 'success',
        'final_url' => $source->url,
        'http_status' => 200,
        'occurred_at' => $accessAt->copy()->addSeconds(10)->toIso8601String(),
    ]);

    $runLookup = $this->withToken($mcpToken)
        ->postJson('/mcp', IntegrationHelpers::toolCall('get_discovery_run', ['execution_id' => $executionId]))
        ->assertOk();
    $runResult = IntegrationHelpers::decodeToolResult($runLookup->getContent());
    $events = $run->fresh()->events()->get();
    $afterRun = $run->fresh();
    $failureContext = json_encode([
        'report' => $reportResult,
        'same_replay' => $sameReplayResult,
        'failure' => $failureResult,
        'run' => $runResult['data']['run'] ?? null,
        'event_count' => $events->count(),
    ], JSON_THROW_ON_ERROR);

    expect($reportResult['ok'] ?? false)->toBeTrue(
        'Expected same-execution access report to succeed after capture; '.$failureContext,
    )
        ->and($reportResult['data']['capture_created'] ?? null)->toBeFalse()
        ->and($reportResult['data']['event']['outcome'] ?? null)->toBe('success')
        ->and($reportResult['data']['event']['occurred_at'] ?? null)->toBe($accessAt->toIso8601String())
        ->and($sameReplayResult['ok'] ?? false)->toBeTrue()
        ->and($sameReplayResult['data']['event']['id'] ?? null)->toBe($reportResult['data']['event']['id'] ?? null)
        ->and($changedDetailsResult['ok'] ?? true)->toBeFalse()
        ->and($changedDetailsResult['error']['code'] ?? null)->toBe('conflict')
        ->and($changedOccurredAtResult['ok'] ?? true)->toBeFalse()
        ->and($changedOccurredAtResult['error']['code'] ?? null)->toBe('conflict')
        ->and($failureResult['ok'] ?? false)->toBeTrue()
        ->and($olderResult['ok'] ?? false)->toBeTrue()
        ->and($runResult['data']['run']['status'] ?? null)->toBe('completed')
        ->and($runResult['data']['run']['page_capture_id'] ?? null)->toBe($capture->id)
        ->and($runResult['data']['run']['last_access_at'] ?? null)->toBe($accessAt->copy()->addSeconds(20)->toIso8601String())
        ->and($events)->toHaveCount(3)
        ->and($events->pluck('outcome')->map(fn ($outcome) => $outcome->value)->all())->toBe(['success', 'failure', 'success'])
        ->and($afterRun->status)->toBe(DiscoveryRunStatus::Completed)
        ->and($afterRun->page_capture_id)->toBe($capture->id)
        ->and($afterRun->completed_at->equalTo($completedAt))->toBeTrue()
        ->and($source->fresh()->next_due_at->equalTo($nextDueAt))->toBeTrue()
        ->and(PageCapture::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('does not route an expired reservation callback to a new run or another user', function (): void {
    $reservedAt = now()->copy()->setTime(13, 1, 47);
    $this->travelTo($reservedAt);

    $owner = User::factory()->create();
    $ownerToken = discoveryAccessRegressionMcpToken($owner);
    $source = discoveryAccessRegressionSource($owner, $reservedAt);

    $firstAcquisition = $this->withToken($ownerToken)
        ->postJson('/mcp', IntegrationHelpers::toolCall('acquire_discovery_runs', [
            'idempotency_key' => 'old-reservation',
        ]))
        ->assertOk();
    $firstResult = IntegrationHelpers::decodeToolResult($firstAcquisition->getContent());
    $oldExecutionId = $firstResult['data']['runs'][0]['execution_id'];
    $oldRun = DiscoveryRun::query()->where('execution_id', $oldExecutionId)->sole();

    $this->travelTo($oldRun->expires_at->copy()->addSecond());

    $nextAcquisition = $this->withToken($ownerToken)
        ->postJson('/mcp', IntegrationHelpers::toolCall('acquire_discovery_runs', [
            'idempotency_key' => 'new-reservation',
        ]))
        ->assertOk();
    $nextResult = IntegrationHelpers::decodeToolResult($nextAcquisition->getContent());
    $newExecutionId = $nextResult['data']['runs'][0]['execution_id'];
    $newRun = DiscoveryRun::query()->where('execution_id', $newExecutionId)->sole();
    $staleOccurredAt = now()->toIso8601String();

    $staleReport = $this->withToken($ownerToken)
        ->postJson('/mcp', IntegrationHelpers::toolCall('report_discovery_access', [
            'execution_id' => $oldExecutionId,
            'event_key' => 'expired-reservation-callback',
            'outcome' => 'success',
            'final_url' => $source->url,
            'http_status' => 200,
            'occurred_at' => $staleOccurredAt,
        ]))
        ->assertOk();
    $staleResult = IntegrationHelpers::decodeToolResult($staleReport->getContent());

    $currentRunLookup = $this->withToken($ownerToken)
        ->postJson('/mcp', IntegrationHelpers::toolCall('get_discovery_run', ['execution_id' => $newExecutionId]))
        ->assertOk();
    $currentRunResult = IntegrationHelpers::decodeToolResult($currentRunLookup->getContent());

    expect($firstResult['ok'] ?? false)->toBeTrue()
        ->and($nextResult['ok'] ?? false)->toBeTrue()
        ->and($newExecutionId)->not->toBe($oldExecutionId)
        ->and($oldRun->fresh()->status)->toBe(DiscoveryRunStatus::Expired)
        ->and($staleResult['ok'] ?? true)->toBeFalse()
        ->and($staleResult['error']['code'] ?? null)->toBe('conflict')
        ->and($currentRunResult['data']['run']['status'] ?? null)->toBe('active')
        ->and($currentRunResult['data']['run']['page_capture_id'] ?? null)->toBeNull()
        ->and($currentRunResult['data']['run']['last_access_at'] ?? null)->toBeNull()
        ->and($oldRun->fresh()->events()->count())->toBe(0)
        ->and($newRun->fresh()->events()->count())->toBe(0)
        ->and(PageCapture::query()->count())->toBe(0);
});

it('does not let another user report access for a known execution identifier', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $source = discoveryAccessRegressionSource($owner, now());
    $run = DiscoveryRun::factory()
        ->for($owner, 'user')
        ->for($source, 'source')
        ->create();
    $otherUserToken = discoveryAccessRegressionMcpToken($otherUser);

    $response = $this->withToken($otherUserToken)
        ->postJson('/mcp', IntegrationHelpers::toolCall('report_discovery_access', [
            'execution_id' => $run->execution_id,
            'event_key' => 'cross-user-callback',
            'outcome' => 'success',
            'final_url' => $source->url,
            'http_status' => 200,
            'occurred_at' => now()->toIso8601String(),
        ]))
        ->assertOk();
    $result = IntegrationHelpers::decodeToolResult($response->getContent());

    expect($result['ok'] ?? true)->toBeFalse()
        ->and($result['error']['code'] ?? null)->toBe('not_found')
        ->and($run->fresh()->status)->toBe(DiscoveryRunStatus::Active)
        ->and($run->fresh()->page_capture_id)->toBeNull()
        ->and($run->events()->count())->toBe(0)
        ->and(PageCapture::query()->count())->toBe(0);
});

it('uses server receipt time when occurred_at is absent and rejects observations outside the reservation window', function (): void {
    $reservedAt = now()->copy()->startOfMinute();
    $expiresAt = $reservedAt->copy()->addMinutes(30);
    $receiptAt = $reservedAt->copy()->addMinutes(10);
    $this->travelTo($receiptAt);

    $user = User::factory()->create();
    $mcpToken = discoveryAccessRegressionMcpToken($user);
    [$source, $capture, $run] = discoveryAccessRegressionCompletedRun($user, $reservedAt, $expiresAt);
    $completedAt = $run->completed_at->copy();
    $nextDueAt = $source->fresh()->next_due_at->copy();

    $report = function (array $payload) use ($mcpToken) {
        return $this->withToken($mcpToken)
            ->postJson('/mcp', IntegrationHelpers::toolCall('report_discovery_access', $payload));
    };
    $receivedResult = IntegrationHelpers::decodeToolResult($report([
        'execution_id' => $run->execution_id,
        'event_key' => 'server-received-access',
        'outcome' => 'success',
        'final_url' => $source->url,
        'http_status' => 200,
    ])->assertOk()->getContent());
    $reservedBoundaryResult = IntegrationHelpers::decodeToolResult($report([
        'execution_id' => $run->execution_id,
        'event_key' => 'at-reservation-start',
        'outcome' => 'success',
        'final_url' => $source->url,
        'http_status' => 200,
        'occurred_at' => $reservedAt->toIso8601String(),
    ])->assertOk()->getContent());

    $invalidPayloads = [
        ['event_key' => 'before-reservation', 'occurred_at' => $reservedAt->copy()->subSecond()],
        ['event_key' => 'at-expiry', 'occurred_at' => $expiresAt],
        ['event_key' => 'future-observation', 'occurred_at' => $receiptAt->copy()->addSecond()],
    ];
    $invalidResults = [];

    foreach ($invalidPayloads as $invalidPayload) {
        $response = $report([
            'execution_id' => $run->execution_id,
            'outcome' => 'success',
            'final_url' => $source->url,
            'http_status' => 200,
            ...$invalidPayload,
        ]);
        $invalidResults[] = IntegrationHelpers::decodeToolResult($response->getContent());
    }

    $this->travelTo($expiresAt);
    $expiredResponse = $report([
        'execution_id' => $run->execution_id,
        'event_key' => 'receipt-after-expiry',
        'outcome' => 'failure',
        'error' => 'late callback',
    ])->assertOk();
    $expiredResult = IntegrationHelpers::decodeToolResult($expiredResponse->getContent());
    $afterRun = $run->fresh();

    expect($receivedResult['ok'] ?? false)->toBeTrue()
        ->and($receivedResult['data']['event']['occurred_at'] ?? null)->toBe($receiptAt->toIso8601String())
        ->and($reservedBoundaryResult['ok'] ?? false)->toBeTrue()
        ->and($invalidResults)->toHaveCount(3)
        ->and(collect($invalidResults)->every(fn (array $result): bool => ($result['ok'] ?? true) === false))->toBeTrue()
        ->and($expiredResult['ok'] ?? true)->toBeFalse()
        ->and($expiredResult['error']['code'] ?? null)->toBe('conflict')
        ->and($afterRun->status)->toBe(DiscoveryRunStatus::Completed)
        ->and($afterRun->page_capture_id)->toBe($capture->id)
        ->and($afterRun->completed_at->equalTo($completedAt))->toBeTrue()
        ->and($afterRun->last_access_at->equalTo($receiptAt))->toBeTrue()
        ->and($source->fresh()->next_due_at->equalTo($nextDueAt))->toBeTrue()
        ->and($run->events()->count())->toBe(2)
        ->and(PageCapture::query()->count())->toBe(1);
});
