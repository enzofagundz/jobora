<?php

use App\Enums\DiscoveryRunStatus;
use App\Models\DiscoveryRun;
use App\Models\DiscoverySource;
use App\Models\User;
use App\Services\CaptureTokenService;
use App\Services\Discovery\CaptureValidationException;
use App\Services\Discovery\CaptureValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function laneBCoverageHtml(int $count): string
{
    $elements = '';

    for ($index = 1; $index <= $count; $index++) {
        $elements .= '<article data-block-id="p'.$index.'" data-kind="post" data-platform-id="pid'.$index.'" data-permalink="https://www.linkedin.com/feed/update/'.$index.'">Post '.$index.'</article>';
    }

    return '<main data-capture-surface="linkedin_jobs" data-capture-version="1">'.$elements.'</main>';
}

/**
 * @return list<array<string, mixed>>
 */
function laneBCoverageBlocks(int $count): array
{
    $blocks = [];

    for ($index = 1; $index <= $count; $index++) {
        $blocks[] = [
            'block_id' => 'p'.$index,
            'kind' => 'post',
            'platform_id' => 'pid'.$index,
            'permalink' => 'https://www.linkedin.com/feed/update/'.$index,
            'card_block_id' => null,
            'detail_block_id' => null,
        ];
    }

    return $blocks;
}

function laneBCoveragePost(User $user, array $payload): TestResponse
{
    return test()->withToken(app(CaptureTokenService::class)->issueToken($user))
        ->postJson('/api/page-captures', $payload);
}

function laneBCoverageEnvelope(User $user, array $coverage, ?array $blocks = null, array $captureOverrides = [], ?DiscoverySource $source = null): array
{
    $source ??= DiscoverySource::factory()->for($user, 'user')->create();
    $blocks ??= laneBCoverageBlocks(2);

    return [
        'format' => 'jobora.capture-envelope',
        'version' => 1,
        'capture' => array_merge([
            'idempotency_key' => 'coverage-'.uniqid(),
            'execution_id' => null,
            'source' => $source->source,
            'surface' => $source->surface,
            'initial_url' => $source->url,
            'final_url' => $source->url,
            'captured_at' => now()->toIso8601String(),
            'coverage' => array_merge([
                'status' => 'complete',
                'reason' => null,
                'blocks_expected' => count($blocks),
                'blocks_captured' => count($blocks),
            ], $coverage),
            'html' => laneBCoverageHtml(count($blocks)),
        ], $captureOverrides),
        'blocks' => $blocks,
    ];
}

beforeEach(function (): void {
    Storage::fake('local');
});

it('rejects coverage when captured blocks differ from the block list', function () {
    $user = User::factory()->create();

    laneBCoveragePost($user, laneBCoverageEnvelope($user, ['blocks_captured' => 1]))
        ->assertStatus(422)
        ->assertJsonPath('message', 'coverage.blocks_captured diverge da quantidade de blocos.');
});

it('rejects a complete capture whose expected blocks differ from captured', function () {
    $user = User::factory()->create();

    laneBCoveragePost($user, laneBCoverageEnvelope($user, ['blocks_expected' => 3]))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Captura completa exige blocks_expected igual a blocks_captured.');
});

it('accepts a complete capture without expected blocks', function () {
    $user = User::factory()->create();

    laneBCoveragePost($user, laneBCoverageEnvelope($user, ['blocks_expected' => null]))
        ->assertStatus(201);
});

it('rejects a partial capture whose expected blocks are below captured', function () {
    $user = User::factory()->create();

    $payload = laneBCoverageEnvelope($user, [
        'status' => 'partial',
        'reason' => 'timeout',
        'blocks_expected' => 1,
    ]);

    laneBCoveragePost($user, $payload)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Captura parcial não pode ter blocks_expected menor que blocks_captured.');
});

it('accepts a partial capture above captured and without expected', function () {
    $user = User::factory()->create();

    foreach ([3, null] as $expected) {
        laneBCoveragePost($user, laneBCoverageEnvelope($user, [
            'status' => 'partial',
            'reason' => 'cancelled',
            'blocks_expected' => $expected,
        ]))->assertStatus(201);
    }
});

it('accepts an opaque execution id inside the contract limits', function () {
    $user = User::factory()->create();
    $source = DiscoverySource::factory()->for($user, 'user')->create();
    DiscoveryRun::factory()->for($user, 'user')->create([
        'discovery_source_id' => $source->id,
        'execution_id' => 'execucao-opaca-1',
        'idempotency_key' => 'opaque-run',
        'status' => DiscoveryRunStatus::Active,
        'active_key' => 'run:opaque',
        'canonical_url_hash' => $source->canonical_url_hash,
        'initial_url' => $source->url,
        'surface' => $source->surface,
        'expires_at' => now()->addMinutes(30),
    ]);

    laneBCoveragePost($user, laneBCoverageEnvelope($user, [], source: $source, captureOverrides: ['execution_id' => 'execucao-opaca-1']))
        ->assertStatus(201)
        ->assertJsonPath('execution_id', 'execucao-opaca-1');
});

it('rejects empty and oversized execution ids', function () {
    $user = User::factory()->create();

    $empty = laneBCoverageEnvelope($user, [], captureOverrides: ['execution_id' => '']);
    expect(fn () => app(CaptureValidator::class)->validate($empty))
        ->toThrow(CaptureValidationException::class);

    laneBCoveragePost($user, laneBCoverageEnvelope($user, [], captureOverrides: ['execution_id' => str_repeat('a', 201)]))
        ->assertStatus(422);
});
