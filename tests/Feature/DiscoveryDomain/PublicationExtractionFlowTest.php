<?php

use App\Enums\AgentIntegrationScope;
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
use App\Models\PublicationIdentityConflict;
use App\Models\PublicationObservation;
use App\Models\User;
use App\Services\AiOperationService;
use App\Services\CaptureTokenService;
use App\Services\Discovery\CaptureBlockReader;
use App\Services\Discovery\DiscoveryRunService;
use App\Services\Discovery\LinkedInPublicationsDecoder;
use App\Services\Discovery\PublicationExtractionException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * @param  list<array{block_id: string, platform_id: string, permalink: string, text: string}>  $posts
 */
function publicationExtractionEnvelope(array $posts, ?string $capturedAt = null): array
{
    $source = 'linkedin';
    $surface = 'linkedin_publications';

    $html = '<main data-capture-surface="'.$surface.'" data-capture-version="1">';

    foreach ($posts as $post) {
        $html .= '<article data-block-id="'.$post['block_id'].'" data-kind="post"'
            .' data-platform-id="'.$post['platform_id'].'"'
            .' data-permalink="'.$post['permalink'].'">'
            .'<h2>'.$post['text'].'</h2></article>';
    }

    $html .= '</main>';

    return [
        'format' => 'jobora.capture-envelope',
        'version' => 1,
        'capture' => [
            'idempotency_key' => 'publication-'.uniqid('', true),
            'execution_id' => null,
            'source' => $source,
            'surface' => $surface,
            'initial_url' => 'https://www.linkedin.com/search/results/content/?keywords=laravel',
            'final_url' => 'https://www.linkedin.com/search/results/content/?keywords=laravel',
            'captured_at' => $capturedAt ?? now()->toIso8601String(),
            'coverage' => [
                'status' => 'complete',
                'reason' => null,
                'blocks_expected' => count($posts),
                'blocks_captured' => count($posts),
            ],
            'html' => $html,
        ],
        'blocks' => array_map(fn (array $post): array => [
            'block_id' => $post['block_id'],
            'kind' => 'post',
            'platform_id' => $post['platform_id'],
            'permalink' => $post['permalink'],
            'card_block_id' => null,
            'detail_block_id' => null,
        ], $posts),
    ];
}

/**
 * @return array{0: DiscoverySource, 1: DiscoveryRun}
 */
function publicationExtractionRun(User $user): array
{
    $url = 'https://www.linkedin.com/search/results/content/?keywords=laravel-'.uniqid();
    $source = DiscoverySource::factory()->for($user, 'user')->create([
        'source' => 'linkedin',
        'surface' => 'linkedin_publications',
        'url' => $url,
        'canonical_url_hash' => hash('sha256', $url),
        'active' => true,
        'next_due_at' => now()->subMinute(),
    ]);

    $run = app(DiscoveryRunService::class)->acquire($user, null, 'idem-'.uniqid())->first();

    return [$source, $run];
}

function publicationExtractionPost(User $user, array $payload): TestResponse
{
    return test()->withToken(app(CaptureTokenService::class)->issueToken($user))
        ->postJson('/api/page-captures', $payload);
}

/**
 * @param  list<array{block_id: string, platform_id: string, permalink: string, text: string}>  $posts
 * @return array{0: PageCapture, 1: AiOperation}
 */
function publicationExtractionIngest(User $user, array $posts, ?string $capturedAt = null): array
{
    [$source, $run] = publicationExtractionRun($user);
    $payload = publicationExtractionEnvelope($posts, $capturedAt);
    $payload['capture']['execution_id'] = $run->execution_id;
    $payload['capture']['initial_url'] = $source->url;
    $payload['capture']['final_url'] = $source->url;

    publicationExtractionPost($user, $payload)->assertStatus(201);

    $capture = PageCapture::query()->latest('id')->firstOrFail();
    $operation = AiOperation::query()
        ->where('operation_key', 'publication_extraction:page_capture:'.$capture->id)
        ->firstOrFail();

    return [$capture, $operation];
}

beforeEach(function (): void {
    Storage::fake('local');
});

