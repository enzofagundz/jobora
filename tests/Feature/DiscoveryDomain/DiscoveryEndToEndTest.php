<?php

use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Mcp\Servers\JoboraServer;
use App\Mcp\Tools\Captures\GetPageCaptureTool;
use App\Mcp\Tools\Captures\ListPublicationsTool;
use App\Mcp\Tools\Captures\RequestPublicationTriageTool;
use App\Mcp\Tools\Discovery\AcquireDiscoveryRunsTool;
use App\Mcp\Tools\JobPostings\ListJobPostingsTool;
use App\Mcp\Tools\System\GetAiOperationTool;
use App\Models\AiOperation;
use App\Models\DiscoveryRun;
use App\Models\DiscoverySource;
use App\Models\JobPosting;
use App\Models\PageCapture;
use App\Models\Publication;
use App\Models\PublicationClefResult;
use App\Models\PublicationObservation;
use App\Models\PublicationTriageResult;
use App\Models\User;
use App\Services\CaptureTokenService;
use App\Services\Discovery\DiscoverySourceUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('runs three controlled discovery surfaces through durable capture, Clef, explicit triage and ready MCP result', function () {
    Storage::fake('local');
    config([
        'queue.default' => 'database',
        'queue.connections.database.after_commit' => false,
        'triage.prefilter.account_id' => 'test-account',
        'triage.prefilter.api_token' => 'test-token',
        'triage.prefilter.model' => '@cf/cloudflare/clef-flash',
        'triage.prefilter.base_url' => 'https://api.cloudflare.com/client/v4',
    ]);

    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    config(['jobora.mcp.user' => $owner->email]);

    $fixtures = [
        ['file' => 'indeed-jobs.json', 'source' => 'indeed', 'surface' => 'indeed_jobs'],
        ['file' => 'linkedin-jobs.json', 'source' => 'linkedin', 'surface' => 'linkedin_jobs'],
        ['file' => 'linkedin-publications.json', 'source' => 'linkedin', 'surface' => 'linkedin_publications'],
    ];
    $sources = [];

    foreach ($fixtures as $index => $definition) {
        $fixture = json_decode(
            (string) file_get_contents(base_path('fixtures/capture-envelope/v1/'.$definition['file'])),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $url = $fixture['capture']['initial_url'];
        $sources[] = DiscoverySource::factory()->for($owner, 'user')->create([
            'name' => 'E2E '.$definition['surface'],
            'source' => $definition['source'],
            'surface' => $definition['surface'],
            'url' => $url,
            'canonical_url_hash' => app(DiscoverySourceUrl::class)->hash($url),
            'active' => true,
            'next_due_at' => now()->subMinutes(3 - $index),
        ]);
    }

    $clefCalls = 0;
    $triageCalls = 0;
    $triageOpportunity = null;
    $invalidTriageOutput = false;
    Http::fake(function (Request $request) use (&$clefCalls, &$triageCalls, &$triageOpportunity, &$invalidTriageOutput) {
        if (str_contains($request->url(), 'api.cloudflare.com')) {
            $clefCalls++;

            return Http::response([
                'success' => true,
                'errors' => [],
                'messages' => [],
                'result' => [
                    'model' => 'clef-flash',
                    'answers' => [
                        'is_job' => ['type' => 'noul', 'noul' => 0.8],
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
            ]);
        }

        if ($triageOpportunity === null) {
            throw new RuntimeException('Unexpected non-Clef provider request before explicit MCP triage.');
        }

        $triageCalls++;
        $structured = ['opportunities' => [$triageOpportunity]];

        if ($invalidTriageOutput) {
            $structured['opportunities'][0]['invented_field'] = 'must not become success';
        }

        return Http::response([
            'choices' => [[
                'message' => ['content' => json_encode($structured, JSON_THROW_ON_ERROR)],
                'finish_reason' => 'stop',
            ]],
            'model' => 'deepseek-v4.1-flash-free',
            'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 4],
        ]);
    });

    $captures = [];
    $runs = [];

    foreach ($fixtures as $index => $definition) {
        if (! isset($runs[$definition['surface']])) {
            JoboraServer::tool(AcquireDiscoveryRunsTool::class, [
                'idempotency_key' => 'discovery-e2e-'.$definition['surface'],
            ])->assertOk()
                ->assertStructuredContent(fn ($json) => $json
                    ->where('ok', true)
                    ->where('data.runs.0.surface', $definition['surface'])
                    ->where('data.runs.0.status', 'active')
                    ->etc());

            $runs[$definition['surface']] = DiscoveryRun::query()
                ->where('user_id', $owner->id)
                ->where('discovery_source_id', $sources[$index]->id)
                ->sole();
        }

        $run = $runs[$definition['surface']];

        $fixture = json_decode(
            (string) file_get_contents(base_path('fixtures/capture-envelope/v1/'.$definition['file'])),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $fixture['capture']['idempotency_key'] = 'discovery-e2e-upload-'.$definition['surface'];
        $fixture['capture']['execution_id'] = $run->execution_id;
        $fixture['capture']['captured_at'] = now()->toIso8601String();

        $upload = $this->withToken(app(CaptureTokenService::class)->issueToken($owner))
            ->postJson('/api/page-captures', $fixture)
            ->assertCreated()
            ->assertJsonPath('replay', false);

        $capture = PageCapture::query()->whereKey($upload->json('id'))->firstOrFail();
        $captures[$definition['surface']] = $capture;
        $operation = AiOperation::query()
            ->where('operation_key', 'publication_extraction:page_capture:'.$capture->id)
            ->sole();

        expect($capture->ingest_status)->toBe('received')
            ->and(Storage::disk('local')->exists((string) $capture->html_path))->toBeTrue()
            ->and($run->fresh()->page_capture_id)->toBe($capture->id)
            ->and($operation->status)->toBe(AiOperationStatus::Queued);

        JoboraServer::tool(GetPageCaptureTool::class, ['execution_id' => $run->execution_id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('data.capture.ingest_status', 'received')
                ->where('data.capture.extraction.status', 'pending')
                ->where('data.capture.surface', $definition['surface'])
                ->etc());

        $replay = $this->withToken(app(CaptureTokenService::class)->issueToken($owner))
            ->postJson('/api/page-captures', $fixture)
            ->assertOk()
            ->assertJsonPath('replay', true)
            ->assertJsonPath('id', $capture->id);

        expect($replay->json('execution_id'))->toBe($run->execution_id)
            ->and(PageCapture::query()->where('user_id', $owner->id)->count())->toBe($index + 1)
            ->and(AiOperation::query()->where('operation_type', AiOperationType::PublicationExtraction)->count())->toBe($index + 1)
            ->and(DB::table('jobs')->count())->toBe($index + 1);

        if ($index < count($fixtures) - 1) {
            JoboraServer::tool(AcquireDiscoveryRunsTool::class, [
                'idempotency_key' => 'discovery-e2e-next-'.$definition['surface'],
            ])->assertOk()
                ->assertStructuredContent(fn ($json) => $json
                    ->where('data.runs.0.source_id', $sources[$index + 1]->id)
                    ->where('data.runs.0.surface', $fixtures[$index + 1]['surface'])
                    ->where('data.runs.0.status', 'active')
                    ->etc());
            $runs[$fixtures[$index + 1]['surface']] = DiscoveryRun::query()
                ->where('user_id', $owner->id)
                ->where('discovery_source_id', $sources[$index + 1]->id)
                ->sole();

            expect($operation->fresh()->status)->toBe(AiOperationStatus::Queued)
                ->and($capture->refresh()->extractionOperation?->status)->toBe(AiOperationStatus::Queued);
        }
    }

    config(['jobora.mcp.user' => $otherUser->email]);
    JoboraServer::tool(GetPageCaptureTool::class, [
        'execution_id' => $runs['indeed_jobs']->execution_id,
    ])->assertHasErrors();
    JoboraServer::tool(ListPublicationsTool::class, ['clef_status' => 'eligible'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('data.total', 0)->where('data.items', [])->etc());
    config(['jobora.mcp.user' => $owner->email]);

    $this->travel(2)->minutes();
    expect(AiOperation::query()->where('operation_type', AiOperationType::PublicationExtraction)->get()
        ->every(fn (AiOperation $operation): bool => $operation->queued_at->lessThanOrEqualTo(now()->subMinute())))
        ->toBeTrue();
    $this->artisan('publication-extractions:recover')
        ->expectsOutput('Extração de publicações: 3 operação(ões) recuperada(s).')
        ->assertSuccessful();

    $queueExceptions = [];
    Event::listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event) use (&$queueExceptions): void {
        $queueExceptions[] = $event->exception->getMessage();
    });

    expect(DB::table('jobs')->count())->toBe(3)
        ->and(PageCapture::query()->count())->toBe(3)
        ->and(Publication::query()->count())->toBe(0);

    $handledJobs = 0;

    while (DB::table('jobs')->exists()) {
        expect($handledJobs)->toBeLessThan(20);
        $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
        $handledJobs++;
    }

    $expectedPublications = 0;
    $publicationsBySurface = [];

    foreach ($fixtures as $definition) {
        $capture = $captures[$definition['surface']];
        $publications = [];

        JoboraServer::tool(ListPublicationsTool::class, ['page_capture_id' => $capture->id])
            ->assertOk()
            ->assertStructuredContent(function ($json) use (&$publications, $definition): void {
                $json->where('data.capture.surface', $definition['surface'])
                    ->where('data.extraction.status', 'completed')
                    ->where('data.items', function (Collection $items) use (&$publications): bool {
                        $publications = $items->map(fn (array $item): array => [
                            'publication' => $item['publication'],
                            'observation' => $item['observation'],
                            'clef' => $item['clef'],
                        ])->all();

                        return $items->isNotEmpty();
                    })
                    ->etc();
            });

        $expectedPublications += count($publications);
        $publicationsBySurface[$definition['surface']] = $publications;
    }

    $actualCapturedPublications = Publication::query()->where('user_id', $owner->id)->count();
    expect($actualCapturedPublications)->toBe($expectedPublications)
        ->and(PublicationObservation::query()->count())->toBe($expectedPublications)
        ->and(PublicationClefResult::query()->count())->toBe($expectedPublications)
        ->and(PublicationClefResult::query()->where('status', 'eligible')->count())->toBe($expectedPublications)
        ->and(AiOperation::query()->where('operation_type', AiOperationType::PublicationExtraction)->where('status', AiOperationStatus::Completed)->count())->toBe(3)
        ->and(AiOperation::query()->where('operation_type', AiOperationType::PublicationTriage)->count())->toBe(0)
        ->and($clefCalls)->toBe($expectedPublications)
        ->and($triageCalls)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);

    foreach ($fixtures as $definition) {
        $publication = $publicationsBySurface[$definition['surface']][0];
        $content = $publication['publication']['content'];
        $evidenceBlockIds = $publication['observation']['evidence_block_ids'];
        $quote = mb_substr($content, 0, 180);
        $role = match ($definition['surface']) {
            'indeed_jobs' => 'Vaga Exemplo Indeed',
            'linkedin_jobs' => 'Vaga Exemplo PHP',
            'linkedin_publications' => 'vaga sintética em PHP',
        };
        $triageOpportunity = [
            'role' => $role,
            'company' => null,
            'description' => $quote,
            'location' => null,
            'work_mode' => null,
            'employment_type' => null,
            'salary_min' => null,
            'salary_max' => null,
            'salary_currency' => null,
            'salary_period' => null,
            'seniority' => null,
            'requirements' => [],
            'technologies' => [],
            'application_methods' => [],
            'evidence' => [[
                'quote' => $quote,
                'block_ids' => $evidenceBlockIds,
                'fields' => ['role', 'description'],
            ]],
        ];
        $triageCallsBefore = $triageCalls;

        JoboraServer::tool(RequestPublicationTriageTool::class, [
            'publication_id' => $publication['publication']['id'],
            'content_version' => $publication['publication']['content_version'],
        ])->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('data.type', AiOperationType::PublicationTriage->value)
                ->where('data.status', 'queued')
                ->where('data.publication_id', $publication['publication']['id'])
                ->etc());

        $triageOperation = AiOperation::query()
            ->where('operation_type', AiOperationType::PublicationTriage)
            ->where('target_id', $publication['publication']['id'])
            ->sole();
        $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
        $triageOpportunity = null;

        expect($queueExceptions)->toBeEmpty()
            ->and($triageCalls)->toBeGreaterThan($triageCallsBefore)
            ->and($triageOperation->fresh()->failure_code)->toBeNull()
            ->and($triageOperation->fresh()->status)->toBe(AiOperationStatus::Completed);

        $processedQueueJobs = 0;
        while (DB::table('jobs')->exists()) {
            expect($processedQueueJobs)->toBeLessThan(5);
            $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
            $processedQueueJobs++;
        }

        expect(DB::table('jobs')->count())->toBe(0);
    }

    $failedPublication = $publicationsBySurface['linkedin_publications'][1];
    $failedContent = $failedPublication['publication']['content'];
    $failedQuote = mb_substr($failedContent, 0, 180);
    $triageOpportunity = [
        'role' => 'oportunidade sintética em QA',
        'company' => null,
        'description' => $failedQuote,
        'location' => null,
        'work_mode' => null,
        'employment_type' => null,
        'salary_min' => null,
        'salary_max' => null,
        'salary_currency' => null,
        'salary_period' => null,
        'seniority' => null,
        'requirements' => [],
        'technologies' => [],
        'application_methods' => [],
        'evidence' => [[
            'quote' => $failedQuote,
            'block_ids' => $failedPublication['observation']['evidence_block_ids'],
            'fields' => ['role', 'description'],
        ]],
    ];
    $invalidTriageOutput = true;
    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $failedPublication['publication']['id'],
        'content_version' => $failedPublication['publication']['content_version'],
    ])->assertOk();
    $failedTriageOperation = AiOperation::query()
        ->where('operation_type', AiOperationType::PublicationTriage)
        ->where('target_id', $failedPublication['publication']['id'])
        ->sole();
    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
    $invalidTriageOutput = false;

    $processedQueueJobs = 0;
    while (DB::table('jobs')->exists()) {
        expect($processedQueueJobs)->toBeLessThan(5);
        $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
        $processedQueueJobs++;
    }

    expect($failedTriageOperation->fresh()->status)->toBe(AiOperationStatus::Failed)
        ->and($failedTriageOperation->fresh()->failure_code)->toBe('invalid_output')
        ->and(PublicationTriageResult::query()->where('ai_operation_id', $failedTriageOperation->id)->sole()->status)->toBe('failed')
        ->and(JobPosting::query()->where('user_id', $owner->id)->count())->toBe(3)
        ->and($triageCalls)->toBeGreaterThanOrEqual(4)
        ->and($queueExceptions)->toBeEmpty()
        ->and(DB::table('jobs')->count())->toBe(0);

    JoboraServer::tool(GetAiOperationTool::class, [
        'publication_id' => $failedPublication['publication']['id'],
        'type' => AiOperationType::PublicationTriage->value,
    ])->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.status', 'failed')
            ->where('data.failure_code', 'invalid_output')
            ->where('data.publication_triage_result.status', 'failed')
            ->where('data.publication_triage_result.error_code', 'invalid_output')
            ->etc());

    JoboraServer::tool(ListJobPostingsTool::class, ['readiness' => 'ready'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 3)
            ->where('data.items', function (Collection $items): bool {
                return $items->count() === 3
                    && $items->every(fn (array $item): bool => $item['readiness'] === 'ready');
            })
            ->etc());

    expect(AiOperation::query()->where('operation_type', AiOperationType::PublicationTriage)->where('status', AiOperationStatus::Completed)->count())->toBe(3)
        ->and(AiOperation::query()->whereNotIn('operation_type', [
            AiOperationType::PublicationExtraction->value,
            AiOperationType::PublicationTriage->value,
        ])->count())->toBe(0)
        ->and($triageCalls)->toBeGreaterThanOrEqual(4)
        ->and($clefCalls)->toBe($expectedPublications);

    config(['jobora.mcp.user' => $otherUser->email]);
    JoboraServer::tool(GetPageCaptureTool::class, [
        'execution_id' => $runs['indeed_jobs']->execution_id,
    ])->assertHasErrors();
    JoboraServer::tool(ListPublicationsTool::class, ['clef_status' => 'eligible'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 0)
            ->where('data.items', [])
            ->etc());
    JoboraServer::tool(GetAiOperationTool::class, ['id' => $failedTriageOperation->id])->assertHasErrors();
    JoboraServer::tool(ListJobPostingsTool::class, ['readiness' => 'ready'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('data.total', 0)->where('data.items', [])->etc());

    config(['jobora.mcp.user' => $owner->email]);
    expect($otherUser->id)->not->toBe($owner->id);
});
