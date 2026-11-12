<?php

use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Jobs\ClassifyPublicationWithClef;
use App\Jobs\ProcessAiOperation;
use App\Mcp\Servers\JoboraServer;
use App\Mcp\Tools\Captures\ListPublicationsTool;
use App\Mcp\Tools\Captures\RequestPublicationTriageTool;
use App\Mcp\Tools\System\GetAiOperationTool;
use App\Models\AiOperation;
use App\Models\JobPosting;
use App\Models\PageCapture;
use App\Models\Publication;
use App\Models\PublicationClefResult;
use App\Models\PublicationObservation;
use App\Models\PublicationTriageResult;
use App\Models\User;
use App\Services\AiOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

final class PublicationOperationTimeoutFixture extends ProcessAiOperation
{
    public function handle(AiOperationService $service): void
    {
        throw new TimeoutExceededException('Controlled worker timeout fixture.');
    }
}

/**
 * @return array{publication: Publication, clef: PublicationClefResult}
 */
function recoveryRegressionPublication(User $user, string $suffix): array
{
    $content = "A public company announced an open software engineering role {$suffix}.";
    $publication = Publication::factory()->for($user)->create([
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
    ]);
    $capture = PageCapture::factory()->for($user)->create();

    PublicationObservation::query()->create([
        'page_capture_id' => $capture->id,
        'publication_id' => $publication->id,
        'observed_at' => now(),
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
        'evidence_block_ids' => ['block-'.$suffix],
    ]);

    $clef = PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 1,
        'attempt' => 1,
        'status' => 'inconclusive',
        'error' => 'rate_limited',
        'classified_at' => now(),
    ]);

    return compact('publication', 'clef');
}

/**
 * @return array<string, mixed>
 */
function recoveryRegressionClefResponse(float $jobProbability = 0.9): array
{
    return [
        'success' => true,
        'errors' => [],
        'messages' => [],
        'result' => [
            'model' => 'clef-flash',
            'answers' => [
                'is_job' => ['type' => 'noul', 'noul' => $jobProbability],
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
}

beforeEach(function (): void {
    config([
        'queue.default' => 'database',
        'queue.connections.database.after_commit' => false,
        'jobora.mcp.user' => null,
        'triage.prefilter.account_id' => 'test-account',
        'triage.prefilter.api_token' => 'test-token',
        'triage.prefilter.model' => '@cf/cloudflare/clef-flash',
        'triage.prefilter.base_url' => 'https://api.cloudflare.com/client/v4',
    ]);
});

it('retries only Clef explicitly for current publication version and preserves earlier attempts', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    config(['jobora.mcp.user' => $user->email]);
    ['publication' => $publication, 'clef' => $firstAttempt] = recoveryRegressionPublication($user, 'first');
    ['publication' => $bypassPublication] = recoveryRegressionPublication($user, 'bypass');
    Http::fake(['*' => Http::response(recoveryRegressionClefResponse())]);

    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
        'retry_clef' => true,
    ])->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.publication_id', $publication->id)
            ->where('data.content_version', 1)
            ->where('data.clef_status', 'pending')
            ->where('data.clef_attempt', 2)
            ->etc());

    $secondAttempt = PublicationClefResult::query()
        ->where('publication_id', $publication->id)
        ->where('attempt', 2)
        ->sole();

    expect($firstAttempt->fresh()->status)->toBe('inconclusive')
        ->and($secondAttempt->status)->toBe('pending')
        ->and(AiOperation::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1);

    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

    expect($secondAttempt->fresh()->status)->toBe('eligible')
        ->and($firstAttempt->fresh()->status)->toBe('inconclusive')
        ->and(PublicationClefResult::query()->where('publication_id', $publication->id)->count())->toBe(2)
        ->and(AiOperation::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);

    JoboraServer::tool(ListPublicationsTool::class, ['clef_status' => 'eligible'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.total', 1)
            ->where('data.items.0.publication.id', $publication->id)
            ->where('data.items.0.clef.attempt', 2)
            ->where('data.items.0.clef.status', 'eligible')
            ->etc());

    config(['jobora.mcp.user' => $otherUser->email]);
    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
        'retry_clef' => true,
    ])->assertHasErrors();
    config(['jobora.mcp.user' => $user->email]);

    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $bypassPublication->id,
        'content_version' => 1,
        'bypass_clef' => true,
        'reason' => 'Explicit human review accepts Clef outage risk.',
    ])->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.status', 'queued')
            ->where('data.publication_id', $bypassPublication->id)
            ->etc());

    $bypassOperation = AiOperation::query()
        ->where('operation_type', AiOperationType::PublicationTriage)
        ->where('target_id', $bypassPublication->id)
        ->sole();

    expect($bypassOperation->status)->toBe(AiOperationStatus::Queued)
        ->and($bypassOperation->input_snapshot['clef_status'])->toBe('inconclusive')
        ->and($bypassOperation->input_snapshot['clef_bypassed'])->toBeTrue()
        ->and($bypassOperation->input_snapshot['clef_bypass_reason'])->toBe('Explicit human review accepts Clef outage risk.')
        ->and(DB::table('jobs')->count())->toBe(1);

    Http::assertSentCount(1);
    expect(json_decode(DB::table('jobs')->value('payload'), true)['displayName'])
        ->toBe(ProcessAiOperation::class);
});