it('decodes the public linkedin publications fixture deterministically', function () {
    $fixture = json_decode((string) file_get_contents(base_path('fixtures/capture-envelope/v1/linkedin-publications.json')), true);
    $blocks = app(CaptureBlockReader::class)->read(
        (string) $fixture['capture']['html'],
        'linkedin_publications',
        1,
    );
    $publications = app(LinkedInPublicationsDecoder::class)->decode($blocks);

    expect($publications)->toHaveCount(2)
        ->and($publications[0]['external_id'])->toBe('urn:li:activity:9000000000000000011')
        ->and($publications[0]['url'])->toBe('https://www.linkedin.com/feed/update/urn:li:activity:9000000000000000011')
        ->and($publications[0]['content'])->toContain('Publicação Exemplo 1')
        ->and($publications[0]['evidence_block_ids'])->toBe(['post-1']);
});

it('accepts an empty publications page as valid', function () {
    $blocks = app(CaptureBlockReader::class)->read(
        '<main data-capture-surface="linkedin_publications" data-capture-version="1"></main>',
        'linkedin_publications',
        1,
    );

    expect($blocks)->toBe([])
        ->and(app(LinkedInPublicationsDecoder::class)->decode($blocks))->toBe([]);
});

it('rejects a broken publications document with a safe parse failure', function () {
    app(CaptureBlockReader::class)->read(
        '<main data-capture-version="1"><article data-kind="post"></article></main>',
        'linkedin_publications',
        1,
    );
})->throws(PublicationExtractionException::class);

