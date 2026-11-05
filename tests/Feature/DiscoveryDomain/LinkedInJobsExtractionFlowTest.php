<?php

use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Jobs\ProcessAiOperation;
use App\Mcp\Servers\JoboraServer;
use App\Mcp\Tools\Captures\GetPageCaptureTool;
use App\Mcp\Tools\Captures\ListPublicationsTool;
use App\Models\AiOperation;
use App\Models\DiscoveryRun;
use App\Models\DiscoverySource;
use App\Models\PageCapture;
use App\Models\Publication;
use App\Models\PublicationObservation;
use App\Models\User;
use App\Services\AiOperationService;
use App\Services\CaptureTokenService;
use App\Services\Discovery\CaptureBlockReader;
use App\Services\Discovery\DiscoveryRunService;
use App\Services\Discovery\LinkedInJobsDecoder;
use App\Services\Discovery\PublicationExtractionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function linkedInJobsFixture(string $file): array
{
    return json_decode(
        (string) file_get_contents(base_path('fixtures/capture-envelope/v1/'.$file)),
        true,
    );
}

/**
 * @return array{0: DiscoverySource, 1: DiscoveryRun}
 */
function linkedInJobsRun(User $user): array
{
    $url = 'https://www.linkedin.com/jobs/search/?keywords=laravel-'.uniqid();
    $source = DiscoverySource::factory()->for($user, 'user')->create([
        'source' => 'linkedin',
        'surface' => 'linkedin_jobs',
        'url' => $url,
        'canonical_url_hash' => hash('sha256', $url),
        'active' => true,
        'next_due_at' => now()->subMinute(),
    ]);

    $run = app(DiscoveryRunService::class)->acquire($user, null, 'idem-'.uniqid())->first();

    return [$source, $run];
}

/**
 * @return array{0: PageCapture, 1: AiOperation}
 */
function linkedInJobsIngest(User $user, string $fixtureFile, array $captureOverrides = []): array
{
    [$source, $run] = linkedInJobsRun($user);
    $payload = linkedInJobsFixture($fixtureFile);
    $payload['capture']['idempotency_key'] = 'linkedin-jobs-'.uniqid('', true);
    $payload['capture']['execution_id'] = $run->execution_id;
    $payload['capture']['initial_url'] = $source->url;
    $payload['capture']['final_url'] = $source->url;
    $payload['capture']['captured_at'] = now()->toIso8601String();
    $payload['capture'] = array_merge($payload['capture'], $captureOverrides);

    test()->withToken(app(CaptureTokenService::class)->issueToken($user))
        ->postJson('/api/page-captures', $payload)
        ->assertStatus(201);

    $capture = PageCapture::query()->latest('id')->firstOrFail();
    $operation = AiOperation::query()
        ->where('operation_key', 'publication_extraction:page_capture:'.$capture->id)
        ->firstOrFail();

    return [$capture, $operation];
}

beforeEach(function (): void {
    Storage::fake('local');
});

it('decodes the linkedin jobs fixture by explicit card and detail block ids', function () {
    $fixture = linkedInJobsFixture('linkedin-jobs.json');
    $blocks = app(CaptureBlockReader::class)->read(
        (string) $fixture['capture']['html'],
        'linkedin_jobs',
        1,
    );
    $jobs = app(LinkedInJobsDecoder::class)->decode($blocks);

    expect($jobs)->toHaveCount(2)
        ->and($jobs[0]['external_id'])->toBe('9000000000000000101')
        ->and($jobs[0]['url'])->toBe('https://www.linkedin.com/jobs/view/9000000000000000101')
        ->and($jobs[0]['content'])->toContain('Descrição sintética da vaga de exemplo.')
        ->and($jobs[0]['evidence_block_ids'])->toBe(['card-1', 'detail-1'])
        ->and($jobs[1]['evidence_block_ids'])->toBe(['card-2', 'detail-2']);
});

it('pairs SPA details out of order without matching titles', function () {
    $fixture = linkedInJobsFixture('linkedin-jobs-spa.json');
    $blocks = app(CaptureBlockReader::class)->read(
        (string) $fixture['capture']['html'],
        'linkedin_jobs',
        1,
    );
    $jobs = collect(app(LinkedInJobsDecoder::class)->decode($blocks))->keyBy('external_id');

    expect($jobs)->toHaveCount(2)
        ->and($jobs['9000000000000000301']['content'])->toContain('Descrição da primeira vaga')
        ->and($jobs['9000000000000000301']['content'])->not->toContain('segunda vaga')
        ->and($jobs['9000000000000000301']['evidence_block_ids'])->toBe(['card-1', 'detail-1'])
        ->and($jobs['9000000000000000302']['content'])->toContain('Descrição da segunda vaga')
        ->and($jobs['9000000000000000302']['evidence_block_ids'])->toBe(['card-2', 'detail-2']);
});

