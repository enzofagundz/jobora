<?php

use App\Enums\AiOperationStatus;
use App\Enums\ConceptCategory;
use App\Enums\ItemSource;
use App\Enums\JobPostingStatus;
use App\Jobs\ProcessAiOperation;
use App\Mcp\Servers\JoboraServer;
use App\Mcp\Tools\Captures\RequestPublicationTriageTool;
use App\Mcp\Tools\JobPostings\GetJobPostingTool;
use App\Mcp\Tools\JobPostings\ReviewJobPostingTool;
use App\Mcp\Tools\System\GetAiOperationTool;
use App\Models\AiOperation;
use App\Models\JobPosting;
use App\Models\JobPostingItem;
use App\Models\OpportunitySource;
use App\Models\PageCapture;
use App\Models\Publication;
use App\Models\PublicationClefResult;
use App\Models\PublicationObservation;
use App\Models\PublicationTriageResult;
use App\Models\User;
use App\Services\AiOperationService;
use App\Services\OpportunityReviewConflict;
use App\Services\OpportunityReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Mcp\McpTestHelpers;

uses(RefreshDatabase::class);

function publicationTriageManagementOpportunity(User $user, array $overrides = [], array $publicationOverrides = []): JobPosting
{
    $content = 'Backend Engineer needed at Example Ltd. Build backend systems. Requirements: PHP 8.4. Technologies: Laravel. Apply at https://jobs.example.test/apply.';
    $publication = Publication::factory()->for($user)->create([
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
        ...$publicationOverrides,
    ]);
    if (is_string($publication->content) && is_string($publication->content_hash)) {
        $capture = PageCapture::factory()->for($user)->create();
        PublicationObservation::factory()->create([
            'page_capture_id' => $capture->id,
            'publication_id' => $publication->id,
            'content' => $publication->content,
            'content_hash' => $publication->content_hash,
            'content_version' => 1,
            'evidence_block_ids' => ['block-1', 'snapshot-block-2'],
        ]);
    }
    $posting = $user->jobPostings()->create([
        'description' => 'Build backend systems.',
        'source_url' => $publication->url,
        'collected_at' => now(),
        'status' => JobPostingStatus::Pending,
        'role' => null,
        'company' => 'Example Ltd',
        'readiness' => 'needs_review',
        'opportunity_version' => 4,
        'requirements' => ['PHP 8.4'],
        'technologies' => ['Laravel'],
        'evidence' => [[
            'quote' => $content,
            'block_ids' => ['block-1'],
            'fields' => ['role', 'company', 'description', 'requirements', 'technologies'],
        ]],
        'review_reasons' => ['missing_role'],
        ...$overrides,
    ]);
    JobPostingItem::query()->create([
        'job_posting_id' => $posting->id,
        'category' => ConceptCategory::Skill,
        'name' => 'Laravel',
        'source' => ItemSource::Ai,
    ]);
    OpportunitySource::query()->create([
        'job_posting_id' => $posting->id,
        'publication_id' => $publication->id,
        'is_legacy' => false,
        'publication_content_version' => 1,
        'evidence_block_ids' => ['block-1'],
        'evidence' => [[
            'quote' => $content,
            'block_ids' => ['block-1'],
            'fields' => ['role', 'company', 'description', 'requirements', 'technologies'],
        ]],
    ]);

    return $posting;
}