it('reports raw_unavailable safely without undoing the capture', function () {
    Queue::fake([ProcessAiOperation::class]);
    $user = User::factory()->create();
    config(['jobora.mcp.user' => $user->email]);

    [$capture, $operation] = publicationExtractionIngest($user, [[
        'block_id' => 'post-1',
        'platform_id' => 'urn:li:activity:60',
        'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:60',
        'text' => 'Vaga sem HTML',
    ]]);

    Storage::disk('local')->delete((string) $capture->html_path);

    (new ProcessAiOperation($operation->id, $operation->run_token, $operation->attempt_token))
        ->handle(app(AiOperationService::class));

    expect($operation->refresh()->status)->toBe(AiOperationStatus::Failed)
        ->and($operation->failure_code)->toBe('raw_unavailable');

    JoboraServer::tool(GetPageCaptureTool::class, ['id' => $capture->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.capture.extraction.status', 'failed')
            ->where('data.capture.extraction.error_code', 'raw_unavailable')
            ->etc());
});

it('reports parse_failed safely and preserves prior publications', function () {
    $user = User::factory()->create();
    config(['jobora.mcp.user' => $user->email]);

    [$capture] = publicationExtractionIngest($user, [[
        'block_id' => 'post-1',
        'platform_id' => 'urn:li:activity:61',
        'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:61',
        'text' => 'Vaga preservada',
    ]]);

    $operation = AiOperation::query()->sole();
    $operation->delete();
    Storage::disk('local')->put((string) $capture->html_path, '<main data-capture-version="1"></main>');

    $this->artisan('publication-extractions:recover')->assertSuccessful();

    $recovered = AiOperation::query()->sole();

    expect($recovered->status)->toBe(AiOperationStatus::Failed)
        ->and($recovered->failure_code)->toBe('parse_failed')
        ->and(Publication::query()->count())->toBe(1);

    JoboraServer::tool(GetPageCaptureTool::class, ['id' => $capture->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.capture.extraction.status', 'failed')
            ->where('data.capture.extraction.error_code', 'parse_failed')
            ->where('data.capture.extraction.counts.publications', 1)
            ->etc());
});

it('keeps the capture ACK when the extraction operation cannot be created', function () {
    $user = User::factory()->create();
    [$source, $run] = publicationExtractionRun($user);

    $this->mock(AiOperationService::class, function ($mock): void {
        $mock->shouldReceive('enqueuePublicationExtraction')->andThrow(new RuntimeException('dispatch down'));
    });

    $payload = publicationExtractionEnvelope([[
        'block_id' => 'post-1',
        'platform_id' => 'urn:li:activity:1',
        'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:1',
        'text' => 'Vaga PHP',
    ]]);
    $payload['capture']['execution_id'] = $run->execution_id;
    $payload['capture']['initial_url'] = $source->url;
    $payload['capture']['final_url'] = $source->url;

    publicationExtractionPost($user, $payload)->assertStatus(201);

    expect(PageCapture::query()->count())->toBe(1);
});

it('runs HTTP ingestion through the public job and exposes state through MCP', function () {
    $user = User::factory()->create();
    config(['jobora.mcp.user' => $user->email]);

    [$capture, $operation] = publicationExtractionIngest($user, [
        [
            'block_id' => 'post-1',
            'platform_id' => 'urn:li:activity:1',
            'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:1',
            'text' => 'Vaga PHP remota',
        ],
        [
            'block_id' => 'post-2',
            'platform_id' => 'urn:li:activity:2',
            'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:2',
            'text' => 'Vaga QA',
        ],
    ]);

    expect($operation->operation_type)->toBe(AiOperationType::PublicationExtraction)
        ->and($operation->status)->toBe(AiOperationStatus::Completed)
        ->and(Publication::query()->count())->toBe(2)
        ->and(PublicationObservation::query()->count())->toBe(2);

    JoboraServer::tool(GetPageCaptureTool::class, ['execution_id' => $capture->execution_id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.capture.extraction.status', 'completed')
            ->where('data.capture.extraction.empty', false)
            ->where('data.capture.extraction.counts.publications', 2)
            ->where('data.capture.extraction.counts.blocks', 2)
            ->where('data.capture.extraction.counts.conflicts', 0)
            ->etc());

    JoboraServer::tool(ListPublicationsTool::class, ['page_capture_id' => $capture->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 2)
            ->where('data.items.0.publication.external_id', 'urn:li:activity:1')
            ->where('data.items.0.publication.content', 'Vaga PHP remota')
            ->where('data.items.0.publication.content_version', 1)
            ->where('data.items.0.observation.evidence_block_ids', ['post-1'])
            ->etc());
});

it('reuses the publication version when a recapture keeps the content', function () {
    $user = User::factory()->create();
    $posts = [[
        'block_id' => 'post-1',
        'platform_id' => 'urn:li:activity:10',
        'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:10',
        'text' => 'Vaga PHP estável',
    ]];

    [$first] = publicationExtractionIngest($user, $posts, now()->subMinutes(5)->toIso8601String());
    [$second] = publicationExtractionIngest($user, $posts);

    $publication = Publication::query()->sole();

    expect(Publication::query()->count())->toBe(1)
        ->and(PublicationObservation::query()->count())->toBe(2)
        ->and($publication->content_version)->toBe(1)
        ->and($publication->observations()->where('page_capture_id', $second->id)->sole()->content_version)->toBe(1)
        ->and($first->id)->not->toBe($second->id);
});

it('increments the version for newer changed content and preserves the previous observation', function () {
    $user = User::factory()->create();
    $base = [[
        'block_id' => 'post-1',
        'platform_id' => 'urn:li:activity:11',
        'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:11',
        'text' => 'Vaga PHP',
    ]];
    $changed = $base;
    $changed[0]['text'] = 'Vaga PHP sênior';

    [$first] = publicationExtractionIngest($user, $base, now()->subMinutes(5)->toIso8601String());
    [$second] = publicationExtractionIngest($user, $changed);

    $publication = Publication::query()->sole();

    expect($publication->content)->toBe('Vaga PHP sênior')
        ->and($publication->content_version)->toBe(2)
        ->and($publication->observations()->where('page_capture_id', $first->id)->sole()->content_version)->toBe(1)
        ->and($publication->observations()->where('page_capture_id', $second->id)->sole()->content_version)->toBe(2);
});

it('does not let an older capture processed late replace the current content', function () {
    $user = User::factory()->create();
    $current = [[
        'block_id' => 'post-1',
        'platform_id' => 'urn:li:activity:12',
        'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:12',
        'text' => 'Vaga PHP atual',
    ]];
    $older = $current;
    $older[0]['text'] = 'Vaga PHP antiga';

    publicationExtractionIngest($user, $current, now()->toIso8601String());
    [$oldCapture] = publicationExtractionIngest($user, $older, now()->subHour()->toIso8601String());

    $publication = Publication::query()->sole();
    $observation = $publication->observations()->where('page_capture_id', $oldCapture->id)->sole();

    expect($publication->content)->toBe('Vaga PHP atual')
        ->and($publication->content_version)->toBe(1)
        ->and($observation->content)->toBe('Vaga PHP antiga')
        ->and($observation->content_version)->toBeNull();
});

it('records an explicit identity conflict scoped to the capture and user', function () {
    $user = User::factory()->create();
    config(['jobora.mcp.user' => $user->email]);

    publicationExtractionIngest($user, [
        [
            'block_id' => 'post-1',
            'platform_id' => 'urn:li:activity:20',
            'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:20',
            'text' => 'Vaga A',
        ],
        [
            'block_id' => 'post-2',
            'platform_id' => 'urn:li:activity:21',
            'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:21',
            'text' => 'Vaga B',
        ],
    ]);

    [$capture] = publicationExtractionIngest($user, [[
        'block_id' => 'post-1',
        'platform_id' => 'urn:li:activity:20',
        'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:21',
        'text' => 'Vaga conflitante',
    ]]);

    expect(PublicationIdentityConflict::query()->count())->toBe(1);

    JoboraServer::tool(ListPublicationsTool::class, ['page_capture_id' => $capture->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.conflicts', 1)
            ->where('data.items.0.conflicts.0.reason', PublicationIdentityConflict::ExternalIdUrlMismatch)
            ->etc());
});

it('isolates captures and publications between users', function () {
    $owner = User::factory()->create();
    config(['jobora.mcp.user' => $owner->email]);
    [$capture] = publicationExtractionIngest($owner, [[
        'block_id' => 'post-1',
        'platform_id' => 'urn:li:activity:30',
        'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:30',
        'text' => 'Vaga privada',
    ]]);

    $other = User::factory()->create();
    config(['jobora.mcp.user' => $other->email]);

    JoboraServer::tool(GetPageCaptureTool::class, ['execution_id' => $capture->execution_id])
        ->assertHasErrors();

    JoboraServer::tool(ListPublicationsTool::class, ['page_capture_id' => $capture->id])
        ->assertHasErrors();
});

it('maps list_publications to the captures read scope', function () {
    expect(AgentIntegrationScope::forTool(ListPublicationsTool::class))
        ->toBe(AgentIntegrationScope::CapturesRead);
});

it('completes an empty publications capture with zero counts', function () {
    $user = User::factory()->create();
    config(['jobora.mcp.user' => $user->email]);

    [$capture] = publicationExtractionIngest($user, []);

    JoboraServer::tool(GetPageCaptureTool::class, ['id' => $capture->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.capture.extraction.status', 'completed')
            ->where('data.capture.extraction.empty', true)
            ->where('data.capture.extraction.counts.publications', 0)
            ->etc());

    JoboraServer::tool(ListPublicationsTool::class, ['page_capture_id' => $capture->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 0)
            ->where('data.extraction.empty', true)
            ->etc());
});

it('requires exactly one capture identifier and lists by execution id', function () {
    $user = User::factory()->create();
    config(['jobora.mcp.user' => $user->email]);

    [$capture] = publicationExtractionIngest($user, [[
        'block_id' => 'post-1',
        'platform_id' => 'urn:li:activity:31',
        'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:31',
        'text' => 'Vaga por execução',
    ]]);

    JoboraServer::tool(ListPublicationsTool::class, [])->assertHasErrors();

    JoboraServer::tool(ListPublicationsTool::class, [
        'execution_id' => $capture->execution_id,
        'page_capture_id' => $capture->id,
    ])->assertHasErrors();

    JoboraServer::tool(ListPublicationsTool::class, ['execution_id' => $capture->execution_id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 1)
            ->where('data.items.0.publication.external_id', 'urn:li:activity:31')
            ->etc());
});

it('recovers a capture whose extraction operation was never created', function () {
    Queue::fake([ProcessAiOperation::class]);
    $user = User::factory()->create();

    [$capture] = publicationExtractionIngest($user, [[
        'block_id' => 'post-1',
        'platform_id' => 'urn:li:activity:40',
        'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:40',
        'text' => 'Vaga recuperável',
    ]]);

    $operation = AiOperation::query()->sole();
    $operation->delete();

    $this->artisan('publication-extractions:recover')->assertSuccessful();

    $recovered = AiOperation::query()->sole();
    Queue::assertPushed(ProcessAiOperation::class, fn (ProcessAiOperation $job): bool => $job->operationId === $recovered->id);

    (new ProcessAiOperation($recovered->id, $recovered->run_token, $recovered->attempt_token))
        ->handle(app(AiOperationService::class));
    expect($recovered->refresh()->status)->toBe(AiOperationStatus::Completed)
        ->and(Publication::query()->count())->toBe(1)
        ->and($capture->refresh()->extractionOperation())->not->toBeNull();
});

it('recovers a queued operation that never reached the queue', function () {
    Queue::fake([ProcessAiOperation::class]);
    $user = User::factory()->create();
    [, $operation] = publicationExtractionIngest($user, [[
        'block_id' => 'post-1',
        'platform_id' => 'urn:li:activity:41',
        'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:41',
        'text' => 'Vaga pendente',
    ]]);

    $operation->forceFill(['queued_at' => now()->subMinutes(2)])->save();

    $this->artisan('publication-extractions:recover')->assertSuccessful();

    Queue::assertPushed(ProcessAiOperation::class, fn (ProcessAiOperation $job): bool => $job->operationId === $operation->id);
});

it('recovers a stale running attempt from the queue', function () {
    Queue::fake([ProcessAiOperation::class]);
    $user = User::factory()->create();
    [, $operation] = publicationExtractionIngest($user, [[
        'block_id' => 'post-1',
        'platform_id' => 'urn:li:activity:42',
        'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:42',
        'text' => 'Vaga travada',
    ]]);

    $operation->forceFill([
        'status' => AiOperationStatus::Running,
        'attempt_token' => 'stale-attempt-token',
        'attempts' => 1,
        'last_attempt_started_at' => now()->subMinutes(10),
    ])->save();

    $this->artisan('publication-extractions:recover')->assertSuccessful();

    Queue::assertPushed(ProcessAiOperation::class, fn (ProcessAiOperation $job): bool => $job->operationId === $operation->id);

    $attemptToken = null;
    (new ProcessAiOperation($operation->id, $operation->run_token, $attemptToken))
        ->handle(app(AiOperationService::class));

    expect($operation->refresh()->status)->toBe(AiOperationStatus::Completed)
        ->and(Publication::query()->sole()->content_version)->toBe(1);
});

it('rejects a paused stale worker after a new attempt completes', function () {
    Queue::fake([ProcessAiOperation::class]);
    $user = User::factory()->create();
    [$capture, $operation] = publicationExtractionIngest($user, [[
        'block_id' => 'post-1',
        'platform_id' => 'urn:li:activity:44',
        'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:44',
        'text' => 'Vaga com escrita concorrente',
    ]]);

    $operation->forceFill([
        'status' => AiOperationStatus::Queued,
        'attempt_token' => 'attempt-A',
        'attempts' => 0,
    ])->save();

    $local = Storage::disk('local');
    $blocking = new class($local->getDriver(), $local->getAdapter(), $local->getConfig()) extends FilesystemAdapter
    {
        public ?Closure $beforeGet = null;

        public function get($path)
        {
            if ($this->beforeGet !== null) {
                $callback = $this->beforeGet;
                $this->beforeGet = null;
                $callback();
            }

            return parent::get($path);
        }
    };

    Storage::set('blocking', $blocking);
    config(['capture.ingestion.disk' => 'blocking']);

    $newCompleted = false;
    $blocking->beforeGet = function () use ($operation, &$newCompleted): void {
        $operation->forceFill(['last_attempt_started_at' => now()->subMinutes(10)])->save();

        (new ProcessAiOperation($operation->id, $operation->run_token, null))
            ->handle(app(AiOperationService::class));

        $newCompleted = true;
    };

    $staleFailed = false;

    try {
        (new ProcessAiOperation($operation->id, $operation->run_token, 'attempt-A'))
            ->handle(app(AiOperationService::class));
    } catch (LogicException) {
        $staleFailed = true;
    }

    expect($newCompleted)->toBeTrue()
        ->and($staleFailed)->toBeTrue()
        ->and($operation->refresh()->status)->toBe(AiOperationStatus::Completed)
        ->and($operation->attempt_token)->not->toBe('attempt-A')
        ->and(PublicationObservation::query()->where('page_capture_id', $capture->id)->count())->toBe(1);
});

it('keeps publication extraction recoverable after an outage longer than five minutes', function () {
    Queue::fake([ProcessAiOperation::class]);
    $user = User::factory()->create();
    [, $operation] = publicationExtractionIngest($user, [[
        'block_id' => 'post-1',
        'platform_id' => 'urn:li:activity:43',
        'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:43',
        'text' => 'Vaga após indisponibilidade',
    ]]);

    expect($operation->expires_at->greaterThan(now()->addMinutes(5)))->toBeTrue();

    $this->travel(10)->minutes();

    $this->artisan('ai-operations:expire')->assertSuccessful();

    expect($operation->refresh()->status)->toBe(AiOperationStatus::Queued);

    $this->artisan('publication-extractions:recover')->assertSuccessful();

    Queue::assertPushed(ProcessAiOperation::class, fn (ProcessAiOperation $job): bool => $job->operationId === $operation->id);
});

it('skips recovery for expired raw, missing raw path or captures outside the window', function () {
    Queue::fake([ProcessAiOperation::class]);
    $user = User::factory()->create();

    PageCapture::factory()->for($user)->create([
        'surface' => 'linkedin_publications',
        'html_path' => null,
        'blocks_captured' => 1,
        'coverage_status' => 'complete',
        'raw_expires_at' => now()->addDay(),
        'created_at' => now(),
    ]);

    PageCapture::factory()->for($user)->create([
        'surface' => 'linkedin_publications',
        'html_path' => 'capture-envelopes/expired.html',
        'blocks_captured' => 1,
        'coverage_status' => 'complete',
        'raw_expires_at' => now()->subDay(),
        'created_at' => now(),
    ]);

    PageCapture::factory()->for($user)->create([
        'surface' => 'linkedin_publications',
        'html_path' => 'capture-envelopes/old.html',
        'blocks_captured' => 1,
        'coverage_status' => 'complete',
        'raw_expires_at' => now()->addDay(),
        'created_at' => now()->subDays(2),
    ]);

    PageCapture::factory()->for($user)->create([
        'surface' => 'linkedin_publications',
        'html_path' => 'capture-envelopes/missing-file.html',
        'blocks_captured' => 1,
        'coverage_status' => 'complete',
        'raw_expires_at' => now()->addDay(),
        'created_at' => now(),
    ]);

    $this->artisan('publication-extractions:recover')->assertSuccessful();

    expect(AiOperation::query()->count())->toBe(0);
});

it('processes a queued extraction with a real database queue worker', function () {
    config([
        'queue.default' => 'database',
        'queue.connections.database.after_commit' => false,
    ]);

    $user = User::factory()->create();
    [$capture, $operation] = publicationExtractionIngest($user, [[
        'block_id' => 'post-1',
        'platform_id' => 'urn:li:activity:50',
        'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:50',
        'text' => 'Vaga em fila real',
    ]]);

    expect($operation->refresh()->status)->toBe(AiOperationStatus::Queued)
        ->and(Publication::query()->count())->toBe(0);

    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

    expect($operation->refresh()->status)->toBe(AiOperationStatus::Completed)
        ->and(Publication::query()->count())->toBe(1)
        ->and($capture->observations()->count())->toBe(1);
});