it('keeps completed bypass triage immutable when caller changes bypass reason', function () {
    $user = User::factory()->create();
    config(['jobora.mcp.user' => $user->email]);
    ['publication' => $publication, 'clef' => $clef] = recoveryRegressionPublication($user, 'bypass-idempotency');
    $clef->forceFill(['status' => 'barred'])->save();
    $reason = 'Reviewed original Clef result manually.';
    $opportunity = [
        'role' => 'Software Engineer',
        'company' => 'Example Company',
        'description' => $publication->content,
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
            'quote' => $publication->content,
            'block_ids' => ['block-bypass-idempotency'],
        ]],
    ];
    Http::fake(['*' => Http::response([
        'choices' => [[
            'message' => ['content' => json_encode(['opportunities' => [$opportunity]], JSON_THROW_ON_ERROR)],
            'finish_reason' => 'stop',
        ]],
        'model' => 'deepseek-v4.1-flash-free',
        'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 12],
    ])]);

    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
        'bypass_clef' => true,
        'reason' => $reason,
    ])->assertOk();

    $operation = AiOperation::query()->sole();
    $runToken = $operation->run_token;
    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
    while (DB::table('jobs')->exists()) {
        $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
    }

    expect($operation->fresh()->status)->toBe(AiOperationStatus::Completed)
        ->and(JobPosting::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(PublicationTriageResult::query()->count())->toBe(1);

    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
        'bypass_clef' => true,
        'reason' => 'A different reason must not re-run completed work.',
    ])->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.operation_id', $operation->id)
            ->where('data.status', 'completed')
            ->etc());

    expect($operation->fresh()->run_token)->toBe($runToken)
        ->and($operation->fresh()->status)->toBe(AiOperationStatus::Completed)
        ->and($operation->fresh()->input_snapshot['clef_bypass_reason'])->toBe($reason)
        ->and(AiOperation::query()->count())->toBe(1)
        ->and(JobPosting::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(PublicationTriageResult::query()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);

    Http::assertSentCount(1);
});

