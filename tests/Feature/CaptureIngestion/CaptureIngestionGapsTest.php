<?php

use App\Enums\DiscoveryRunStatus;
use App\Mcp\Servers\JoboraServer;
use App\Mcp\Tools\Captures\GetPageCaptureTool;
use App\Models\DiscoveryRun;
use App\Models\DiscoverySource;
use App\Models\PageCapture;
use App\Models\Publication;
use App\Models\PublicationObservation;
use App\Models\User;
use App\Services\CaptureTokenService;
use App\Services\Discovery\CaptureIngestionService;
use App\Services\Discovery\DiscoveryRunService;
use App\Services\Discovery\ObservationRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;
use Tests\Feature\Mcp\McpTestHelpers;

uses(RefreshDatabase::class);

function gapHtml(string $suffix = ''): string
{
    return '<main data-capture-surface="linkedin_jobs" data-capture-version="1"><article data-block-id="card-1" data-kind="card" data-platform-id="p1" data-permalink="https://www.linkedin.com/jobs/view/1">Vaga '.$suffix.'</article></main>';
}

function gapBlocks(): array
{
    return [[
        'block_id' => 'card-1',
        'kind' => 'card',
        'platform_id' => 'p1',
        'permalink' => 'https://www.linkedin.com/jobs/view/1',
        'card_block_id' => null,
        'detail_block_id' => null,
    ]];
}

function gapEnvelope(User $user, ?DiscoveryRun $run, string $key, ?string $html = null, array $captureOverrides = [], ?array $blocks = null): array
{
    $source = $run?->source ?? DiscoverySource::factory()->for($user, 'user')->create();

    return [
        'format' => 'jobora.capture-envelope',
        'version' => 1,
        'capture' => array_merge([
            'idempotency_key' => $key,
            'execution_id' => $run?->execution_id,
            'source' => $source->source,
            'surface' => $source->surface,
            'initial_url' => $source->url,
            'final_url' => $source->url,
            'captured_at' => now()->toIso8601String(),
            'coverage' => ['status' => 'complete', 'reason' => null, 'blocks_expected' => 1, 'blocks_captured' => 1],
            'html' => $html ?? gapHtml(),
        ], $captureOverrides),
        'blocks' => $blocks ?? gapBlocks(),
    ];
}

function gapPost(User $user, array $payload): TestResponse
{
    return test()->withToken(app(CaptureTokenService::class)->issueToken($user))
        ->postJson('/api/page-captures', $payload);
}

beforeEach(function (): void {
    Storage::fake('local');
});

it('returns the existing capture on replay after the run is already completed', function () {
    $user = User::factory()->create();
    DiscoverySource::factory()->for($user, 'user')->create(['active' => true, 'next_due_at' => now()->subMinute()]);
    $run = app(DiscoveryRunService::class)->acquire($user, null, 'run')->first();
    $payload = gapEnvelope($user, $run, 'same-key');

    gapPost($user, $payload)->assertStatus(201);
    expect($run->refresh()->status)->toBe(DiscoveryRunStatus::Completed);

    gapPost($user, $payload)->assertStatus(200)->assertJsonPath('replay', true);

    expect(PageCapture::query()->count())->toBe(1);
});

it('treats a recursive key permutation as the same canonical payload', function () {
    $user = User::factory()->create();
    $payload = gapEnvelope($user, null, 'perm-key');

    gapPost($user, $payload)->assertStatus(201);

    $reordered = $payload;
    $reordered['capture'] = array_reverse($payload['capture'], true);
    $reordered['capture']['coverage'] = array_reverse($payload['capture']['coverage'], true);
    $reordered['blocks'][0] = array_reverse($payload['blocks'][0], true);

    gapPost($user, $reordered)->assertStatus(200)->assertJsonPath('replay', true);
});