it('allows explicit Clef bypass with reason and idempotent audit snapshot', function () {
    $user = McpTestHelpers::user();
    Queue::fake();
    $content = 'Publicação anunciando oportunidade de trabalho para pessoa desenvolvedora.';
    $publication = Publication::factory()->for($user)->create([
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
    ]);
    $clefResult = PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 1,
        'attempt' => 1,
        'status' => 'barred',
    ]);
    $arguments = [
        'publication_id' => $publication->id,
        'content_version' => 1,
        'bypass_clef' => true,
        'reason' => 'Anúncio apresenta cargo e candidatura explícitos.',
    ];

    $first = JoboraServer::tool(RequestPublicationTriageTool::class, $arguments)->assertOk();
    $second = JoboraServer::tool(RequestPublicationTriageTool::class, $arguments)->assertOk();
    $operation = AiOperation::query()->sole();

    $first->assertStructuredContent(fn ($json) => $json
        ->where('ok', true)
        ->where('data.operation_id', $operation->id)
        ->where('data.status', 'queued')
        ->etc());
    $second->assertStructuredContent(fn ($json) => $json
        ->where('ok', true)
        ->where('data.operation_id', $operation->id)
        ->etc());

    expect($operation->input_snapshot)->toMatchArray([
        'clef_result_id' => $clefResult->id,
        'clef_status' => 'barred',
        'clef_bypassed' => true,
        'clef_bypass_reason' => $arguments['reason'],
        'requested_by' => $user->id,
    ]);
    Queue::assertPushed(ProcessAiOperation::class, 1);

    Http::fake(['*' => Http::response([
        'choices' => [[
            'message' => ['content' => json_encode(['opportunities' => []], JSON_THROW_ON_ERROR)],
            'finish_reason' => 'stop',
        ]],
        'model' => 'deepseek-v4.1-flash-free',
        'usage' => ['prompt_tokens' => 6, 'completion_tokens' => 2],
    ])]);
    (new ProcessAiOperation($operation->id, $operation->run_token, $operation->attempt_token))
        ->handle(app(AiOperationService::class));
    $triageResult = PublicationTriageResult::query()->sole();

    expect($triageResult->clef_result_id)->toBe($clefResult->id)
        ->and($triageResult->clef_status)->toBe('barred')
        ->and($triageResult->clef_bypassed)->toBeTrue()
        ->and($triageResult->clef_bypass_reason)->toBe($arguments['reason'])
        ->and($triageResult->requested_by)->toBe($user->id);
    JoboraServer::tool(GetAiOperationTool::class, ['id' => $operation->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.publication_triage_results.0.clef_result_id', $clefResult->id)
            ->where('data.publication_triage_results.0.clef_bypassed', true)
            ->where('data.publication_triage_results.0.requested_by', $user->id)
            ->etc());
    Http::assertSentCount(1);
});

it('reuses completed triage when Clef decision changes for the same publication version', function () {
    $user = McpTestHelpers::user();
    Queue::fake();
    $content = 'Backend developer role announced with clear application instructions.';
    $publication = Publication::factory()->for($user)->create([
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
    ]);
    $eligibleClef = PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 1,
        'attempt' => 1,
        'status' => 'eligible',
    ]);
    Http::fakeSequence()
        ->push([
            'choices' => [[
                'message' => ['content' => json_encode(['opportunities' => []], JSON_THROW_ON_ERROR)],
                'finish_reason' => 'stop',
            ]],
            'model' => 'deepseek-v4.1-flash-free',
            'usage' => ['prompt_tokens' => 6, 'completion_tokens' => 2],
        ])
        ->push([
            'choices' => [[
                'message' => ['content' => json_encode(['opportunities' => []], JSON_THROW_ON_ERROR)],
                'finish_reason' => 'stop',
            ]],
            'model' => 'deepseek-v4.1-flash-free',
            'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 2],
        ]);
    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
    ])->assertOk();
    $operation = AiOperation::query()->sole();
    $firstRunToken = $operation->run_token;
    (new ProcessAiOperation($operation->id, $firstRunToken, $operation->attempt_token))
        ->handle(app(AiOperationService::class));

    $barredClef = PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 1,
        'attempt' => 2,
        'status' => 'barred',
    ]);
    $reason = 'Manual evidence confirms active job announcement.';
    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
        'bypass_clef' => true,
        'reason' => $reason,
    ])->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.operation_id', $operation->id)
            ->where('data.status', 'completed')
            ->etc());
    $reevaluation = $operation->fresh();
    expect($reevaluation->run_token)->toBe($firstRunToken)
        ->and($reevaluation->status)->toBe(AiOperationStatus::Completed);

    $results = PublicationTriageResult::query()->where('ai_operation_id', $operation->id)->orderBy('id')->get();
    expect($results)->toHaveCount(1)
        ->and($results[0]->clef_result_id)->toBe($eligibleClef->id)
        ->and($results[0]->clef_status)->toBe('eligible')
        ->and($results[0]->clef_bypassed)->toBeFalse()
        ->and($results[0]->clef_bypass_reason)->toBeNull()
        ->and($results[0]->output)->toBe(['outcome' => 'not_a_job', 'opportunities' => []]);

    $newContent = 'A later persisted version of the same publication.';
    $publication->forceFill([
        'content' => $newContent,
        'content_hash' => hash('sha256', $newContent),
        'content_version' => 2,
    ])->save();
    PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 2,
        'attempt' => 1,
        'status' => 'eligible',
    ]);
    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 2,
    ])->assertOk();
    $nextVersionOperation = AiOperation::query()->where('target_id', $publication->id)->where('operation_key', '!=', $operation->operation_key)->sole();
    (new ProcessAiOperation(
        $nextVersionOperation->id,
        $nextVersionOperation->run_token,
        $nextVersionOperation->attempt_token,
    ))->handle(app(AiOperationService::class));

    JoboraServer::tool(GetAiOperationTool::class, [
        'publication_id' => $publication->id,
        'type' => 'publication_triage',
    ])->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.publication_triage_history.0.content_version', 1)
            ->where('data.publication_triage_history.1.content_version', 2)
            ->etc());
    Queue::assertPushed(ProcessAiOperation::class, 2);
    Http::assertSentCount(2);
});

