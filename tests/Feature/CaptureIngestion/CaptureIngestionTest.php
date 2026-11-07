<?php

use App\Enums\DiscoveryRunStatus;
use App\Models\DiscoveryRun;
use App\Models\DiscoverySource;
use App\Models\PageCapture;
use App\Models\User;
use App\Services\CaptureTokenService;
use App\Services\Discovery\DiscoveryRunService;
use App\Services\Discovery\DiscoverySourceUrl;
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
        ->and($run->source->refresh()->last_success_at)->toBeNull()
        ->and($run->source->next_due_at->equalTo($run->completed_at->copy()->addMinutes($run->source->frequency_minutes)))->toBeTrue();
});

it('acknowledges empty no-content captures but retries only at the original reservation expiry', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $source = DiscoverySource::factory()->for($user, 'user')->create(['frequency_minutes' => 1440, 'last_success_at' => now()->subDay()]);
    $run = app(DiscoveryRunService::class)->acquire($user, null, 'empty-reservation')->sole();
    $lastSuccess = $source->last_success_at->copy();
    $this->travel(2)->minutes();
    $payload = captureEnvelope($user, $run, ['blocks' => [], 'capture' => [
        'coverage' => ['status' => 'partial', 'reason' => 'no_content', 'blocks_expected' => 0, 'blocks_captured' => 0],
        'html' => '<main data-capture-surface="linkedin_jobs" data-capture-version="1"></main>',
    ]]);
    capturePost($user, $payload)->assertCreated()->assertJsonPath('coverage', 'partial');
    expect($run->refresh()->status)->toBe(DiscoveryRunStatus::Completed)
        ->and($source->refresh()->next_due_at->equalTo($run->expires_at))->toBeTrue()
        ->and($source->last_success_at->equalTo($lastSuccess))->toBeTrue();
    $this->travel(1)->minutes();
    capturePost($user, $payload)->assertOk()->assertJsonPath('replay', true);
    expect($source->refresh()->next_due_at->equalTo($run->expires_at))->toBeTrue();
    expect(app(DiscoveryRunService::class)->acquire($user, null, 'not-yet-due'))->toBeEmpty();
    $this->travelTo($run->expires_at);
    expect(app(DiscoveryRunService::class)->acquire($user, null, 'due-at-expiry'))->toHaveCount(1);
});

