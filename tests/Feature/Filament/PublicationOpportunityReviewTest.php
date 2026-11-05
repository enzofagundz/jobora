<?php

use App\Enums\ConceptCategory;
use App\Enums\ItemSource;
use App\Enums\JobPostingStatus;
use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Filament\Resources\JobPostings\Pages\ListJobPostings;
use App\Filament\Resources\JobPostings\Pages\ViewJobPosting;
use App\Models\AiOperation;
use App\Models\JobPosting;
use App\Models\JobPostingItem;
use App\Models\OpportunityReviewRevision;
use App\Models\OpportunitySource;
use App\Models\PageCapture;
use App\Models\Publication;
use App\Models\PublicationClefResult;
use App\Models\PublicationObservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const REVIEW_PUBLICATION_CONTENT = 'Backend Engineer needed at Example Ltd. Build backend systems. Requirements: PHP 8.4. Technologies: Laravel. Apply at https://jobs.example.test/apply.';

function structuredOpportunity(User $user, array $overrides = [], array $publicationOverrides = []): JobPosting
{
    $publication = Publication::factory()->for($user)->create([
        'content' => REVIEW_PUBLICATION_CONTENT,
        'content_hash' => hash('sha256', REVIEW_PUBLICATION_CONTENT),
        'content_version' => 1,
        ...$publicationOverrides,
    ]);
    $capture = PageCapture::factory()->for($user)->create();
    PublicationObservation::factory()->create([
        'page_capture_id' => $capture->id,
        'publication_id' => $publication->id,
        'content' => REVIEW_PUBLICATION_CONTENT,
        'content_hash' => hash('sha256', REVIEW_PUBLICATION_CONTENT),
        'content_version' => 1,
        'evidence_block_ids' => ['block-1'],
    ]);

    $posting = $user->jobPostings()->create([
        'description' => 'Build backend systems.',
        'source_url' => $publication->url,
        'collected_at' => now(),
        'status' => JobPostingStatus::Pending,
        'role' => null,
        'company' => 'Example Ltd',
        'readiness' => 'needs_review',
        'opportunity_version' => 42,
        'requirements' => ['PHP 8.4'],
        'technologies' => ['Laravel'],
        'evidence' => [[
            'quote' => REVIEW_PUBLICATION_CONTENT,
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
            'quote' => REVIEW_PUBLICATION_CONTENT,
            'block_ids' => ['block-1'],
            'fields' => ['role', 'company', 'description', 'requirements', 'technologies'],
        ]],
    ]);

    return $posting;
}

it('corrige oportunidade da página, expõe estado e histórico e é idempotente no replay', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $posting = structuredOpportunity($user);

    Livewire::test(ViewJobPosting::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertActionVisible('correctOpportunity')
        ->assertActionVisible('reevaluatePublication')
        ->assertSee('Oportunidade estruturada')
        ->assertSee('Aguardando revisão')
        ->assertSee('Versão')
        ->assertSee('Cargo ausente');

    $pendingCorrection = [
        'expected_version' => 42,
        'company' => 'Example Ltd',
    ];

    Livewire::test(ViewJobPosting::class, ['record' => $posting->id])
        ->callAction('correctOpportunity', $pendingCorrection)
        ->assertNotified('Correção salva');

    $posting->refresh();

    expect($posting->readiness)->toBe('needs_review')
        ->and($posting->opportunity_version)->toBe(43)
        ->and(OpportunityReviewRevision::query()->where('job_posting_id', $posting->id)->count())->toBe(1);

    Livewire::test(ViewJobPosting::class, ['record' => $posting->id])
        ->callAction('correctOpportunity', $pendingCorrection)
        ->assertNotified('Correção salva');

    expect(OpportunityReviewRevision::query()->where('job_posting_id', $posting->id)->count())->toBe(1)
        ->and($posting->refresh()->opportunity_version)->toBe(43);

    Livewire::test(ViewJobPosting::class, ['record' => $posting->id])
        ->callAction('correctOpportunity', [
            'expected_version' => 43,
            'role' => 'Backend Engineer',
        ])
        ->assertNotified('Oportunidade pronta');

    $posting->refresh();

    expect($posting->readiness)->toBe('ready')
        ->and($posting->opportunity_version)->toBe(44)
        ->and($posting->review_reasons)->toBe([])
        ->and(OpportunityReviewRevision::query()->where('job_posting_id', $posting->id)->count())->toBe(2);

    Livewire::test(ViewJobPosting::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertActionHidden('correctOpportunity')
        ->assertSee('Pronta')
        ->assertSee('Versão')
        ->assertSee('Backend Engineer')
        ->assertSee('42 → 43')
        ->assertSee('43 → 44');
});

it('recusa correção com versão obsoleta sem alterar a oportunidade', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $posting = structuredOpportunity($user);

    $page = Livewire::test(ViewJobPosting::class, ['record' => $posting->id]);

    $posting->forceFill(['opportunity_version' => 44])->save();

    $page->callAction('correctOpportunity', [
        'expected_version' => 42,
        'role' => 'Backend Engineer',
    ])->assertNotified();

    $posting->refresh();

    expect($posting->opportunity_version)->toBe(44)
        ->and($posting->readiness)->toBe('needs_review')
        ->and(OpportunityReviewRevision::query()->count())->toBe(0);
});

it('isola a oportunidade estruturada entre contas', function () {
    $owner = User::factory()->create();
    $posting = structuredOpportunity($owner);

    $this->actingAs(User::factory()->create())
        ->get(JobPostingResource::getUrl('view', ['record' => $posting]))
        ->assertNotFound();
});

it('exige motivo no bypass da decisão Clef antes de solicitar reavaliação', function () {
    Queue::fake();
    $user = User::factory()->create();
    $this->actingAs($user);
    $posting = structuredOpportunity($user);

    PublicationClefResult::query()->create([
        'publication_id' => $posting->sources()->first()->publication_id,
        'content_version' => 1,
        'attempt' => 1,
        'status' => 'barred',
        'classified_at' => now(),
    ]);

    Livewire::test(ViewJobPosting::class, ['record' => $posting->id])
        ->callAction('reevaluatePublication', [
            'retry' => false,
            'bypass_clef' => true,
            'reason' => null,
        ])
        ->assertHasFormErrors(['reason']);

    expect(AiOperation::query()->count())->toBe(0);

    Livewire::test(ViewJobPosting::class, ['record' => $posting->id])
        ->callAction('reevaluatePublication', [
            'retry' => false,
            'bypass_clef' => true,
            'reason' => 'Publicação é uma vaga real apesar da decisão Clef.',
        ])
        ->assertNotified();

    $operation = AiOperation::query()->sole();

    expect($operation->input_snapshot['clef_bypassed'])->toBeTrue()
        ->and($operation->input_snapshot['clef_bypass_reason'])->toBe('Publicação é uma vaga real apesar da decisão Clef.');
});

it('esconde as ações V1 e oferece revisão própria para oportunidade estruturada', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $posting = structuredOpportunity($user);

    Livewire::test(ListJobPostings::class)
        ->assertTableActionHidden('review', $posting)
        ->assertTableActionVisible('reviewOpportunity', $posting);
});
