<?php

use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Enums\JobPostingStatus;
use App\Filament\Resources\JobPostings\Pages\ViewJobPosting;
use App\Jobs\ProcessAiOperation;
use App\Models\AiOperation;
use App\Models\JobPosting;
use App\Models\OpportunitySource;
use App\Models\Publication;
use App\Models\PublicationTriageResult;
use App\Models\User;
use App\Services\AiOperationService;
use App\Services\OpportunityReviewConflict;
use App\Services\OpportunityReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function publicationReviewPosting(User $user): array
{
    $publication = Publication::factory()->for($user)->create([
        'content' => 'Backend Engineer at Example Ltd.',
        'content_version' => 1,
    ]);
    $posting = $user->jobPostings()->create([
        'description' => 'Build backend systems.',
        'source_url' => $publication->url,
        'collected_at' => now(),
        'status' => JobPostingStatus::Pending,
        'role' => null,
        'company' => 'Example Ltd',
        'readiness' => 'needs_review',
        'opportunity_version' => 1,
        'review_reasons' => ['missing_role'],
    ]);
    OpportunitySource::query()->create([
        'job_posting_id' => $posting->id,
        'publication_id' => $publication->id,
        'is_legacy' => false,
        'publication_content_version' => 1,
    ]);

    return [$posting, $publication];
}

it('shows needs-review triage status in Portuguese', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    [$posting, $publication] = publicationReviewPosting($user);
    $operation = AiOperation::query()->create([
        'user_id' => $user->id,
        'operation_key' => 'publication-triage-test',
        'operation_type' => 'publication_triage',
        'target_type' => 'publication',
        'target_id' => $publication->id,
        'run_token' => (string) Str::uuid(),
        'status' => 'completed',
        'queued_at' => now(),
        'completed_at' => now(),
        'expires_at' => now()->addDay(),
        'input_fingerprint' => hash('sha256', 'triage'),
    ]);
    PublicationTriageResult::query()->create([
        'ai_operation_id' => $operation->id,
        'publication_id' => $publication->id,
        'content_version' => 1,
        'run_token' => $operation->run_token,
        'attempt_token' => (string) Str::uuid(),
        'attempt' => 1,
        'status' => 'needs_review',
        'provider' => 'test',
        'model' => 'test',
        'schema_version' => '1',
        'input_fingerprint' => hash('sha256', 'triage-result'),
    ]);

    Livewire::test(ViewJobPosting::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertSee('Aguardando revisão')
        ->assertDontSee('needs_review');
});

it('refreshes correction data after a version conflict so the user can resubmit', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    [$posting] = publicationReviewPosting($user);
    $page = Livewire::test(ViewJobPosting::class, ['record' => $posting->id])
        ->mountAction('correctOpportunity')
        ->assertSet('mountedActions.0.data.expected_version', 1);

    app()->instance(OpportunityReviewService::class, new class($posting->id)
    {
        public function __construct(private int $postingId) {}

        public function review(
            User $actor,
            JobPosting $posting,
            int $expectedVersion,
            array $corrections,
            ?string $idempotencyKey = null,
        ): never {
            JobPosting::query()->whereKey($this->postingId)->update([
                'opportunity_version' => 2,
                'company' => 'Updated Example Ltd',
            ]);

            throw new OpportunityReviewConflict('A oportunidade mudou. Reabra a correção.');
        }
    });

    $page->setActionData(['role' => 'Backend Engineer'])
        ->callMountedAction()
        ->assertNotified('A oportunidade mudou')
        ->assertSet('record.opportunity_version', 2)
        ->assertSet('record.company', 'Updated Example Ltd')
        ->unmountAction();

    app()->forgetInstance(OpportunityReviewService::class);

    $page->mountAction('correctOpportunity')
        ->assertSet('mountedActions.0.data.expected_version', 2)
        ->assertSet('mountedActions.0.data.company', 'Updated Example Ltd')
        ->setActionData(['role' => 'Backend Engineer'])
        ->callMountedAction()
        ->assertNotified();

    expect($posting->refresh()->opportunity_version)->toBe(3);
});

it('reports completed triage without claiming a new request', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    [$posting, $publication] = publicationReviewPosting($user);
    Queue::fake();
    $content = 'Backend Engineer at Example Ltd.';
    $publication->forceFill([
        'content' => $content,
        'content_hash' => hash('sha256', $content),
    ])->save();
    AiOperation::query()->create([
        'user_id' => $user->id,
        'operation_key' => "user:{$user->id}:publication_triage:publication:{$publication->id}:version:1",
        'operation_type' => AiOperationType::PublicationTriage,
        'target_type' => 'publication',
        'target_id' => $publication->id,
        'run_token' => (string) Str::uuid(),
        'status' => AiOperationStatus::Completed,
        'queued_at' => now(),
        'completed_at' => now(),
        'expires_at' => now()->addDay(),
        'input_fingerprint' => hash('sha256', 'triage'),
    ]);

    Livewire::test(ViewJobPosting::class, ['record' => $posting->id])
        ->callAction('reevaluatePublication')
        ->assertNotified('Triagem já concluída')
        ->assertNotNotified('Reavaliação solicitada');

    Queue::assertNothingPushed();
});

it('reports queued triage without requesting it again', function () {
    config(['triage.prefilter.enabled' => false]);
    Queue::fake();
    $user = User::factory()->create();
    $this->actingAs($user);
    [$posting, $publication] = publicationReviewPosting($user);
    $content = 'Backend Engineer at Example Ltd.';
    $publication->forceFill([
        'content' => $content,
        'content_hash' => hash('sha256', $content),
    ])->save();

    app(AiOperationService::class)->enqueuePublicationTriage($user, $publication->id, 1);

    Livewire::test(ViewJobPosting::class, ['record' => $posting->id])
        ->callAction('reevaluatePublication')
        ->assertNotified('Triagem na fila')
        ->assertNotNotified('Reavaliação solicitada');

    Queue::assertPushed(ProcessAiOperation::class, 1);
});