it('rejects semantic filter changes in the final URL before persisting captures or schedules', function () {
    $user = User::factory()->create();
    $source = DiscoverySource::factory()->for($user, 'user')->create();
    $run = app(DiscoveryRunService::class)->acquire($user, null, 'final-filter-change')->sole();
    $due = $source->next_due_at->copy();
    $payload = captureEnvelope($user, $run, ['capture' => ['final_url' => $source->url.'&f_TPR=r86400']]);
    capturePost($user, $payload)->assertUnprocessable();
    expect(PageCapture::query()->count())->toBe(0)
        ->and($run->refresh()->status)->toBe(DiscoveryRunStatus::Active)
        ->and($source->refresh()->next_due_at->equalTo($due))->toBeTrue()
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('correlates both observed Jobs URLs with the frozen reservation and preserves real capture provenance', function () {
    $this->travelTo(now()->startOfSecond());
    $user = User::factory()->create();
    $url = 'https://www.linkedin.com/jobs/search/?keywords=Laravel&f_WT=2&custom=kept';
    $source = DiscoverySource::factory()->for($user, 'user')->create(['url' => $url, 'canonical_url_hash' => app(DiscoverySourceUrl::class)->hash($url), 'frequency_minutes' => 60]);
    $run = app(DiscoveryRunService::class)->acquire($user, null, 'observed-capture')->sole();
    $initial = str_replace('/jobs/search/', '/jobs/search-results', $url).'&skipRedirect=true&infoNotice=ai-job-search';
    $final = $initial.'&currentJobId=123';
    $payload = captureEnvelope($user, $run, ['capture' => ['initial_url' => $initial, 'final_url' => $final]]);
    capturePost($user, $payload)->assertCreated();
    expect(PageCapture::query()->sole()->url)->toBe($initial)
        ->and($run->refresh()->initial_url)->toBe($url)
        ->and($source->refresh()->next_due_at->equalTo(now()->addMinutes(60)))->toBeTrue()
        ->and($source->last_success_at->equalTo(now()))->toBeTrue();
});

it('accepts known tracking decorations in both observed Jobs URLs without changing their provenance', function () {
    $user = User::factory()->create();
    $source = DiscoverySource::factory()->for($user, 'user')->create();
    $run = app(DiscoveryRunService::class)->acquire($user, null, 'capture-tracking')->sole();
    $initial = str_replace('/jobs/search/', '/jobs/search-results', $source->url).'&trk=abc&utm_source=synthetic';
    $payload = captureEnvelope($user, $run, ['capture' => ['initial_url' => $initial, 'final_url' => $initial.'&currentJobId=123&utm_campaign=smoke']]);
    capturePost($user, $payload)->assertCreated();
    expect(PageCapture::query()->sole()->url)->toBe($initial);
});

it('returns typed validation errors for unsafe or semantically changed final Jobs URLs without side effects', function (string $final) {
    $user = User::factory()->create();
    $url = 'https://www.linkedin.com/jobs/search/?keywords=Laravel&f_WT=2';
    $source = DiscoverySource::factory()->for($user, 'user')->create(['url' => $url, 'canonical_url_hash' => app(DiscoverySourceUrl::class)->hash($url)]);
    $run = app(DiscoveryRunService::class)->acquire($user, null, 'invalid-final')->sole();
    capturePost($user, captureEnvelope($user, $run, ['capture' => ['final_url' => $final]]))->assertUnprocessable()->assertJsonStructure(['message']);
    expect(PageCapture::query()->count())->toBe(0)
        ->and($run->refresh()->status)->toBe(DiscoveryRunStatus::Active)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
})->with([
    'credentials' => ['https://person:password@www.linkedin.com/jobs/search-results?keywords=Laravel&f_WT=2'],
    'other host' => ['https://other.example/jobs/search-results?keywords=Laravel&f_WT=2'],
    'different allowed host' => ['https://br.linkedin.com/jobs/search-results?keywords=Laravel&f_WT=2'],
    'other surface' => ['https://www.linkedin.com/search/results/content/?keywords=Laravel&f_WT=2'],
    'other path' => ['https://www.linkedin.com/jobs/search-results-extra?keywords=Laravel&f_WT=2'],
    'changed filter' => ['https://www.linkedin.com/jobs/search-results?keywords=Laravel&f_WT=1'],
    'added salary' => ['https://www.linkedin.com/jobs/search-results?keywords=Laravel&f_WT=2&f_SAL=salary'],
    'added period' => ['https://www.linkedin.com/jobs/search-results?keywords=Laravel&f_WT=2&f_TPR=r86400'],
    'duplicate keyword' => ['https://www.linkedin.com/jobs/search-results?keywords=PHP&keywords=Laravel&f_WT=2'],
    'array period' => ['https://www.linkedin.com/jobs/search-results?keywords=Laravel&f_WT=2&f_TPR[]=r86400'],
]);

it('maps invalid initial source URLs to 422 instead of leaking an invalid argument exception', function () {
    $user = User::factory()->create();
    $payload = captureEnvelope($user, null, ['capture' => ['initial_url' => 'https://www.linkedin.com/feed/']]);
    capturePost($user, $payload)->assertUnprocessable()->assertJsonStructure(['message']);
    expect(PageCapture::query()->count())->toBe(0);
});

it('accepts manual Jobs capture on the redirected path without inventing a reservation', function () {
    $user = User::factory()->create();
    $url = 'https://www.linkedin.com/jobs/search-results?keywords=Laravel&f_WT=2&skipRedirect=true';
    $payload = captureEnvelope($user, null, ['capture' => ['initial_url' => $url, 'final_url' => $url.'&currentJobId=123']]);
    capturePost($user, $payload)->assertCreated()->assertJsonPath('execution_id', null);
    expect(DiscoveryRun::query()->count())->toBe(0)
        ->and(PageCapture::query()->sole()->url)->toBe($url);
});

it('does not overwrite a source edited or disabled while its empty run is reserved', function (string $change) {
    $this->freezeTime();
    $user = User::factory()->create();
    $source = DiscoverySource::factory()->for($user, 'user')->create(['frequency_minutes' => 1440]);
    $run = app(DiscoveryRunService::class)->acquire($user, null, 'source-edited')->sole();
    $payload = captureEnvelope($user, $run, ['blocks' => [], 'capture' => [
        'coverage' => ['status' => 'partial', 'reason' => 'no_content', 'blocks_expected' => 0, 'blocks_captured' => 0],
        'html' => '<main data-capture-surface="linkedin_jobs" data-capture-version="1"></main>',
    ]]);
    $this->travel(1)->minutes();
    $attributes = match ($change) {
        'postponed' => ['next_due_at' => now()->addDays(2)],
        'disabled' => ['active' => false, 'next_due_at' => null],
        'unscheduled' => ['next_due_at' => null],
        'identity' => ['url' => $source->url.'&f_WT=2', 'canonical_url_hash' => app(DiscoverySourceUrl::class)->hash($source->url.'&f_WT=2')],
    };
    $source->update([...$attributes, 'version' => 2]);
    $before = $source->fresh()->getAttributes();
    capturePost($user, $payload)->assertCreated();
    expect($source->fresh()->getAttributes())->toBe($before)
        ->and($run->refresh()->status)->toBe(DiscoveryRunStatus::Completed);
})->with(['postponed', 'disabled', 'unscheduled', 'identity']);

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