it('accepts a card without an explicit detail as partial evidence', function () {
    $fixture = linkedInJobsFixture('linkedin-jobs-card-only.json');
    $blocks = app(CaptureBlockReader::class)->read(
        (string) $fixture['capture']['html'],
        'linkedin_jobs',
        1,
    );
    $jobs = collect(app(LinkedInJobsDecoder::class)->decode($blocks))->keyBy('external_id');

    expect($jobs)->toHaveCount(2)
        ->and($jobs['9000000000000000201']['evidence_block_ids'])->toBe(['card-1', 'detail-1'])
        ->and($jobs['9000000000000000202']['content'])->toContain('Vaga Parcial sem Detalhe')
        ->and($jobs['9000000000000000202']['evidence_block_ids'])->toBe(['card-2']);
});

it('accepts an empty jobs page as valid', function () {
    $blocks = app(CaptureBlockReader::class)->read(
        '<main data-capture-surface="linkedin_jobs" data-capture-version="1"></main>',
        'linkedin_jobs',
        1,
    );

    expect($blocks)->toBe([])
        ->and(app(LinkedInJobsDecoder::class)->decode($blocks))->toBe([]);
});

it('rejects a dangling detail reference or a search url as broken jobs capture', function (string $html) {
    $blocks = app(CaptureBlockReader::class)->read($html, 'linkedin_jobs', 1);
    app(LinkedInJobsDecoder::class)->decode($blocks);
})->with([
    'detail declarado mas ausente' => [
        '<main data-capture-surface="linkedin_jobs" data-capture-version="1">'
        .'<article data-block-id="card-1" data-kind="card" data-platform-id="101" data-permalink="https://www.linkedin.com/jobs/view/101" data-detail-block-id="detail-1"></article>'
        .'</main>',
    ],
    'permalink de busca' => [
        '<main data-capture-surface="linkedin_jobs" data-capture-version="1">'
        .'<article data-block-id="card-1" data-kind="card" data-platform-id="101" data-permalink="https://www.linkedin.com/jobs/search/?keywords=php"></article>'
        .'</main>',
    ],
])->throws(PublicationExtractionException::class);

