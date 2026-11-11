<?php

use App\Enums\JobPostingStatus;
use App\Filament\Resources\JobPostings\Pages\ViewJobPosting;
use App\Models\AiOperation;
use App\Models\OpportunitySource;
use App\Models\Publication;
use App\Models\PublicationTriageResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
