<?php

use App\DTO\ApplicationMethodData;
use App\Enums\ApplicationMethodType;
use App\Enums\ItemSource;
use App\Jobs\ProcessAiOperation;
use App\Mcp\Servers\JoboraServer;
use App\Mcp\Tools\Captures\RequestPublicationTriageTool;
use App\Mcp\Tools\JobPostings\ReviewJobPostingTool;
use App\Mcp\Tools\System\GetAiOperationTool;
use App\Models\AiOperation;
use App\Models\PageCapture;
use App\Models\Publication;
use App\Models\PublicationClefResult;
use App\Models\PublicationObservation;
use App\Models\PublicationTriageResult;
use App\Services\AiOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Mcp\McpTestHelpers;

uses(RefreshDatabase::class);

it('requires quoted support for asserted fields and audits snapshot-backed provenance corrections', function () {
    $user = McpTestHelpers::user();
    Queue::fake();

    $content = 'Backend Engineer at AcmeLabs. Apply through LinkedIn. Contact recruiting@example.test. Apply at https://jobs.example.test/apply. A competitor, Invented Inc, posted a separate notice.';
    $publication = Publication::factory()->for($user)->create([
        'url' => null,
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
    ]);
    $capture = PageCapture::factory()->for($user)->create();
    PublicationObservation::factory()->create([
        'page_capture_id' => $capture->id,
        'publication_id' => $publication->id,
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
        'evidence_block_ids' => ['snapshot-main', 'snapshot-apply'],
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
    Http::fake(['*' => Http::response([
        'choices' => [[
            'message' => ['content' => json_encode([
                'opportunities' => [[
                    'role' => 'Backend Engineer',
                    'company' => 'Invented Inc',
                    'description' => null,
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
                    'application_methods' => [
                        [
                            'type' => ApplicationMethodType::Email->value,
                            'platform' => null,
                            'url' => null,
                            'email' => 'recruiting@example.test',
                            'phone' => null,
                            'instructions' => null,
                        ],
                        [
                            'type' => ApplicationMethodType::LinkedinApply->value,
                            'platform' => ' LinkedIn ',
                            'url' => null,
                            'email' => null,
                            'phone' => null,
                            'instructions' => null,
                        ],
                    ],
                    'evidence' => [
                        [
                            'quote' => 'Backend Engineer at AcmeLabs. Apply through LinkedIn. Contact recruiting@example.test.',
                            'block_ids' => ['snapshot-main'],
                            'fields' => ['role', 'application_methods'],
                        ],
                        [
                            'quote' => 'A competitor, Invented Inc, posted a separate notice.',
                            'block_ids' => ['snapshot-main'],
                            'fields' => ['role'],
                        ],
                    ],
                ]],
            ], JSON_THROW_ON_ERROR)],
            'finish_reason' => 'stop',
        ]],
        'model' => 'deepseek-v4.1-flash-free',
        'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 12],
    ])]);

    (new ProcessAiOperation($operation->id, $operation->run_token, $operation->attempt_token))
        ->handle(app(AiOperationService::class));

    $posting = $user->jobPostings()->sole();
    $result = PublicationTriageResult::query()->sole();

    JoboraServer::tool(GetAiOperationTool::class, ['id' => $operation->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.publication_triage_results.0.output.opportunities.0.company', 'Invented Inc')
            ->etc());

    expect($posting->readiness)->toBe('needs_review')
        ->and($posting->review_reasons)->toContain('unsupported_field_evidence')
        ->and($posting->review_reasons)->not->toContain('missing_publication_url')
        ->and($posting->applicationMethods)->toHaveCount(2)
        ->and($posting->applicationMethods->first()->dedup_key)
        ->toBe(ApplicationMethodData::fromArray([
            'type' => ApplicationMethodType::Email,
            'email' => 'recruiting@example.test',
        ])->dedupKey())
        ->and($posting->applicationMethods->last()->type)->toBe(ApplicationMethodType::LinkedinApply)
        ->and($posting->applicationMethods->last()->source)->toBe(ItemSource::Ai);

    $originalOutput = $result->output;
    $correctedEvidence = [[
        'quote' => $content,
        'block_ids' => ['snapshot-main', 'snapshot-apply'],
        'fields' => ['role', 'company', 'application_methods'],
    ]];

    $reviewArguments = [
        'id' => $posting->id,
        'expected_version' => 1,
    ];

    JoboraServer::tool(ReviewJobPostingTool::class, [
        ...$reviewArguments,
        'corrections' => [
            'evidence' => [[
                'quote' => 'This quote was never persisted.',
                'block_ids' => ['snapshot-main'],
                'fields' => ['company'],
            ]],
        ],
    ])->assertHasErrors(['validation']);

    JoboraServer::tool(ReviewJobPostingTool::class, [
        ...$reviewArguments,
        'corrections' => [
            'evidence' => [[
                'quote' => $content,
                'block_ids' => ['fabricated-block'],
                'fields' => ['company'],
            ]],
        ],
    ])->assertHasErrors(['validation']);

    JoboraServer::tool(ReviewJobPostingTool::class, [
        ...$reviewArguments,
        'corrections' => ['source_url' => 'https://fake.example.test/posting'],
    ])->assertHasErrors(['validation']);

    expect($posting->fresh()->opportunity_version)->toBe(1);

    JoboraServer::tool(ReviewJobPostingTool::class, [
        ...$reviewArguments,
        'corrections' => [
            'company' => 'AcmeLabs',
            'source_url' => 'https://jobs.example.test/apply',
            'evidence' => $correctedEvidence,
            'application_methods' => [
                [
                    'type' => ApplicationMethodType::Email->value,
                    'email' => 'RECRUITING@example.test',
                ],
                [
                    'type' => ApplicationMethodType::LinkedinApply->value,
                    'platform' => 'linkedin',
                ],
            ],
        ],
    ])->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.readiness', 'ready')
            ->where('data.opportunity_version', 2)
            ->etc());

    $revision = $posting->fresh()->reviewRevisions()->sole();

    expect($posting->fresh()->readiness)->toBe('ready')
        ->and($posting->fresh()->source_url)->toBe('https://jobs.example.test/apply')
        ->and($posting->fresh()->evidence)->toBe($correctedEvidence)
        ->and($posting->fresh()->applicationMethods->pluck('source')->every(fn (ItemSource $source): bool => $source === ItemSource::Ai))->toBeTrue()
        ->and($revision->changes)->toMatchArray([
            'company' => 'AcmeLabs',
            'source_url' => 'https://jobs.example.test/apply',
            'evidence' => $correctedEvidence,
        ])
        ->and($revision->before_snapshot['source_url'])->toBeNull()
        ->and($revision->after_snapshot['source_url'])->toBe('https://jobs.example.test/apply')
        ->and($result->fresh()->output)->toBe($originalOutput);
});

it('revalidates corrected evidence against publication snapshot on salary-only follow-up', function () {
    $user = McpTestHelpers::user();
    Queue::fake();
    $content = 'Backend Engineer at AcmeLabs. Salary from 100000 to 150000 USD annually. Apply at https://jobs.example.test/apply.';
    $publication = Publication::factory()->for($user)->create([
        'url' => null,
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
    ]);
    $capture = PageCapture::factory()->for($user)->create();
    PublicationObservation::factory()->create([
        'page_capture_id' => $capture->id,
        'publication_id' => $publication->id,
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
        'evidence_block_ids' => ['snapshot-main', 'snapshot-salary'],
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
    Http::fake(['*' => Http::response([
        'choices' => [[
            'message' => ['content' => json_encode([
                'opportunities' => [[
                    'role' => 'Backend Engineer',
                    'company' => 'AcmeLabs',
                    'description' => null,
                    'location' => null,
                    'work_mode' => null,
                    'employment_type' => null,
                    'salary_min' => 150000,
                    'salary_max' => 100000,
                    'salary_currency' => null,
                    'salary_period' => null,
                    'seniority' => null,
                    'requirements' => [],
                    'technologies' => [],
                    'application_methods' => [],
                    'evidence' => [
                        [
                            'quote' => 'Backend Engineer at AcmeLabs.',
                            'block_ids' => ['snapshot-main'],
                            'fields' => ['role', 'company'],
                        ],
                        [
                            'quote' => 'Salary from 100000 to 150000 USD annually.',
                            'block_ids' => ['snapshot-main'],
                            'fields' => ['salary_min', 'salary_max'],
                        ],
                    ],
                ]],
            ], JSON_THROW_ON_ERROR)],
            'finish_reason' => 'stop',
        ]],
        'model' => 'deepseek-v4.1-flash-free',
        'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 12],
    ])]);

    (new ProcessAiOperation($operation->id, $operation->run_token, $operation->attempt_token))
        ->handle(app(AiOperationService::class));

    $posting = $user->jobPostings()->sole();
    expect($posting->readiness)->toBe('needs_review')
        ->and($posting->review_reasons)->toContain('salary_range_inconsistent');

    JoboraServer::tool(ReviewJobPostingTool::class, [
        'id' => $posting->id,
        'expected_version' => 1,
        'corrections' => [
            'evidence' => [[
                'quote' => $content,
                'block_ids' => ['snapshot-main', 'snapshot-salary'],
                'fields' => ['role', 'company', 'salary_min', 'salary_max'],
            ]],
        ],
    ])->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.readiness', 'needs_review')
            ->where('data.opportunity_version', 2)
            ->etc());

    expect($posting->fresh()->review_reasons)
        ->toBe(['salary_range_inconsistent']);

    JoboraServer::tool(ReviewJobPostingTool::class, [
        'id' => $posting->id,
        'expected_version' => 2,
        'corrections' => ['salary_max' => 150000],
    ])->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.readiness', 'ready')
            ->where('data.opportunity_version', 3)
            ->etc());

    $revisions = $posting->fresh()->reviewRevisions()->orderBy('to_version')->get();
    expect($posting->fresh()->salary_min)->toBe('150000.00')
        ->and($posting->fresh()->salary_max)->toBe('150000.00')
        ->and($posting->fresh()->readiness)->toBe('ready')
        ->and($posting->fresh()->review_reasons)->toBe([])
        ->and($revisions)->toHaveCount(2)
        ->and($revisions[1]->changes)->toBe(['salary_max' => 150000]);
});
