<?php

use App\Enums\DiscoveryRunStatus;
use App\Models\DiscoveryRun;
use App\Models\DiscoverySource;
use App\Models\PageCapture;
use App\Models\User;
use App\Services\CaptureTokenService;
use App\Services\Discovery\DiscoveryRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function captureBlocks(): array
{
    return [
        [
            'block_id' => 'card-1',
            'kind' => 'card',
            'platform_id' => 'job-1',
            'permalink' => 'https://www.linkedin.com/jobs/view/1',
            'card_block_id' => null,
            'detail_block_id' => 'detail-1',
        ],
        [
            'block_id' => 'detail-1',
            'kind' => 'detail',
            'platform_id' => 'job-1',
            'permalink' => 'https://www.linkedin.com/jobs/view/1',
            'card_block_id' => 'card-1',
            'detail_block_id' => null,
        ],
    ];
}

function captureHtml(string $extra = ''): string
{
    return '<main data-capture-surface="linkedin_jobs" data-capture-version="1">'.$extra
        .'<article data-block-id="card-1" data-kind="card" data-platform-id="job-1" data-permalink="https://www.linkedin.com/jobs/view/1" data-detail-block-id="detail-1"><h2>Vaga PHP</h2></article>'
        .'<section data-block-id="detail-1" data-kind="detail" data-platform-id="job-1" data-permalink="https://www.linkedin.com/jobs/view/1" data-card-block-id="card-1"><p>Descrição</p></section>'
        .'</main>';
}

function captureEnvelope(User $user, ?DiscoveryRun $run, array $overrides = []): array
{
    $source = $run?->source ?? DiscoverySource::factory()->for($user, 'user')->create();

    $capture = array_merge([
        'idempotency_key' => 'capture-'.uniqid(),
        'execution_id' => $run?->execution_id,
        'source' => $source->source,
        'surface' => $source->surface,
        'initial_url' => $source->url,
        'final_url' => $source->url,
        'captured_at' => now()->toIso8601String(),
        'coverage' => [
            'status' => 'complete',
            'reason' => null,
            'blocks_expected' => 2,
            'blocks_captured' => 2,
        ],
        'html' => captureHtml(),
    ], $overrides['capture'] ?? []);

    return [
        'format' => 'jobora.capture-envelope',
        'version' => 1,
        'capture' => $capture,
        'blocks' => $overrides['blocks'] ?? captureBlocks(),
    ];
}

function capturePost(User $user, array $payload): TestResponse
{
    $token = app(CaptureTokenService::class)->issueToken($user);

    return test()->withToken($token)->postJson('/api/page-captures', $payload);
}

beforeEach(function (): void {
    Storage::fake('local');
});

it('ingests a complete capture durably and completes the run', function () {
    $user = User::factory()->create();
    $source = DiscoverySource::factory()->for($user, 'user')->create([
        'active' => true,
        'next_due_at' => now()->subMinute(),
    ]);
    $run = app(DiscoveryRunService::class)->acquire($user, null, 'idem')->first();
    $payload = captureEnvelope($user, $run);

    capturePost($user, $payload)
        ->assertStatus(201)
        ->assertJsonPath('execution_id', $run->execution_id)
        ->assertJsonPath('coverage', 'complete')
        ->assertJsonPath('replay', false);

    $capture = PageCapture::query()->sole();

    expect($capture->ingest_status)->toBe('received')
        ->and($capture->payload_hash)->not->toBeNull()
        ->and(Storage::disk('local')->exists((string) $capture->html_path))->toBeTrue();

    expect($run->refresh()->status)->toBe(DiscoveryRunStatus::Completed)
        ->and($run->completion_status)->toBe('complete')
        ->and($run->page_capture_id)->toBe($capture->id)
        ->and($run->active_key)->toBeNull()
        ->and($source->refresh()->last_success_at)->not->toBeNull()
        ->and($source->next_due_at->isFuture())->toBeTrue();

    capturePost($user, $payload)
        ->assertStatus(200)
        ->assertJsonPath('replay', true);

    expect(PageCapture::query()->count())->toBe(1);
});

it('rejects the same idempotency key with a different canonical payload', function () {
    $user = User::factory()->create();
    $payload = captureEnvelope($user, null);

    capturePost($user, $payload)->assertStatus(201);

    $changed = $payload;
    $changed['capture']['html'] = captureHtml('<p>mudou</p>');

    capturePost($user, $changed)->assertStatus(409);
});