it('retries failed triage from stored publication version without rerunning Clef', function () {
    $user = McpTestHelpers::user();
    Queue::fake();
    $oldContent = 'Older persisted publication text contains a developer job description.';
    $publication = Publication::factory()->for($user)->create([
        'content' => $oldContent,
        'content_hash' => hash('sha256', $oldContent),
        'content_version' => 1,
    ]);
    $oldClefResult = PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 1,
        'attempt' => 1,
        'status' => 'eligible',
    ]);
    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
    ])->assertOk();
    $operation = AiOperation::query()->sole();
    $firstRunToken = $operation->run_token;
    $operation->forceFill(['status' => 'failed', 'failure_code' => 'processing_failed'])->save();

    $newContent = 'New persisted publication version contains unrelated content.';
    $publication->forceFill([
        'content' => $newContent,
        'content_hash' => hash('sha256', $newContent),
        'content_version' => 2,
    ])->save();
    PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 2,
        'attempt' => 1,
        'status' => 'barred',
    ]);

    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
        'retry' => true,
    ])->assertOk();
    $retriedOperation = $operation->fresh();
    expect($retriedOperation->run_token)->not->toBe($firstRunToken)
        ->and($retriedOperation->input_snapshot['content'])->toBe($oldContent)
        ->and($retriedOperation->input_snapshot['clef_result_id'])->toBe($oldClefResult->id);

    Http::fake(['*' => Http::response([
        'choices' => [[
            'message' => ['content' => json_encode(['opportunities' => []], JSON_THROW_ON_ERROR)],
            'finish_reason' => 'stop',
        ]],
        'model' => 'deepseek-v4.1-flash-free',
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2],
    ])]);
    (new ProcessAiOperation(
        $retriedOperation->id,
        $retriedOperation->run_token,
        $retriedOperation->attempt_token,
    ))->handle(app(AiOperationService::class));

    $result = PublicationTriageResult::query()->sole();
    expect($result->content_version)->toBe(1)
        ->and($result->clef_result_id)->toBe($oldClefResult->id)
        ->and($retriedOperation->fresh()->status->value)->toBe('completed');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains(
        json_encode($request->data(), JSON_THROW_ON_ERROR),
        $oldContent,
    ));
});

it('reviews a structured opportunity with optimistic version, immutable history and idempotent replay', function () {
    $user = McpTestHelpers::user();
    $posting = publicationTriageManagementOpportunity($user, [], ['url' => null]);
    $correctedEvidence = $posting->evidence;
    $correctedEvidence[0]['block_ids'] = ['snapshot-block-2'];
    $invalidEvidence = $correctedEvidence;
    $invalidEvidence[0]['block_ids'] = ['fabricated-block'];
    JoboraServer::tool(ReviewJobPostingTool::class, [
        'id' => $posting->id,
        'expected_version' => 4,
        'corrections' => ['evidence' => $invalidEvidence],
    ])->assertHasErrors(['validation']);
    expect($posting->fresh()->opportunity_version)->toBe(4);

    $corrections = [
        'role' => 'Backend Engineer',
        'source_url' => 'https://jobs.example.test/apply',
        'evidence' => $correctedEvidence,
    ];
    $arguments = [
        'id' => $posting->id,
        'expected_version' => 4,
        'corrections' => $corrections,
    ];

    $first = JoboraServer::tool(ReviewJobPostingTool::class, $arguments)->assertOk();
    $replay = JoboraServer::tool(ReviewJobPostingTool::class, $arguments)->assertOk();

    $first->assertStructuredContent(fn ($json) => $json
        ->where('ok', true)
        ->where('data.job_posting_id', $posting->id)
        ->where('data.readiness', 'ready')
        ->where('data.opportunity_version', 5)
        ->etc());
    $revisionId = $posting->fresh()->reviewRevisions()->sole()->id;
    $replay->assertStructuredContent(fn ($json) => $json
        ->where('ok', true)
        ->where('data.review_revision_id', $revisionId)
        ->etc());

    expect($posting->fresh()->role)->toBe('Backend Engineer')
        ->and($posting->fresh()->opportunity_version)->toBe(5)
        ->and($posting->fresh()->readiness)->toBe('ready')
        ->and($posting->fresh()->review_reasons)->toBe([])
        ->and($posting->fresh()->evidence)->toBe($correctedEvidence)
        ->and($posting->items()->where('name', 'Laravel')->value('source'))->toBe(ItemSource::Ai)
        ->and($posting->reviewRevisions()->count())->toBe(1);

    JoboraServer::tool(GetJobPostingTool::class, ['id' => $posting->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.job_posting.review_history.0.actor_id', $user->id)
            ->where('data.job_posting.review_history.0.from_version', 4)
            ->where('data.job_posting.review_history.0.to_version', 5)
            ->where('data.job_posting.review_history.0.changes.role', 'Backend Engineer')
            ->where('data.job_posting.review_history.0.changes.source_url', 'https://jobs.example.test/apply')
            ->where('data.job_posting.review_history.0.changes.evidence.0.block_ids.0', 'snapshot-block-2')
            ->etc());

    expect(fn () => app(OpportunityReviewService::class)->review(
        $user,
        $posting->fresh(),
        4,
        ['company' => 'Changed Ltd'],
    ))->toThrow(OpportunityReviewConflict::class);
    JoboraServer::tool(ReviewJobPostingTool::class, [
        'id' => $posting->id,
        'expected_version' => 4,
        'corrections' => ['company' => 'Changed Ltd'],
    ])->assertHasErrors(['conflict']);
});

it('requires a reason before bypassing a barred Clef decision', function () {
    $user = McpTestHelpers::user();
    Queue::fake();
    $content = 'A public role announcement with enough persisted text.';
    $publication = Publication::factory()->for($user)->create([
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
    ]);
    PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 1,
        'attempt' => 1,
        'status' => 'barred',
    ]);

    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
        'bypass_clef' => true,
    ])->assertHasErrors(['validation']);

    Queue::assertNothingPushed();
    expect(AiOperation::query()->count())->toBe(0);
});