it('reuses completed bypass when Clef retry changes current decision', function () {
    $user = User::factory()->create();
    config(['jobora.mcp.user' => $user->email]);
    ['publication' => $publication] = recoveryRegressionPublication($user, 'clef-decision-idempotency');
    $triageOpportunity = [
        'role' => 'Software Engineer',
        'company' => 'Example Company',
        'description' => $publication->content,
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
            'quote' => $publication->content,
            'block_ids' => ['block-clef-decision-idempotency'],
        ]],
    ];
    $triageCalls = 0;
    $clefCalls = 0;
    Http::fake(function (Request $request) use (&$triageCalls, &$clefCalls, $triageOpportunity) {
        if (str_contains($request->url(), 'api.cloudflare.com')) {
            $clefCalls++;

            return Http::response(recoveryRegressionClefResponse(0.1));
        }

        $triageCalls++;

        return Http::response([
            'choices' => [[
                'message' => ['content' => json_encode(['opportunities' => [$triageOpportunity]], JSON_THROW_ON_ERROR)],
                'finish_reason' => 'stop',
            ]],
            'model' => 'deepseek-v4.1-flash-free',
            'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 12],
        ]);
    });

    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
        'bypass_clef' => true,
        'reason' => 'Explicit review accepts uncertain Clef result.',
    ])->assertOk();

    $operation = AiOperation::query()->sole();
    $runToken = $operation->run_token;
    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
    while (DB::table('jobs')->exists()) {
        $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
    }

    expect($operation->fresh()->status)->toBe(AiOperationStatus::Completed)
        ->and(JobPosting::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(PublicationTriageResult::query()->count())->toBe(1);

    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
        'retry_clef' => true,
    ])->assertOk();
    expect(DB::table('jobs')->count())->toBe(1);
    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

    expect(PublicationClefResult::query()
        ->where('publication_id', $publication->id)
        ->orderByDesc('attempt')
        ->value('status'))->toBe('barred');

    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
        'bypass_clef' => true,
        'reason' => 'Clef retry changed decision; preserve completed triage.',
    ])->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.operation_id', $operation->id)
            ->where('data.status', 'completed')
            ->etc());

    expect($operation->fresh()->run_token)->toBe($runToken)
        ->and($operation->fresh()->status)->toBe(AiOperationStatus::Completed)
        ->and(AiOperation::query()->count())->toBe(1)
        ->and(JobPosting::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(PublicationTriageResult::query()->count())->toBe(1)
        ->and($triageCalls)->toBe(1)
        ->and($clefCalls)->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('reuses completed bypass while explicit Clef retry is pending', function () {
    $user = User::factory()->create();
    config(['jobora.mcp.user' => $user->email]);
    ['publication' => $publication] = recoveryRegressionPublication($user, 'pending-clef-replay');
    $llmCalls = 0;
    Http::fake(function () use (&$llmCalls) {
        $llmCalls++;

        return Http::response([
            'choices' => [[
                'message' => ['content' => json_encode(['opportunities' => []], JSON_THROW_ON_ERROR)],
                'finish_reason' => 'stop',
            ]],
            'model' => 'deepseek-v4.1-flash-free',
            'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 2],
        ]);
    });

    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
        'bypass_clef' => true,
        'reason' => 'Explicit review accepts uncertain Clef result.',
    ])->assertOk();

    $operation = AiOperation::query()->sole();
    $runToken = $operation->run_token;
    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
    while (DB::table('jobs')->exists()) {
        $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
    }

    expect($operation->fresh()->status)->toBe(AiOperationStatus::Completed)
        ->and(PublicationTriageResult::query()->count())->toBe(1);

    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
        'retry_clef' => true,
    ])->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.clef_status', 'pending')
            ->where('data.clef_attempt', 2)
            ->etc());

    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
        'bypass_clef' => true,
        'reason' => 'Explicit review accepts uncertain Clef result.',
    ])->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.operation_id', $operation->id)
            ->where('data.status', 'completed')
            ->etc());

    expect($operation->fresh()->run_token)->toBe($runToken)
        ->and($operation->fresh()->status)->toBe(AiOperationStatus::Completed)
        ->and(AiOperation::query()->count())->toBe(1)
        ->and(PublicationTriageResult::query()->count())->toBe(1)
        ->and($llmCalls)->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(json_decode(DB::table('jobs')->value('payload'), true)['displayName'])
        ->toBe(ClassifyPublicationWithClef::class);
});