it('marks partial coverage distinctly without a successful source timestamp', function () {
    $user = User::factory()->create();
    DiscoverySource::factory()->for($user, 'user')->create([
        'active' => true,
        'next_due_at' => now()->subMinute(),
    ]);
    $run = app(DiscoveryRunService::class)->acquire($user, null, 'idem-partial')->first();
    $payload = captureEnvelope($user, $run, [
        'capture' => ['coverage' => [
            'status' => 'partial',
            'reason' => 'timeout',
            'blocks_expected' => 5,
            'blocks_captured' => 2,
        ]],
    ]);

    capturePost($user, $payload)->assertStatus(201);

    expect($run->refresh()->completion_status)->toBe('partial')
        ->and($run->status)->toBe(DiscoveryRunStatus::Completed)
        ->and($run->source->refresh()->last_success_at)->toBeNull();
});

it('accepts a manual capture without execution id and does not complete any run', function () {
    $user = User::factory()->create();

    $payload = captureEnvelope($user, null, ['capture' => ['execution_id' => null]]);

    capturePost($user, $payload)->assertStatus(201);

    expect(PageCapture::query()->count())->toBe(1)
        ->and(DiscoveryRun::query()->count())->toBe(0);
});

it('rejects foreign, expired and mismatched runs with conflict', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    DiscoverySource::factory()->for($owner, 'user')->create([
        'active' => true,
        'next_due_at' => now()->subMinute(),
    ]);
    $run = app(DiscoveryRunService::class)->acquire($owner, null, 'idem-exp')->first();

    capturePost($intruder, captureEnvelope($intruder, $run))->assertStatus(409);

    app(DiscoveryRunService::class)->expireDue($run->expires_at->copy()->addMinute());

    capturePost($owner, captureEnvelope($owner, $run))->assertStatus(409);

    expect(PageCapture::query()->count())->toBe(0);
});

it('returns 422 for an invalid envelope and 413 above configured size', function () {
    $user = User::factory()->create();
    $payload = captureEnvelope($user, null);
    $payload['capture']['unexpected'] = true;

    capturePost($user, $payload)->assertStatus(422);

    config(['capture.ingestion.html_max_bytes' => 100]);

    $large = captureEnvelope($user, null, ['capture' => ['html' => captureHtml(str_repeat('x', 200))]]);

    capturePost($user, $large)->assertStatus(413);
});

it('sanitizes executable content and sensitive attributes before storage', function () {
    $user = User::factory()->create();
    $payload = captureEnvelope($user, null, [
        'capture' => ['html' => captureHtml('<script>alert(1)</script><p onclick="evil()" data-password="segredo" style="color:red">ok</p>')],
    ]);

    capturePost($user, $payload)->assertStatus(201);

    $capture = PageCapture::query()->sole();
    $stored = Storage::disk('local')->get((string) $capture->html_path);

    expect($stored)->not->toContain('<script')
        ->and($stored)->not->toContain('onclick')
        ->and($stored)->not->toContain('data-password')
        ->and($stored)->not->toContain('style=')
        ->and($stored)->toContain('data-block-id="card-1"')
        ->and($stored)->toContain('data-detail-block-id="detail-1"');
});

it('does not confirm the capture when private storage fails', function () {
    $user = User::factory()->create();

    Storage::shouldReceive('disk')->andReturnSelf();
    Storage::shouldReceive('put')->andReturn(false);

    capturePost($user, captureEnvelope($user, null))->assertStatus(503);

    expect(PageCapture::query()->count())->toBe(0);
});

it('prunes only the raw html after retention and keeps metadata', function () {
    $user = User::factory()->create();
    $payload = captureEnvelope($user, null);
    capturePost($user, $payload)->assertStatus(201);

    $capture = PageCapture::query()->sole();
    $path = (string) $capture->html_path;
    $capture->forceFill(['raw_expires_at' => now()->subDay()])->save();

    $this->artisan('captures:prune-raw')->assertSuccessful();

    expect(Storage::disk('local')->exists($path))->toBeFalse()
        ->and(PageCapture::query()->whereKey($capture->id)->exists())->toBeTrue()
        ->and($capture->refresh()->html_path)->toBeNull()
        ->and($capture->raw_deleted_at)->not->toBeNull();
});

it('reconciles orphan objects and expired metadata without deleting recent rows', function () {
    $user = User::factory()->create();
    Storage::disk('local')->put('capture-envelopes/orphan.html', 'x');
    touch(Storage::disk('local')->path('capture-envelopes/orphan.html'), now()->subDay()->getTimestamp());

    $payload = captureEnvelope($user, null);
    capturePost($user, $payload)->assertStatus(201);
    $capture = PageCapture::query()->sole();
    Storage::disk('local')->delete((string) $capture->html_path);
    $capture->forceFill(['raw_expires_at' => now()->subDay()])->save();

    $this->artisan('captures:reconcile-orphans')->assertSuccessful();

    expect(Storage::disk('local')->exists('capture-envelopes/orphan.html'))->toBeFalse()
        ->and($capture->refresh()->html_path)->toBeNull();
});