it('requires a new visit when persisted publication content is unavailable', function () {
    $user = McpTestHelpers::user();
    $posting = publicationTriageManagementOpportunity($user, [], [
        'content' => null,
        'content_hash' => null,
    ]);

    JoboraServer::tool(ReviewJobPostingTool::class, [
        'id' => $posting->id,
        'expected_version' => 4,
        'corrections' => ['role' => 'Backend Engineer'],
    ])->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.readiness', 'needs_review')
            ->where('data.next_action', 'new_visit_required')
            ->where('data.review_reasons.0', 'publication_content_unavailable')
            ->etc());

    expect($posting->fresh()->readiness)->toBe('needs_review')
        ->and($posting->fresh()->review_reasons)->toContain('publication_content_unavailable')
        ->and(PageCapture::query()->count())->toBe(0);
});

it('isolates structured review and review history by configured owner', function () {
    $configuredUser = McpTestHelpers::user();
    $owner = User::factory()->create();
    $posting = publicationTriageManagementOpportunity($owner);

    JoboraServer::tool(ReviewJobPostingTool::class, [
        'id' => $posting->id,
        'expected_version' => 4,
        'corrections' => ['role' => 'Backend Engineer'],
    ])->assertHasErrors(['not_found']);
    JoboraServer::tool(GetJobPostingTool::class, ['id' => $posting->id])->assertHasErrors(['not_found']);

    expect($posting->fresh()->opportunity_version)->toBe(4)
        ->and($posting->reviewRevisions()->count())->toBe(0)
        ->and($configuredUser->id)->not->toBe($owner->id);
});

it('ignores a stale triage callback after an explicit retry and human review', function () {
    $user = McpTestHelpers::user();
    Queue::fake();
    $content = 'Backend Engineer role with application instructions.';
    $publication = Publication::factory()->for($user)->create([
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
    ]);
    PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 1,
        'attempt' => 1,
        'status' => 'eligible',
    ]);
    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
    ])->assertOk();
    $operation = AiOperation::query()->sole();
    $oldRunToken = $operation->run_token;
    $oldAttemptToken = $operation->attempt_token;
    $operation->forceFill(['status' => 'failed', 'failure_code' => 'processing_failed'])->save();

    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
        'retry' => true,
    ])->assertOk();
    $posting = publicationTriageManagementOpportunity($user);
    app(OpportunityReviewService::class)->review($user, $posting, 4, ['role' => 'Human-corrected Engineer']);
    Http::fake(['*' => Http::response([
        'choices' => [[
            'message' => ['content' => json_encode(['opportunities' => []], JSON_THROW_ON_ERROR)],
            'finish_reason' => 'stop',
        ]],
        'model' => 'deepseek-v4.1-flash-free',
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1],
    ])]);

    (new ProcessAiOperation($operation->id, $oldRunToken, $oldAttemptToken))
        ->handle(app(AiOperationService::class));

    expect($posting->fresh()->role)->toBe('Human-corrected Engineer')
        ->and($posting->fresh()->opportunity_version)->toBe(5)
        ->and($posting->reviewRevisions()->count())->toBe(1)
        ->and(PublicationTriageResult::query()->count())->toBe(0)
        ->and(JobPosting::query()->count())->toBe(1);
    Http::assertNothingSent();
});
