<?php

use App\Mcp\Servers\JoboraServer;
use App\Mcp\Tools\Captures\ListPublicationsTool;
use App\Models\User;
use App\Services\CaptureTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Mcp\McpTestHelpers;

uses(RefreshDatabase::class);

/**
 * @param  list<array{block_id: string, platform_id: ?string, permalink: ?string, content: string}>  $posts
 * @return array<string, mixed>
 */
function publicationIdentityEnrichmentRegressionEnvelope(array $posts): array
{
    $surface = 'linkedin_publications';
    $html = '<main data-capture-surface="'.$surface.'" data-capture-version="1">';

    foreach ($posts as $post) {
        $html .= '<article data-block-id="'.$post['block_id'].'" data-kind="post"';

        if ($post['platform_id'] !== null) {
            $html .= ' data-platform-id="'.$post['platform_id'].'"';
        }

        if ($post['permalink'] !== null) {
            $html .= ' data-permalink="'.$post['permalink'].'"';
        }

        $html .= '><p>'.htmlspecialchars($post['content'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p></article>';
    }

    $html .= '</main>';

    return [
        'format' => 'jobora.capture-envelope',
        'version' => 1,
        'capture' => [
            'idempotency_key' => 'identity-enrichment-'.Str::uuid(),
            'execution_id' => null,
            'source' => 'linkedin',
            'surface' => $surface,
            'initial_url' => 'https://www.linkedin.com/search/results/content/?keywords=synthetic',
            'final_url' => 'https://www.linkedin.com/search/results/content/?keywords=synthetic',
            'captured_at' => now()->toIso8601String(),
            'coverage' => [
                'status' => 'complete',
                'reason' => null,
                'blocks_expected' => count($posts),
                'blocks_captured' => count($posts),
            ],
            'html' => $html,
        ],
        'blocks' => array_map(static fn (array $post): array => [
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
 * @param  list<array{block_id: string, platform_id: ?string, permalink: ?string, content: string}>  $posts
 */
function publicationIdentityEnrichmentRegressionIngest(User $user, array $posts): int
{
    $response = test()
        ->withToken(app(CaptureTokenService::class)->issueToken($user))
        ->postJson('/api/page-captures', publicationIdentityEnrichmentRegressionEnvelope($posts))
        ->assertCreated();

    return (int) $response->json('id');
}

function configurePublicationIdentityEnrichmentRegressionWorker(): void
{
    config([
        'queue.default' => 'database',
        'queue.connections.database.after_commit' => false,
        'triage.prefilter.account_id' => 'test-account',
        'triage.prefilter.api_token' => 'test-token',
        'triage.prefilter.model' => '@cf/cloudflare/clef-flash',
        'triage.prefilter.base_url' => 'https://api.cloudflare.com/client/v4',
    ]);

    Http::fake(['*' => Http::response([
        'success' => true,
        'errors' => [],
        'messages' => [],
        'result' => [
            'model' => 'clef-flash',
            'answers' => [
                'is_job' => ['type' => 'noul', 'noul' => 0.9],
                'non_job_kind' => [
                    'type' => 'choice',
                    'choice' => 'other',
                    'probabilities' => [
                        'candidate_offering' => 0.1,
                        'job_curation' => 0.1,
                        'editorial_content' => 0.1,
                        'promotion' => 0.1,
                        'other' => 0.6,
                    ],
                    'confidence' => 0.91,
                ],
            ],
        ],
    ])]);
}

function runPublicationIdentityEnrichmentQueue(): void
{
    // The in-process worker shares PHPUnit's 1 GiB heap; avoid its unrelated 128 MiB default.
    test()->artisan('queue:work', [
        '--stop-when-empty' => true,
        '--sleep' => 0,
        '--memory' => 1024,
    ])->assertSuccessful();
}

beforeEach(function (): void {
    Storage::fake('local');
});

it('keeps anonymous and identified posts separate without same-occurrence evidence in either order', function () {
    $user = McpTestHelpers::user();
    configurePublicationIdentityEnrichmentRegressionWorker();
    $content = 'Synthetic Backend Engineer opening with PHP and Laravel.';
    $externalId = 'urn:li:activity:9000000000000000991';
    $permalink = 'https://www.linkedin.com/feed/update/'.$externalId;
    $anonymous = [
        'block_id' => 'post-anonymous',
        'platform_id' => null,
        'permalink' => null,
        'content' => $content,
    ];
    $identified = [
        'block_id' => 'post-identified',
        'platform_id' => $externalId,
        'permalink' => $permalink,
        'content' => $content,
    ];
    $captureIds = [
        publicationIdentityEnrichmentRegressionIngest($user, [$anonymous, $identified]),
        publicationIdentityEnrichmentRegressionIngest($user, [$identified, $anonymous]),
    ];

    runPublicationIdentityEnrichmentQueue();

    foreach ($captureIds as $captureId) {
        JoboraServer::tool(ListPublicationsTool::class, ['page_capture_id' => $captureId])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('data.extraction.status', 'completed')
                ->where('data.extraction.counts.blocks', 2)
                ->where('data.extraction.counts.publications', 2)
                ->where('data.total', 2)
                ->where('data.items', function (Collection $items) use ($externalId, $permalink): bool {
                    $publications = $items->pluck('publication');
                    $identified = $publications->firstWhere('external_id', $externalId);

                    return $publications->count() === 2
                        && $publications->pluck('id')->unique()->count() === 2
                        && $publications->whereNull('external_id')->count() === 1
                        && $identified !== null
                        && $identified['url'] === $permalink;
                })
                ->etc());
    }
});

it('keeps two anonymous publications with identical text distinct', function () {
    $user = McpTestHelpers::user();
    configurePublicationIdentityEnrichmentRegressionWorker();
    $content = 'Synthetic Backend Engineer opening with PHP and Laravel.';
    $captureId = publicationIdentityEnrichmentRegressionIngest($user, [
        [
            'block_id' => 'post-anonymous-a',
            'platform_id' => null,
            'permalink' => null,
            'content' => $content,
        ],
        [
            'block_id' => 'post-anonymous-b',
            'platform_id' => null,
            'permalink' => null,
            'content' => $content,
        ],
    ]);

    runPublicationIdentityEnrichmentQueue();

    JoboraServer::tool(ListPublicationsTool::class, ['page_capture_id' => $captureId])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 2)
            ->where('data.extraction.status', 'completed')
            ->where('data.extraction.counts.publications', 2)
            ->where('data.items', function (Collection $items): bool {
                return $items->count() === 2
                    && $items->pluck('publication.id')->unique()->count() === 2
                    && $items->pluck('publication.external_id')->every(fn (?string $id): bool => $id === null);
            })
            ->etc());
});
