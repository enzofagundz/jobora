<?php

use App\Enums\JobPostingStatus;
use App\Filament\Resources\JobPostings\Pages\ViewJobPosting;
use App\Models\JobPosting;
use App\Models\OpportunityReviewRevision;
use App\Models\OpportunitySource;
use App\Models\PageCapture;
use App\Models\Publication;
use App\Models\PublicationObservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @param  list<array{quote: string, block_ids: list<string>, fields?: list<string>}>  $evidence
 */
function provenanceOpportunity(User $user, array $evidence, array $overrides = []): JobPosting
{
    $content = 'Backend Engineer needed at AcmeLabs. Apply at https://jobs.example.test/apply.';
    $publication = Publication::factory()->for($user)->create([
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
        'evidence_block_ids' => ['block-1', 'block-2'],
    ]);

    $posting = $user->jobPostings()->create([
        'description' => 'Build backend systems.',
        'source_url' => $publication->url,
        'collected_at' => now(),
        'status' => JobPostingStatus::Pending,
        'role' => null,
        'company' => 'Invented Inc',
        'readiness' => 'needs_review',
        'opportunity_version' => 7,
        'requirements' => [],
        'technologies' => [],
        'evidence' => $evidence,
        'review_reasons' => ['missing_role'],
        ...$overrides,
    ]);

    OpportunitySource::query()->create([
        'job_posting_id' => $posting->id,
        'publication_id' => $publication->id,
        'is_legacy' => false,
        'publication_content_version' => 1,
        'evidence_block_ids' => ['block-1', 'block-2'],
        'evidence' => $evidence,
    ]);

    return $posting;
}

it('preenche e salva correção de proveniência com autoria e histórico', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $evidence = [[
        'quote' => 'Backend Engineer needed at AcmeLabs.',
        'block_ids' => ['block-1'],
        'fields' => ['role'],
    ]];
    $posting = provenanceOpportunity($user, $evidence);

    Livewire::test(ViewJobPosting::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertSee('Evidências')
        ->mountAction('correctOpportunity')
        ->assertSet('mountedActions.0.data.source_url', $posting->source_url)
        ->assertSet('mountedActions.0.data.evidence', fn (array $state): bool => count($state) === 1
            && array_values($state)[0]['quote'] === $evidence[0]['quote']
            && array_values($state)[0]['block_ids'] === ['block-1']
            && array_values($state)[0]['fields'] === ['role']);

    $correctedEvidence = [[
        'quote' => 'Backend Engineer needed at AcmeLabs.',
        'block_ids' => ['block-1', 'block-2'],
        'fields' => ['role', 'company', 'description'],
    ]];

    Livewire::test(ViewJobPosting::class, ['record' => $posting->id])
        ->callAction('correctOpportunity', [
            'expected_version' => 7,
            'role' => 'Backend Engineer',
            'company' => 'AcmeLabs',
            'description' => 'Backend Engineer needed at AcmeLabs.',
            'source_url' => 'https://jobs.example.test/apply',
            'evidence' => $correctedEvidence,
        ])
        ->assertNotified('Oportunidade pronta');

    $posting->refresh();
    $revision = OpportunityReviewRevision::query()->sole();

    expect($posting->readiness)->toBe('ready')
        ->and($posting->source_url)->toBe('https://jobs.example.test/apply')
        ->and($posting->evidence)->toBe($correctedEvidence)
        ->and($revision->actor_id)->toBe($user->id)
        ->and($revision->changes)->toMatchArray([
            'source_url' => 'https://jobs.example.test/apply',
            'evidence' => $correctedEvidence,
        ])
        ->and($revision->before_snapshot['source_url'])->not->toBe('https://jobs.example.test/apply')
        ->and($revision->after_snapshot['source_url'])->toBe('https://jobs.example.test/apply');
});

it('recusa evidência que não existe no snapshot sem alterar a oportunidade', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $evidence = [[
        'quote' => 'Backend Engineer needed at AcmeLabs.',
        'block_ids' => ['block-1'],
        'fields' => ['role'],
    ]];
    $posting = provenanceOpportunity($user, $evidence);

    Livewire::test(ViewJobPosting::class, ['record' => $posting->id])
        ->callAction('correctOpportunity', [
            'expected_version' => 7,
            'role' => 'Backend Engineer',
            'evidence' => [[
                'quote' => 'Citação que nunca esteve na publicação.',
                'block_ids' => ['block-1'],
                'fields' => ['role'],
            ]],
        ])
        ->assertNotified('Correção não aceita');

    expect(OpportunityReviewRevision::query()->count())->toBe(0)
        ->and($posting->refresh()->opportunity_version)->toBe(7)
        ->and($posting->evidence)->toBe($evidence);
});

it('não reenvia evidência inalterada sem campos e mantém a proveniência antiga', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $legacyEvidence = [[
        'quote' => 'Backend Engineer needed at AcmeLabs.',
        'block_ids' => ['block-1'],
    ]];
    $posting = provenanceOpportunity($user, $legacyEvidence);

    Livewire::test(ViewJobPosting::class, ['record' => $posting->id])
        ->callAction('correctOpportunity', [
            'expected_version' => 7,
            'role' => 'Backend Engineer',
        ])
        ->assertNotified('Correção salva');

    expect($posting->refresh()->evidence)->toBe($legacyEvidence)
        ->and(OpportunityReviewRevision::query()->sole()->changes)->not->toHaveKey('evidence')
        ->and(OpportunityReviewRevision::query()->sole()->changes)->not->toHaveKey('source_url');
});

it('não bloqueia oportunidade por link fora da publicação quando ele não é corrigido', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $posting = provenanceOpportunity($user, [[
        'quote' => 'Backend Engineer needed at AcmeLabs.',
        'block_ids' => ['block-1'],
        'fields' => ['role'],
    ]], [
        'company' => null,
        'description' => null,
        'source_url' => 'https://fora-da-publicacao.example.test/vaga',
    ]);

    Livewire::test(ViewJobPosting::class, ['record' => $posting->id])
        ->callAction('correctOpportunity', [
            'expected_version' => 7,
            'role' => 'Backend Engineer',
        ])
        ->assertNotified('Oportunidade pronta');

    expect($posting->refresh()->readiness)->toBe('ready')
        ->and($posting->source_url)->toBe('https://fora-da-publicacao.example.test/vaga');
});