it('fails explicitly above payload and block limits without truncating', function () {
    $user = User::factory()->create();

    $bigHtml = str_repeat('x', 12 * 1024 * 1024);
    gapPost($user, gapEnvelope($user, null, 'big', $bigHtml))->assertStatus(413);

    $blocks = [];
    for ($i = 0; $i <= 1000; $i++) {
        $blocks[] = array_merge(gapBlocks()[0], ['block_id' => 'card-'.$i]);
    }

    gapPost($user, gapEnvelope($user, null, 'blocks', blocks: $blocks))->assertStatus(413);
});

it('does not confirm the run and leaves a reconcilable orphan when metadata persistence fails', function () {
    $user = User::factory()->create();
    DiscoverySource::factory()->for($user, 'user')->create(['active' => true, 'next_due_at' => now()->subMinute()]);
    $run = app(DiscoveryRunService::class)->acquire($user, null, 'run')->first();
    $payload = gapEnvelope($user, $run, 'metadata-fail');

    Event::listen('eloquent.creating: '.PageCapture::class, function (): void {
        throw new RuntimeException('metadata down');
    });

    try {
        $response = gapPost($user, $payload)->assertStatus(500);
        expect($response->exceptions->contains(fn (Throwable $exception): bool => $exception instanceof RuntimeException && $exception->getMessage() === 'metadata down'))->toBeTrue();
    } finally {
        Event::forget('eloquent.creating: '.PageCapture::class);
    }

    expect(PageCapture::query()->count())->toBe(0)
        ->and($run->refresh()->status)->toBe(DiscoveryRunStatus::Active)
        ->and($run->page_capture_id)->toBeNull();

    $files = Storage::disk('local')->allFiles('capture-envelopes');
    expect($files)->toHaveCount(1);

    foreach ($files as $file) {
        touch(Storage::disk('local')->path($file), now()->subDay()->getTimestamp());
    }

    $this->artisan('captures:reconcile-orphans')->assertSuccessful();

    expect(Storage::disk('local')->allFiles('capture-envelopes'))->toHaveCount(0);
});

it('keeps publications and observations while pruning raw html', function () {
    $user = User::factory()->create();
    gapPost($user, gapEnvelope($user, null, 'retention'))->assertStatus(201);

    $capture = PageCapture::query()->sole();
    $publication = Publication::factory()->for($user, 'user')->create([
        'source' => $capture->source,
        'canonical_url_hash' => hash('sha256', 'publication-1'),
    ]);
    $observation = app(ObservationRecorder::class)->record($capture, $publication);

    $capture->forceFill(['raw_expires_at' => now()->subDay()])->save();

    $this->artisan('captures:prune-raw')->assertSuccessful();

    expect(PageCapture::query()->whereKey($capture->id)->exists())->toBeTrue()
        ->and(Publication::query()->whereKey($publication->id)->exists())->toBeTrue()
        ->and(PublicationObservation::query()->whereKey($observation->id)->exists())->toBeTrue();
});

it('keeps oauth tokens away from the capture ingestion api', function () {
    $user = User::factory()->create();
    $client = Helpers::client();
    Helpers::integration($user, $client);
    $oauth = Helpers::accessToken($user, $client);

    $this->withToken($oauth)
        ->postJson('/api/page-captures', gapEnvelope($user, null, 'oauth'))
        ->assertStatus(401);

    gapPost($user, gapEnvelope($user, null, 'capture'))->assertStatus(201);
});

it('returns metadata without raw html through the read tool', function () {
    Storage::fake('local');
    $user = McpTestHelpers::user();
    [$capture] = app(CaptureIngestionService::class)->ingest($user, gapEnvelope($user, null, 'mcp-get'));

    JoboraServer::tool(GetPageCaptureTool::class, ['id' => $capture->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.capture.id', $capture->id)
            ->etc());

    JoboraServer::tool(GetPageCaptureTool::class, ['id' => $capture->id])
        ->assertDontSee('data-block-id')
        ->assertDontSee('capture-envelopes');
});
