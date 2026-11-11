<?php

use App\Jobs\ClassifyPublicationWithClef;
use App\Jobs\PrefilterJobCaptures;
use App\Jobs\TriageJobCaptures;
use App\Mcp\Servers\JoboraServer;
use App\Mcp\Tools\Captures\ListPublicationsTool;
use App\Models\User;
use App\Services\CaptureTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function publicationClefResponse(float $probability, array $overrides = []): array
{
    $response = [
        'success' => true,
        'errors' => [],
        'messages' => [],
        'result' => [
            'model' => 'clef-flash',
            'answers' => [
                'is_job' => ['type' => 'noul', 'noul' => $probability],
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
    ];

    return array_replace_recursive($response, $overrides);
}

/**
 * @return array<string, mixed>
 */
function publicationClefPayload(string $jobKey, ?string $description = null): array
{
    $payload = json_decode((string) file_get_contents(base_path('fixtures/capture-envelope/v1/indeed-jobs.json')), true);
    $payload['capture']['idempotency_key'] = 'clef-'.uniqid('', true);
    $payload['capture']['execution_id'] = null;
    $payload['capture']['captured_at'] = now()->toIso8601String();
    $payload['capture']['final_url'] = str_replace('exemplo0001', $jobKey, $payload['capture']['final_url']);
    $payload['capture']['html'] = str_replace('exemplo0001', $jobKey, $payload['capture']['html']);

    if ($description !== null) {
        $payload['capture']['html'] = str_replace('Descrição sintética da vaga de exemplo no Indeed.', $description, $payload['capture']['html']);
    }

    foreach ($payload['blocks'] as &$block) {
        $block['platform_id'] = $jobKey;
        $block['permalink'] = str_replace('exemplo0001', $jobKey, $block['permalink']);
    }
    unset($block);

    return $payload;
}

function publicationClefConfigureUser(User $user): void
{
    config([
        'jobora.mcp.user' => $user->email,
        'triage.prefilter.account_id' => 'test-account',
        'triage.prefilter.api_token' => 'test-token',
        'triage.prefilter.model' => '@cf/cloudflare/clef-flash',
        'triage.prefilter.base_url' => 'https://api.cloudflare.com/client/v4',
    ]);
}

function publicationClefIngest(User $user, array $payload, int $expectedStatus = 201): TestResponse
{
    return test()->withToken(app(CaptureTokenService::class)->issueToken($user))
        ->postJson('/api/page-captures', $payload)
        ->assertStatus($expectedStatus);
}

it('lists a new publication Clef result by status without a capture', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    publicationClefConfigureUser($user);
    Http::fake(['*' => Http::response(publicationClefResponse(0.50))]);
    Queue::fake([PrefilterJobCaptures::class, TriageJobCaptures::class]);

    publicationClefIngest($user, publicationClefPayload('baseline0001'));

    JoboraServer::tool(ListPublicationsTool::class, [
        'clef_status' => 'eligible',
        'limit' => 10,
        'offset' => 0,
    ])->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 1)
            ->where('data.items.0.clef.status', 'eligible')
            ->where('data.items.0.clef.probability', 0.50)
            ->where('data.items.0.clef.category', 'other')
            ->where('data.items.0.clef.confidence', 0.91)
            ->where('data.items.0.clef.model', '@cf/cloudflare/clef-flash')
            ->where('data.items.0.clef.attempt', 1)
            ->where('data.items.0.clef.content_version', 1)
            ->where('data.items.0.clef.error', null)
            ->where('data.items.0.clef.classifier_identifier', fn (string $identifier): bool => str_starts_with($identifier, 'clef-config-sha256:'))
            ->etc());

    Queue::assertNotPushed(PrefilterJobCaptures::class);
    Queue::assertNotPushed(TriageJobCaptures::class);
    Http::assertSentCount(1);
});

it('applies strict cutoff and paginates current Clef statuses', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    publicationClefConfigureUser($user);
    Http::fakeSequence()
        ->push(publicationClefResponse(0.4999))
        ->push(publicationClefResponse(0.50))
        ->push(publicationClefResponse(0.5001));

    publicationClefIngest($user, publicationClefPayload('below0001'));
    publicationClefIngest($user, publicationClefPayload('equal0001'));
    publicationClefIngest($user, publicationClefPayload('above0001'));

    JoboraServer::tool(ListPublicationsTool::class, [
        'clef_status' => 'eligible',
        'limit' => 1,
        'offset' => 0,
    ])->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 2)
            ->where('data.items.0.clef.status', 'eligible')
            ->where('data.next_offset', 1)
            ->etc());

    JoboraServer::tool(ListPublicationsTool::class, [
        'clef_status' => 'eligible',
        'limit' => 1,
        'offset' => 1,
    ])->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 2)
            ->where('data.items.0.clef.status', 'eligible')
            ->where('data.next_offset', null)
            ->etc());

    JoboraServer::tool(ListPublicationsTool::class, ['clef_status' => 'barred'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 1)
            ->where('data.items.0.clef.probability', 0.4999)
            ->where('data.items.0.clef.status', 'barred')
            ->etc());
});

