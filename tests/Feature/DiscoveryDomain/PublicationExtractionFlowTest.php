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
use App\Services\Discovery\CaptureValidationException;
use App\Services\Discovery\CaptureValidator;
use App\Services\Discovery\DiscoveryRunService;
use App\Services\Discovery\DiscoverySourceUrl;
use App\Services\Discovery\LinkedInPublicationsDecoder;
use App\Services\Discovery\PublicationExtractionException;
use App\Services\Discovery\PublicationExtractionProcessor;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * @param  list<array{block_id: string, platform_id: ?string, permalink: ?string, text: string}>  $posts
 */
function publicationExtractionEnvelope(array $posts, ?string $capturedAt = null): array
{
    $source = 'linkedin';
    $surface = 'linkedin_publications';

    $html = '<main data-capture-surface="'.$surface.'" data-capture-version="1">';

    foreach ($posts as $post) {
        $html .= '<article data-block-id="'.$post['block_id'].'" data-kind="post"'
            .($post['platform_id'] === null ? '' : ' data-platform-id="'.$post['platform_id'].'"')
            .($post['permalink'] === null ? '' : ' data-permalink="'.$post['permalink'].'"').'>'
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
function publicationExtractionRun(User $user, ?DiscoverySource $source = null): array
{
    if ($source === null) {
        $url = 'https://www.linkedin.com/search/results/content/?keywords=laravel-'.uniqid();
        $source = DiscoverySource::factory()->for($user, 'user')->create([
            'source' => 'linkedin',
            'surface' => 'linkedin_publications',
            'url' => $url,
            'canonical_url_hash' => app(DiscoverySourceUrl::class)->hash($url),
            'active' => true,
            'next_due_at' => now()->subMinute(),
        ]);
    } else {
        $source->forceFill(['next_due_at' => now()->subMinute()])->save();
    }

    $run = app(DiscoveryRunService::class)->acquire($user, null, 'idem-'.uniqid())->first();

    return [$source, $run];
}

function publicationExtractionPost(User $user, array $payload): TestResponse
{
    return test()->withToken(app(CaptureTokenService::class)->issueToken($user))
        ->postJson('/api/page-captures', $payload);
}

/**
 * @param  list<array{block_id: string, platform_id: ?string, permalink: ?string, text: string}>  $posts
 * @return array{0: PageCapture, 1: AiOperation}
 */
function publicationExtractionIngest(User $user, array $posts, ?string $capturedAt = null, ?DiscoverySource $source = null): array
{
    [$source, $run] = publicationExtractionRun($user, $source);
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

it('ingests multiple linkedin posts without identity or permalink', function () {
    $user = User::factory()->create();
    config(['jobora.mcp.user' => $user->email]);

    $posts = [
        [
            'block_id' => 'post-anonymous-1',
            'platform_id' => null,
            'permalink' => null,
            'text' => 'Post sem identidade',
        ],
        [
            'block_id' => 'post-anonymous-2',
            'platform_id' => null,
            'permalink' => null,
            'text' => 'Post sem identidade',
        ],
        [
            'block_id' => 'post-id-only',
            'platform_id' => 'urn:li:activity:anonymous-id-only',
            'permalink' => null,
            'text' => 'Post com ID',
        ],
        [
            'block_id' => 'post-url-only',
            'platform_id' => null,
            'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:url-only',
            'text' => 'Post com URL',
        ],
    ];

    [$capture, $operation] = publicationExtractionIngest($user, $posts);

    $anonymousPublicationIds = [];
    JoboraServer::tool(ListPublicationsTool::class, ['page_capture_id' => $capture->id])
        ->assertOk()
        ->assertStructuredContent(function ($json) use (&$anonymousPublicationIds): void {
            $json->where('data.total', 4)
                ->where('data.items', function (Collection $items) use (&$anonymousPublicationIds): bool {
                    $anonymousItems = $items->filter(fn (array $item): bool => $item['publication']['content'] === 'Post sem identidade');
                    $anonymousPublicationIds = $anonymousItems->pluck('publication.id')->all();

                    return $anonymousItems->count() === 2
                        && $anonymousItems->pluck('publication.id')->unique()->count() === 2;
                })
                ->etc();
        });

    $publications = Publication::query()->orderBy('id')->get();
    $storedHtml = Storage::disk('local')->get((string) $capture->html_path);

    expect($operation->refresh()->status)->toBe(AiOperationStatus::Completed)
        ->and($publications)->toHaveCount(4)
        ->and($publications->pluck('external_id')->all())->toBe([
            null,
            null,
            'urn:li:activity:anonymous-id-only',
            null,
        ])
        ->and($publications->pluck('url')->all())->toBe([
            null,
            null,
            null,
            'https://www.linkedin.com/feed/update/urn:li:activity:url-only',
        ])
        ->and($publications[0]->fingerprint)->toBe(hash('sha256', 'capture-block:v1|'.$capture->id.'|post-anonymous-1'))
        ->and($publications[1]->fingerprint)->toBe(hash('sha256', 'capture-block:v1|'.$capture->id.'|post-anonymous-2'))
        ->and($publications[2]->fingerprint)->toBeNull()
        ->and($publications[3]->fingerprint)->toBeNull()
        ->and(PublicationObservation::query()->count())->toBe(4)
        ->and(PublicationObservation::query()->where('page_capture_id', $capture->id)->orderBy('id')->pluck('evidence_block_ids')->all())->toBe([
            ['post-anonymous-1'],
            ['post-anonymous-2'],
            ['post-id-only'],
            ['post-url-only'],
        ])
        ->and($storedHtml)->not->toMatch('/data-block-id="post-anonymous-[12]"[^>]*data-platform-id=/')
        ->and($storedHtml)->not->toMatch('/data-block-id="post-anonymous-[12]"[^>]*data-permalink=/');

    app(PublicationExtractionProcessor::class)->extractFromSnapshot(
        $user,
        [
            'page_capture_id' => $capture->id,
            'html_path' => $capture->html_path,
            'surface' => $capture->surface,
            'format_version' => $capture->format_version,
        ],
        fn (Closure $persist): mixed => $persist(),
    );

    expect(Publication::query()->count())->toBe(4)
        ->and(PublicationObservation::query()->count())->toBe(4);

    [$recapture] = publicationExtractionIngest($user, $posts);

    expect(Publication::query()->count())->toBe(6)
        ->and(PublicationObservation::query()->count())->toBe(8)
        ->and(Publication::query()->where('external_id', 'urn:li:activity:anonymous-id-only')->count())->toBe(1)
        ->and(Publication::query()->where('url', 'https://www.linkedin.com/feed/update/urn:li:activity:url-only')->count())->toBe(1)
        ->and(Publication::query()->where('fingerprint', hash('sha256', 'capture-block:v1|'.$recapture->id.'|post-anonymous-1'))->exists())->toBeTrue()
        ->and(Publication::query()->where('fingerprint', hash('sha256', 'capture-block:v1|'.$recapture->id.'|post-anonymous-2'))->exists())->toBeTrue()
        ->and($recapture->id)->not->toBe($capture->id);
});

it('reuses anonymous publications across recaptures without merging distinct source items', function () {
    $user = User::factory()->create();
    config(['jobora.mcp.user' => $user->email]);
    $firstPosts = [
        [
            'block_id' => 'post-a-first-capture',
            'platform_id' => null,
            'permalink' => null,
            'text' => 'Publicação anônima A',
        ],
        [
            'block_id' => 'post-b-first-capture',
            'platform_id' => null,
            'permalink' => null,
            'text' => 'Publicação anônima B',
        ],
    ];

    [$firstCapture] = publicationExtractionIngest($user, $firstPosts);
    $source = DiscoverySource::query()->where('user_id', $user->id)->sole();
    [$secondCapture] = publicationExtractionIngest($user, [
        [
            'block_id' => 'post-b-second-capture',
            'platform_id' => null,
            'permalink' => null,
            'text' => 'Publicação anônima B',
        ],
        [
            'block_id' => 'post-a-second-capture',
            'platform_id' => null,
            'permalink' => null,
            'text' => 'Publicação anônima A',
        ],
    ], source: $source);
    [$otherSourceCapture] = publicationExtractionIngest($user, [$firstPosts[0]]);

    $readPublicationIds = function (PageCapture $capture, int $expectedCount): array {
        $publicationIds = [];

        JoboraServer::tool(ListPublicationsTool::class, ['page_capture_id' => $capture->id])
            ->assertOk()
            ->assertStructuredContent(function ($json) use (&$publicationIds, $expectedCount): void {
                $json->where('data.total', $expectedCount)
                    ->where('data.items', function (Collection $items) use (&$publicationIds, $expectedCount): bool {
                        foreach ($items as $item) {
                            $publicationIds[$item['publication']['content']] = $item['publication']['id'];
                        }

                        return count($publicationIds) === $expectedCount;
                    })
                    ->etc();
            });

        ksort($publicationIds);

        return $publicationIds;
    };

    $firstPublications = $readPublicationIds($firstCapture, 2);
    $secondPublications = $readPublicationIds($secondCapture, 2);
    $otherSourcePublications = $readPublicationIds($otherSourceCapture, 1);

    expect($firstPublications)->toHaveCount(2)
        ->and(array_unique($firstPublications))->toHaveCount(2)
        ->and($secondPublications)->toBe($firstPublications)
        ->and($otherSourcePublications['Publicação anônima A'])->not->toBe($firstPublications['Publicação anônima A']);
});

it('requires identity keys but allows null only on linkedin publication posts', function () {
    $validator = app(CaptureValidator::class);
    $payload = publicationExtractionEnvelope([[
        'block_id' => 'post-null-identity',
        'platform_id' => null,
        'permalink' => null,
        'text' => 'Post sem identidade',
    ]]);

    $validator->validate($payload);

    foreach (['platform_id', 'permalink'] as $identityKey) {
        $missing = $payload;
        unset($missing['blocks'][0][$identityKey]);
        expect(fn () => $validator->validate($missing))
            ->toThrow(CaptureValidationException::class);

        foreach (['', 42, []] as $invalid) {
            $invalidPayload = $payload;
            $invalidPayload['blocks'][0][$identityKey] = $invalid;
            expect(fn () => $validator->validate($invalidPayload))
                ->toThrow(CaptureValidationException::class);
        }

        $whitespace = $payload;
        $whitespace['blocks'][0][$identityKey] = '   ';
        expect(fn () => $validator->validate($whitespace))
            ->toThrow(CaptureValidationException::class);
    }

    $jobPayload = $payload;
    $jobPayload['capture']['surface'] = 'linkedin_jobs';
    $jobPayload['capture']['initial_url'] = 'https://www.linkedin.com/jobs/search/?keywords=laravel';

    expect(fn () => $validator->validate($jobPayload))
        ->toThrow(CaptureValidationException::class);

    foreach (['card', 'detail'] as $kind) {
        foreach (['platform_id', 'permalink'] as $identityKey) {
            $relatedBlock = $payload;
            $relatedBlock['blocks'][0]['kind'] = $kind;
            $relatedBlock['blocks'][0]['platform_id'] = 'urn:li:activity:related-block';
            $relatedBlock['blocks'][0]['permalink'] = 'https://www.linkedin.com/feed/update/urn:li:activity:related-block';
            $relatedBlock['blocks'][0][$identityKey] = null;
            $relatedBlock['blocks'][0]['card_block_id'] = $kind === 'detail' ? 'card-1' : null;

            expect(fn () => $validator->validate($relatedBlock))
                ->toThrow(CaptureValidationException::class, $identityKey.' inválido.');
        }
    }
});

it('rejects identity HTML attributes that diverge from JSON identity values', function () {
    $user = User::factory()->create();
    foreach ([
        'data-platform-id=""',
        'data-permalink=""',
        'data-permalink="https://www.linkedin.com/feed/update/forged"',
    ] as $attribute) {
        $anonymous = publicationExtractionEnvelope([[
            'block_id' => 'post-null-identity',
            'platform_id' => null,
            'permalink' => null,
            'text' => 'Post sem identidade',
        ]]);
        $anonymous['capture']['html'] = str_replace(
            'data-kind="post"',
            'data-kind="post" '.$attribute,
            $anonymous['capture']['html'],
        );

        publicationExtractionPost($user, $anonymous)->assertStatus(422);
    }

    foreach ([
        ' data-platform-id="urn:li:activity:html-mismatch"',
        ' data-permalink="https://www.linkedin.com/feed/update/urn:li:activity:html-mismatch"',
    ] as $missingAttribute) {
        $identified = publicationExtractionEnvelope([[
            'block_id' => 'post-identified',
            'platform_id' => 'urn:li:activity:html-mismatch',
            'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:html-mismatch',
            'text' => 'Post com identidade',
        ]]);
        $identified['capture']['html'] = str_replace($missingAttribute, '', $identified['capture']['html']);

        publicationExtractionPost($user, $identified)->assertStatus(422);
    }

    foreach ([
        ['data-platform-id="urn:li:activity:html-mismatch"', 'data-platform-id="urn:li:activity:other"'],
        ['data-permalink="https://www.linkedin.com/feed/update/urn:li:activity:html-mismatch"', 'data-permalink="https://www.linkedin.com/feed/update/urn:li:activity:other"'],
    ] as [$htmlValue, $htmlMismatch]) {
        $identified = publicationExtractionEnvelope([[
            'block_id' => 'post-identified',
            'platform_id' => 'urn:li:activity:html-mismatch',
            'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:html-mismatch',
            'text' => 'Post com identidade',
        ]]);
        $identified['capture']['html'] = str_replace($htmlValue, $htmlMismatch, $identified['capture']['html']);

        publicationExtractionPost($user, $identified)->assertStatus(422);
    }
});

it('rejects a LinkedIn search URL as publication permalink', function () {
    expect(fn () => app(LinkedInPublicationsDecoder::class)->decode([[
        'block_id' => 'post-search-url',
        'kind' => 'post',
        'platform_id' => null,
        'permalink' => 'https://www.linkedin.com/search/results/content/?keywords=laravel',
        'content' => 'Post com URL de busca',
    ]]))->toThrow(PublicationExtractionException::class);
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

it('trava todas as publicações em ordem de id antes de aplicar conteúdo', function () {
    $user = User::factory()->create();
    $alpha = [
        'block_id' => 'post-alpha',
        'platform_id' => 'urn:li:activity:13',
        'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:13',
        'text' => 'Vaga alpha',
    ];
    $beta = [
        'block_id' => 'post-beta',
        'platform_id' => 'urn:li:activity:14',
        'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:14',
        'text' => 'Vaga beta',
    ];

    publicationExtractionIngest($user, [$alpha, $beta]);
    $alphaId = Publication::query()->where('external_id', $alpha['platform_id'])->sole()->id;
    $betaId = Publication::query()->where('external_id', $beta['platform_id'])->sole()->id;

    $alpha['text'] = 'Vaga alpha atualizada';
    $beta['text'] = 'Vaga beta atualizada';

    $locks = [];
    DB::listen(function ($query) use (&$locks): void {
        if (str_contains($query->sql, 'from "publications"') && str_contains($query->sql, 'order by "id"')) {
            $locks[] = $query->sql;
        }
    });

    // Ordem invertida em relação aos ids: o lock não pode seguir o decodificador.
    [$capture, $operation] = publicationExtractionIngest($user, [$beta, $alpha]);

    expect($locks)->toHaveCount(1)
        ->and($locks[0])->toContain('order by "id"')
        ->and($locks[0])->toContain((string) $alphaId)
        ->and($locks[0])->toContain((string) $betaId)
        ->and(Publication::query()->whereKey($alphaId)->sole()->content)->toBe('Vaga alpha atualizada')
        ->and(Publication::query()->whereKey($betaId)->sole()->content)->toBe('Vaga beta atualizada')
        ->and($capture->observations()->count())->toBe(2)
        ->and($operation->refresh()->status)->toBe(AiOperationStatus::Completed);
});

it('does not let an older same-content capture inflate the same-second tie-break', function () {
    Queue::fake([ProcessAiOperation::class]);
    $user = User::factory()->create();
    $base = [[
        'block_id' => 'post-1',
        'platform_id' => 'urn:li:activity:15',
        'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:15',
        'text' => 'Vaga PHP',
    ]];
    $changed = $base;
    $changed[0]['text'] = 'Vaga PHP sênior';

    [$first, $firstOperation] = publicationExtractionIngest($user, $base, '2026-08-18T10:00:00+00:00');
    [$sameSecond, $sameSecondOperation] = publicationExtractionIngest($user, $changed, '2026-08-18T10:00:00+00:00');
    [$staleRecapture, $staleOperation] = publicationExtractionIngest($user, $base, '2026-08-18T09:00:00+00:00');

    // A recaptura antiga de mesmo conteúdo é processada depois, mas não é dona do instante.
    foreach ([$firstOperation, $staleOperation, $sameSecondOperation] as $operation) {
        (new ProcessAiOperation($operation->id, $operation->run_token, $operation->attempt_token))
            ->handle(app(AiOperationService::class));
    }

    $publication = Publication::query()->sole();

    expect($publication->content)->toBe('Vaga PHP sênior')
        ->and($publication->content_version)->toBe(2)
        ->and($publication->observations()->where('page_capture_id', $first->id)->sole()->content_version)->toBe(1)
        ->and($publication->observations()->where('page_capture_id', $staleRecapture->id)->sole()->content_version)->toBe(1)
        ->and($publication->observations()->where('page_capture_id', $sameSecond->id)->sole()->content_version)->toBe(2);
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

    $staleAttemptToken = (string) Str::uuid();
    $operation->forceFill([
        'status' => AiOperationStatus::Running,
        'attempt_token' => $staleAttemptToken,
        'attempts' => 1,
        'last_attempt_started_at' => now()->subMinutes(10),
    ])->save();

    $this->artisan('publication-extractions:recover')->assertSuccessful();

    Queue::assertPushed(ProcessAiOperation::class, fn (ProcessAiOperation $job): bool => $job->operationId === $operation->id);

    $attemptToken = null;
    (new ProcessAiOperation($operation->id, $operation->run_token, $attemptToken))
        ->handle(app(AiOperationService::class));

    expect($operation->refresh()->status)->toBe(AiOperationStatus::Completed)
        ->and($operation->attempt_token)->not->toBe($staleAttemptToken)
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

    $pausedAttemptToken = (string) Str::uuid();
    $operation->forceFill([
        'status' => AiOperationStatus::Queued,
        'attempt_token' => $pausedAttemptToken,
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
        (new ProcessAiOperation($operation->id, $operation->run_token, $pausedAttemptToken))
            ->handle(app(AiOperationService::class));
    } catch (LogicException) {
        $staleFailed = true;
    }

    expect($newCompleted)->toBeTrue()
        ->and($staleFailed)->toBeTrue()
        ->and($operation->refresh()->status)->toBe(AiOperationStatus::Completed)
        ->and($operation->attempt_token)->not->toBe($pausedAttemptToken)
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

it('resolve a operação de extração mesmo com operação mais recente de outro alvo', function () {
    $user = User::factory()->create();
    [$capture, $operation] = publicationExtractionIngest($user, [[
        'block_id' => 'post-1',
        'platform_id' => 'urn:li:activity:46',
        'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:46',
        'text' => 'Vaga com id compartilhado',
    ]]);

    AiOperation::query()->create([
        'user_id' => $user->id,
        'operation_key' => 'ats_score:job_posting:'.$capture->id,
        'operation_type' => AiOperationType::AtsScore,
        'target_type' => 'job_posting',
        'target_id' => $capture->id,
        'run_token' => (string) Str::uuid(),
        'status' => AiOperationStatus::Completed,
        'queued_at' => now(),
        'expires_at' => now()->addDay(),
        'input_snapshot' => [],
        'input_fingerprint' => 'test',
    ]);

    expect($capture->refresh()->extractionOperation?->id)->toBe($operation->id);

    // A recuperação não pode reenfileirar uma captura que já tem extração.
    expect(PageCapture::query()->whereDoesntHave('extractionOperation')->count())->toBe(0);
});

it('keeps recovering stuck operations when the capture disk raises on exists', function () {
    Queue::fake([ProcessAiOperation::class]);
    $user = User::factory()->create();
    [, $operation] = publicationExtractionIngest($user, [[
        'block_id' => 'post-1',
        'platform_id' => 'urn:li:activity:45',
        'permalink' => 'https://www.linkedin.com/feed/update/urn:li:activity:45',
        'text' => 'Vaga com storage indisponível',
    ]]);

    $operation->forceFill([
        'status' => AiOperationStatus::Running,
        'attempt_token' => 'stale-attempt-token',
        'attempts' => 1,
        'last_attempt_started_at' => now()->subMinutes(10),
    ])->save();

    $local = Storage::disk('local');
    $unreachable = new class($local->getDriver(), $local->getAdapter(), $local->getConfig()) extends FilesystemAdapter
    {
        public function exists($path)
        {
            throw new RuntimeException('Storage unreachable.');
        }
    };

    Storage::set('unreachable', $unreachable);
    config(['capture.ingestion.disk' => 'unreachable']);

    $this->artisan('publication-extractions:recover')->assertSuccessful();

    Queue::assertPushed(ProcessAiOperation::class, fn (ProcessAiOperation $job): bool => $job->operationId === $operation->id);
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