it('recovers stale claimed triage despite orphaned uniqueness lock and fences old attempt', function () {
    $user = User::factory()->create();
    config(['jobora.mcp.user' => $user->email]);
    ['publication' => $publication, 'clef' => $clef] = recoveryRegressionPublication($user, 'stale-claimed');
    $clef->forceFill(['status' => 'eligible'])->save();
    Http::fake(['*' => Http::response([
        'choices' => [[
            'message' => ['content' => json_encode(['opportunities' => []], JSON_THROW_ON_ERROR)],
            'finish_reason' => 'stop',
        ]],
        'model' => 'deepseek-v4.1-flash-free',
        'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 2],
    ])]);

    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
    ])->assertOk();

    $operation = AiOperation::query()->sole();
    $runToken = $operation->run_token;
    $oldAttemptToken = $operation->attempt_token;
    DB::table('jobs')->delete();
    $operation->forceFill([
        'status' => AiOperationStatus::Running,
        'attempts' => 1,
        'queued_at' => now()->subMinutes(3),
        'started_at' => now()->subMinutes(3),
        'last_attempt_started_at' => now()->subMinutes(3),
        'expires_at' => now()->addMinutes(2),
    ])->save();

    $this->artisan('publication-triages:recover')->assertSuccessful();

    $recoveredOperation = $operation->fresh();
    expect($recoveredOperation->status)->toBe(AiOperationStatus::Queued)
        ->and($recoveredOperation->attempt_token)->not->toBe($oldAttemptToken)
        ->and($recoveredOperation->run_token)->toBe($runToken)
        ->and(DB::table('jobs')->count())->toBe(1);

    (new ProcessAiOperation($operation->id, $runToken, $oldAttemptToken))
        ->handle(app(AiOperationService::class));
    app(AiOperationService::class)->fail(
        $operation->id,
        $runToken,
        new RuntimeException('late failure from fenced worker'),
        $oldAttemptToken,
        3,
    );

    expect($operation->fresh()->status)->toBe(AiOperationStatus::Queued)
        ->and($operation->fresh()->attempt_token)->toBe($recoveredOperation->attempt_token)
        ->and(DB::table('jobs')->count())->toBe(1);

    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
    while (DB::table('jobs')->exists()) {
        $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
    }

    expect($operation->fresh()->status)->toBe(AiOperationStatus::Completed)
        ->and($operation->fresh()->attempts)->toBe(2)
        ->and(PublicationTriageResult::query()->where('ai_operation_id', $operation->id)->sole()->attempt)->toBe(2);

    Http::assertSentCount(1);
});