it('stores invalid, partial, timeout, and rate-limit responses as inconclusive', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    publicationClefConfigureUser($user);
    $invalid = publicationClefResponse(0.9);
    $invalid['result']['answers']['is_job']['noul'] = '0.9';
    $outOfRange = publicationClefResponse(1.01);
    $invalidConfidence = publicationClefResponse(0.9);
    $invalidConfidence['result']['answers']['non_job_kind']['confidence'] = '0.91';
    $partial = publicationClefResponse(0.9, ['errors' => [['code' => 1000]]]);
    $responses = [
        fn () => Http::response($invalid),
        fn () => Http::response($outOfRange),
        fn () => Http::response($invalidConfidence),
        fn () => Http::response($partial),
        fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
        fn () => Http::response('rate limited', 429),
    ];
    Http::fake(function () use (&$responses) {
        return array_shift($responses)();
    });

    publicationClefIngest($user, publicationClefPayload('invalid0001'));
    publicationClefIngest($user, publicationClefPayload('range000001'));
    publicationClefIngest($user, publicationClefPayload('confidence01'));
    publicationClefIngest($user, publicationClefPayload('partial0001'));
    publicationClefIngest($user, publicationClefPayload('timeout0001'));
    publicationClefIngest($user, publicationClefPayload('ratelimit001'));

    JoboraServer::tool(ListPublicationsTool::class, ['clef_status' => 'inconclusive'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 6)
            ->where('data.items', function ($items): bool {
                return $items->every(fn (array $item): bool => $item['clef']['status'] === 'inconclusive'
                    && $item['clef']['probability'] === null
                    && $item['clef']['confidence'] === null
                    && is_string($item['clef']['error'])
                    && $item['clef']['error'] !== '');
            })
            ->etc());
});

it('does not repeat Clef for replay and classifies changed content as a new version', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    publicationClefConfigureUser($user);
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        return Http::response(publicationClefResponse($calls === 1 ? 0.8 : 0.4));
    });
    $firstPayload = publicationClefPayload('replay0001');

    publicationClefIngest($user, $firstPayload);
    publicationClefIngest($user, $firstPayload, 200)->assertJsonPath('replay', true);
    publicationClefIngest($user, publicationClefPayload('replay0001', 'Descrição Indeed alterada.'));

    JoboraServer::tool(ListPublicationsTool::class, ['clef_status' => 'barred'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 1)
            ->where('data.items.0.publication.content_version', 2)
            ->where('data.items.0.clef.content_version', 2)
            ->where('data.items.0.clef.probability', 0.4)
            ->where('data.items.0.publication.content', fn (string $content): bool => str_contains($content, 'Descrição Indeed alterada.'))
            ->etc());

    JoboraServer::tool(ListPublicationsTool::class, ['clef_status' => 'eligible'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 0)
            ->etc());

    expect($calls)->toBe(2);
});

it('keeps a slower old-version result from replacing the current version result', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    publicationClefConfigureUser($user);
    $updatedPayload = publicationClefPayload('stale0001', 'Descrição Indeed versão dois.');
    $startedRecapture = false;
    Http::fake(function () use ($user, $updatedPayload, &$startedRecapture) {
        if (! $startedRecapture) {
            $startedRecapture = true;
            publicationClefIngest($user, $updatedPayload);

            return Http::response(publicationClefResponse(0.4999));
        }

        return Http::response(publicationClefResponse(0.5001));
    });

    publicationClefIngest($user, publicationClefPayload('stale0001'));

    JoboraServer::tool(ListPublicationsTool::class, ['clef_status' => 'eligible'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 1)
            ->where('data.items.0.publication.content_version', 2)
            ->where('data.items.0.clef.content_version', 2)
            ->where('data.items.0.clef.probability', 0.5001)
            ->etc());

    JoboraServer::tool(ListPublicationsTool::class, ['clef_status' => 'barred'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 0)
            ->etc());
});

it('isolates Clef-filtered publication listing between users', function () {
    Storage::fake('local');
    $owner = User::factory()->create();
    publicationClefConfigureUser($owner);
    Http::fake(['*' => Http::response(publicationClefResponse(0.8))]);
    publicationClefIngest($owner, publicationClefPayload('private0001'));

    $otherUser = User::factory()->create();
    publicationClefConfigureUser($otherUser);

    JoboraServer::tool(ListPublicationsTool::class, ['clef_status' => 'eligible'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 0)
            ->where('data.items', [])
            ->etc());
});

it('recovers pending Clef dispatch through its scheduled command', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    publicationClefConfigureUser($user);
    Queue::fake([ClassifyPublicationWithClef::class]);
    Http::fake(['*' => Http::response(publicationClefResponse(0.8))]);
    publicationClefIngest($user, publicationClefPayload('recover0001'));
    Queue::assertPushedOnce(ClassifyPublicationWithClef::class);

    JoboraServer::tool(ListPublicationsTool::class, ['clef_status' => 'pending'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 1)
            ->where('data.items.0.clef.status', 'pending')
            ->etc());

    $this->travel(12)->minutes();
    Artisan::call('publication-clef:recover');
    Queue::assertPushedTimes(ClassifyPublicationWithClef::class, 2);
});