it('runs a jobs envelope through API and job and exposes the result through MCP', function () {
    $user = User::factory()->create();
    config(['jobora.mcp.user' => $user->email]);

    [$capture, $operation] = linkedInJobsIngest($user, 'linkedin-jobs.json');

    expect($operation->operation_type)->toBe(AiOperationType::PublicationExtraction)
        ->and($operation->status)->toBe(AiOperationStatus::Completed)
        ->and(Publication::query()->count())->toBe(2)
        ->and(PublicationObservation::query()->count())->toBe(2);

    JoboraServer::tool(GetPageCaptureTool::class, ['id' => $capture->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.capture.surface', 'linkedin_jobs')
            ->where('data.capture.extraction.status', 'completed')
            ->where('data.capture.extraction.counts.publications', 2)
            ->where('data.capture.extraction.counts.conflicts', 0)
            ->etc());

    JoboraServer::tool(ListPublicationsTool::class, ['page_capture_id' => $capture->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 2)
            ->where('data.items.0.publication.external_id', '9000000000000000101')
            ->where('data.items.0.publication.url', 'https://www.linkedin.com/jobs/view/9000000000000000101')
            ->where('data.items.0.publication.content_version', 1)
            ->where('data.items.0.observation.evidence_block_ids', ['card-1', 'detail-1'])
            ->where('data.items.1.observation.evidence_block_ids', ['card-2', 'detail-2'])
            ->etc());
});

it('keeps partial coverage explicit while extracting the captured jobs', function () {
    $user = User::factory()->create();
    config(['jobora.mcp.user' => $user->email]);

    [$capture] = linkedInJobsIngest($user, 'linkedin-jobs-card-only.json');

    JoboraServer::tool(GetPageCaptureTool::class, ['id' => $capture->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.capture.coverage', 'partial')
            ->where('data.capture.coverage_reason', 'timeout')
            ->where('data.capture.extraction.status', 'completed')
            ->where('data.capture.extraction.counts.publications', 2)
            ->etc());

    JoboraServer::tool(ListPublicationsTool::class, ['execution_id' => $capture->execution_id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.items.1.publication.external_id', '9000000000000000202')
            ->where('data.items.1.observation.evidence_block_ids', ['card-2'])
            ->etc());
});

it('does not duplicate jobs when the same content is recaptured or reprocessed', function () {
    $user = User::factory()->create();

    [$first] = linkedInJobsIngest($user, 'linkedin-jobs.json');
    [$second] = linkedInJobsIngest($user, 'linkedin-jobs.json');

    expect(Publication::query()->count())->toBe(2)
        ->and(PublicationObservation::query()->count())->toBe(4)
        ->and(Publication::query()->pluck('content_version')->all())->toBe([1, 1]);

    $secondOperation = AiOperation::query()
        ->where('operation_key', 'publication_extraction:page_capture:'.$second->id)
        ->sole();
    $secondOperation->delete();

    $this->artisan('publication-extractions:recover')->assertSuccessful();

    $recoveredOperation = AiOperation::query()
        ->where('operation_key', 'publication_extraction:page_capture:'.$second->id)
        ->sole();

    expect($recoveredOperation->status)->toBe(AiOperationStatus::Completed)
        ->and(Publication::query()->count())->toBe(2)
        ->and(PublicationObservation::query()->count())->toBe(4)
        ->and(PublicationObservation::query()->where('page_capture_id', $second->id)->count())->toBe(2)
        ->and($first->id)->not->toBe($second->id);
});

it('fails safely when the stored raw has fewer blocks than the capture metadata', function () {
    $user = User::factory()->create();
    config(['jobora.mcp.user' => $user->email]);

    [$capture] = linkedInJobsIngest($user, 'linkedin-jobs.json');

    $operation = AiOperation::query()
        ->where('operation_key', 'publication_extraction:page_capture:'.$capture->id)
        ->sole();
    $operation->delete();

    Storage::disk('local')->put(
        (string) $capture->html_path,
        '<main data-capture-surface="linkedin_jobs" data-capture-version="1"></main>',
    );

    $this->artisan('publication-extractions:recover')->assertSuccessful();

    $recovered = AiOperation::query()
        ->where('operation_key', 'publication_extraction:page_capture:'.$capture->id)
        ->sole();

    expect($recovered->status)->toBe(AiOperationStatus::Failed)
        ->and($recovered->failure_code)->toBe('parse_failed')
        ->and(PageCapture::query()->whereKey($capture->id)->exists())->toBeTrue()
        ->and(Publication::query()->count())->toBe(2);

    JoboraServer::tool(GetPageCaptureTool::class, ['id' => $capture->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.capture.extraction.status', 'failed')
            ->where('data.capture.extraction.error_code', 'parse_failed')
            ->where('data.capture.extraction.empty', false)
            ->where('data.capture.extraction.counts.blocks', 4)
            ->etc());
});

it('resolves a same-second changed recapture deterministically regardless of processing order', function (int $firstIndex) {
    Queue::fake([ProcessAiOperation::class]);
    $user = User::factory()->create();
    $timestamp = '2026-11-04T10:00:00Z';
    $html = (string) linkedInJobsFixture('linkedin-jobs.json')['capture']['html'];
    $htmlA = str_replace('Descrição sintética da vaga de exemplo.', 'Conteúdo A', $html);
    $htmlB = str_replace('Descrição sintética da vaga de exemplo.', 'Conteúdo B', $html);

    [$lowCapture, $lowOperation] = linkedInJobsIngest($user, 'linkedin-jobs.json', [
        'captured_at' => $timestamp,
        'html' => $htmlA,
    ]);
    [$highCapture, $highOperation] = linkedInJobsIngest($user, 'linkedin-jobs.json', [
        'captured_at' => $timestamp,
        'html' => $htmlB,
    ]);

    $operations = [$lowOperation, $highOperation];

    foreach ([[0, 1], [1, 0]][$firstIndex] as $index) {
        $operation = $operations[$index];
        (new ProcessAiOperation($operation->id, $operation->run_token, $operation->attempt_token))
            ->handle(app(AiOperationService::class));
    }

    $publication = Publication::query()->where('external_id', '9000000000000000101')->sole();
    $lowObservation = PublicationObservation::query()
        ->where('page_capture_id', $lowCapture->id)
        ->where('publication_id', $publication->id)
        ->sole();
    $highObservation = PublicationObservation::query()
        ->where('page_capture_id', $highCapture->id)
        ->where('publication_id', $publication->id)
        ->sole();

    $expected = $firstIndex === 0
        ? ['publication' => 2, 'low' => 1, 'high' => 2]
        : ['publication' => 1, 'low' => null, 'high' => 1];

    expect($publication->content)->toContain('Conteúdo B')
        ->and($publication->content_version)->toBe($expected['publication'])
        ->and($lowObservation->content_version)->toBe($expected['low'])
        ->and($highObservation->content_version)->toBe($expected['high']);
})->with([
    'oldest capture first' => [0],
    'newest capture first' => [1],
]);

it('isolates jobs captures and publications between users', function () {
    $owner = User::factory()->create();
    config(['jobora.mcp.user' => $owner->email]);
    [$capture] = linkedInJobsIngest($owner, 'linkedin-jobs.json');

    $other = User::factory()->create();
    config(['jobora.mcp.user' => $other->email]);

    JoboraServer::tool(GetPageCaptureTool::class, ['id' => $capture->id])
        ->assertHasErrors();

    JoboraServer::tool(ListPublicationsTool::class, ['page_capture_id' => $capture->id])
        ->assertHasErrors();
});

it('processes jobs through a real database queue worker', function () {
    config([
        'queue.default' => 'database',
        'queue.connections.database.after_commit' => false,
    ]);

    $user = User::factory()->create();
    [$capture, $operation] = linkedInJobsIngest($user, 'linkedin-jobs.json');

    expect($operation->refresh()->status)->toBe(AiOperationStatus::Queued)
        ->and(Publication::query()->count())->toBe(0);

    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

    expect($operation->refresh()->status)->toBe(AiOperationStatus::Completed)
        ->and(Publication::query()->count())->toBe(2)
        ->and($capture->observations()->count())->toBe(2);
});