it('expires stale running triage after its deadline and allows explicit LLM retry', function () {
    $user = User::factory()->create();
    config(['jobora.mcp.user' => $user->email]);
    ['publication' => $publication, 'clef' => $clef] = recoveryRegressionPublication($user, 'expired-running');
    $clef->forceFill(['status' => 'eligible'])->save();
    Http::fake(['*' => Http::response([
        'choices' => [[
            'message' => ['content' => json_encode(['opportunities' => []], JSON_THROW_ON_ERROR)],
            'finish_reason' => 'stop',
        ]],
        'model' => 'deepseek-v4.1-flash-free',
        'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 2],
    ])]);

    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
    ])->assertOk();

    $operation = AiOperation::query()->sole();
    $oldRunToken = $operation->run_token;
    $oldAttemptToken = $operation->attempt_token;
    DB::table('jobs')->delete();
    $operation->forceFill([
        'status' => AiOperationStatus::Running,
        'attempts' => 1,
        'started_at' => now()->subMinutes(6),
        'last_attempt_started_at' => now()->subMinutes(6),
        'expires_at' => now()->subMinute(),
    ])->save();

    $this->artisan('publication-triages:recover')->assertSuccessful();

    expect($operation->fresh()->status)->toBe(AiOperationStatus::Failed)
        ->and($operation->fresh()->failure_code)->toBe('worker_unavailable')
        ->and(PublicationTriageResult::query()->where('ai_operation_id', $operation->id)->sole()->error_code)->toBe('worker_unavailable');

    JoboraServer::tool(GetAiOperationTool::class, ['id' => $operation->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.status', 'failed')
            ->where('data.failure_code', 'worker_unavailable')
            ->etc());

    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
        'retry' => true,
    ])->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.status', 'queued')
            ->where('data.publication_id', $publication->id)
            ->etc());

    expect($operation->fresh()->run_token)->not->toBe($oldRunToken)
        ->and($operation->fresh()->status)->toBe(AiOperationStatus::Queued);

    (new ProcessAiOperation($operation->id, $oldRunToken, $oldAttemptToken))
        ->handle(app(AiOperationService::class));
    app(AiOperationService::class)->fail(
        $operation->id,
        $oldRunToken,
        new RuntimeException('late callback after explicit retry'),
        $oldAttemptToken,
        3,
    );

    expect($operation->fresh()->status)->toBe(AiOperationStatus::Queued);

    $processedJobs = 0;
    while ($operation->fresh()->status !== AiOperationStatus::Completed) {
        expect($processedJobs)->toBeLessThan(5);
        $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
        $processedJobs++;
    }
    while (DB::table('jobs')->exists()) {
        expect($processedJobs)->toBeLessThan(8);
        $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
        $processedJobs++;
    }

    expect($operation->fresh()->failure_code)->toBeNull()
        ->and(PublicationClefResult::query()->where('publication_id', $publication->id)->count())->toBe(1)
        ->and(PublicationTriageResult::query()->where('ai_operation_id', $operation->id)->orderBy('id')->pluck('status')->all())
        ->toBe(['failed', 'completed'])
        ->and(DB::table('jobs')->count())->toBe(0);

    Http::assertSentCount(1);
});

it('does not let timed-out old token fail active reclaimed triage attempt', function () {
    config(['queue.failed.database' => null]);
    $user = User::factory()->create();
    config(['jobora.mcp.user' => $user->email]);
    ['publication' => $publication, 'clef' => $clef] = recoveryRegressionPublication($user, 'old-timeout-callback');
    $clef->forceFill(['status' => 'eligible'])->save();

    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
    ])->assertOk();

    $operation = AiOperation::query()->sole();
    $oldAttemptToken = $operation->attempt_token;
    $currentAttemptToken = (string) Str::uuid();
    DB::table('jobs')->delete();
    $operation->forceFill([
        'status' => AiOperationStatus::Running,
        'attempts' => 3,
        'attempt_token' => $currentAttemptToken,
        'started_at' => now()->subMinute(),
        'last_attempt_started_at' => now(),
        'expires_at' => now()->addMinutes(2),
    ])->save();

    PublicationOperationTimeoutFixture::dispatch(
        $operation->id,
        $operation->run_token,
        $oldAttemptToken,
        'old-timeout-callback',
    );
    DB::table('jobs')->update(['attempts' => 2]);

    $this->artisan('queue:work', ['--once' => true, '--tries' => 3])->assertSuccessful();

    expect($operation->fresh()->status)->toBe(AiOperationStatus::Running)
        ->and($operation->fresh()->attempts)->toBe(3)
        ->and($operation->fresh()->attempt_token)->toBe($currentAttemptToken)
        ->and($operation->fresh()->failure_code)->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0);

    $operation->forceFill(['attempt_token' => null])->save();
    PublicationOperationTimeoutFixture::dispatch(
        $operation->id,
        $operation->run_token,
        null,
        'legacy-tokenless-timeout',
    );
    DB::table('jobs')->update(['attempts' => 2]);

    $this->artisan('queue:work', ['--once' => true, '--tries' => 3])->assertSuccessful();

    expect($operation->fresh()->status)->toBe(AiOperationStatus::Failed)
        ->and($operation->fresh()->failure_code)->toBe('processing_failed');
});
