<?php

use App\Enums\DiscoveryRunStatus;
use App\Models\DiscoveryRun;
use App\Models\DiscoverySource;
use App\Models\PageCapture;
use App\Models\User;
use App\Services\CaptureTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function captureExecutionHtml(): string
{
    return '<main data-capture-surface="linkedin_jobs" data-capture-version="1">'
        .'<article data-block-id="card-1" data-kind="card" data-platform-id="9001" data-permalink="https://www.linkedin.com/jobs/view/9001">Vaga</article>'
        .'</main>';
}

/**
 * @return list<array<string, mixed>>
 */
function captureExecutionBlocks(): array
{
    return [[
        'block_id' => 'card-1',
        'kind' => 'card',
        'platform_id' => '9001',
        'permalink' => 'https://www.linkedin.com/jobs/view/9001',
        'card_block_id' => null,
        'detail_block_id' => null,
    ]];
}

function captureExecutionPost(User $user, array $payload): TestResponse
{
    return test()->withToken(app(CaptureTokenService::class)->issueToken($user))
        ->postJson('/api/page-captures', $payload);
}

/**
 * @return array{0: DiscoverySource, 1: DiscoveryRun, 2: array<string, mixed>}
 */
function captureExecutionFixture(User $user, int $length): array
{
    $executionId = str_repeat('e', $length);
    $source = DiscoverySource::factory()->for($user, 'user')->create();
    $run = DiscoveryRun::factory()->for($user, 'user')->create([
        'discovery_source_id' => $source->id,
        'execution_id' => $executionId,
        'idempotency_key' => 'run-'.uniqid(),
        'status' => DiscoveryRunStatus::Active,
        'active_key' => 'run:'.uniqid(),
        'canonical_url_hash' => $source->canonical_url_hash,
        'initial_url' => $source->url,
        'surface' => $source->surface,
        'expires_at' => now()->addMinutes(30),
    ]);

    $blocks = captureExecutionBlocks();

    return [$source, $run, [
        'format' => 'jobora.capture-envelope',
        'version' => 1,
        'capture' => [
            'idempotency_key' => 'execution-'.$length.'-'.uniqid(),
            'execution_id' => $executionId,
            'source' => $source->source,
            'surface' => $source->surface,
            'initial_url' => $source->url,
            'final_url' => $source->url,
            'captured_at' => now()->toIso8601String(),
            'coverage' => [
                'status' => 'complete',
                'reason' => null,
                'blocks_expected' => count($blocks),
                'blocks_captured' => count($blocks),
            ],
            'html' => captureExecutionHtml(),
        ],
        'blocks' => $blocks,
    ]];
}

beforeEach(function (): void {
    Storage::fake('local');
});

it('persists and replays execution identifiers of 65 and 200 characters', function (int $length) {
    $user = User::factory()->create();
    [$source, $run, $payload] = captureExecutionFixture($user, $length);

    captureExecutionPost($user, $payload)
        ->assertStatus(201)
        ->assertJsonPath('execution_id', str_repeat('e', $length))
        ->assertJsonPath('replay', false);

    $capture = PageCapture::query()->sole();

    expect($capture->execution_id)->toBe(str_repeat('e', $length))
        ->and($run->refresh()->status)->toBe(DiscoveryRunStatus::Completed)
        ->and($run->page_capture_id)->toBe($capture->id);

    captureExecutionPost($user, $payload)
        ->assertStatus(200)
        ->assertJsonPath('replay', true)
        ->assertJsonPath('id', $capture->id);

    expect(PageCapture::query()->count())->toBe(1)
        ->and($capture->user_id)->toBe($user->id)
        ->and($source->id)->toBe($run->discovery_source_id);
})->with([65, 200]);

it('does not shrink identifiers when V2 data exceeds the old limit', function () {
    $user = User::factory()->create();
    captureExecutionFixture($user, 65);

    $migration = require database_path('migrations/2026_11_04_320002_widen_capture_execution_identifiers.php');
    $migration->down();

    $runColumn = collect(Schema::getColumns('discovery_runs'))->firstWhere('name', 'execution_id');

    expect(DiscoveryRun::query()->where('execution_id', str_repeat('e', 65))->exists())->toBeTrue();

    if (DB::connection()->getDriverName() === 'pgsql') {
        expect($runColumn['type'])->toContain('200');
    }
});

it('keeps execution identifier metadata and uniqueness for both tables', function () {
    $runColumn = collect(Schema::getColumns('discovery_runs'))->firstWhere('name', 'execution_id');
    $captureColumn = collect(Schema::getColumns('page_captures'))->firstWhere('name', 'execution_id');

    expect($runColumn)->not->toBeNull()
        ->and($captureColumn)->not->toBeNull()
        ->and($captureColumn['nullable'])->toBeTrue()
        ->and(collect(Schema::getIndexes('discovery_runs'))
            ->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['execution_id']))->toBeTrue();

    if (DB::connection()->getDriverName() === 'pgsql') {
        expect($runColumn['type'])->toContain('200')
            ->and($captureColumn['type'])->toContain('200');
    }
});
