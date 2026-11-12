<?php

use App\DTO\ApplicationMethodData;
use App\Enums\ConceptCategory;
use App\Enums\ItemSource;
use App\Models\JobPosting;
use App\Models\OpportunityReviewRevision;
use App\Models\OpportunitySource;
use App\Models\Publication;
use App\Models\PublicationClefResult;
use App\Models\PublicationObservation;
use App\Models\PublicationTriageResult;
use App\Models\User;
use App\Services\AiOperationService;
use App\Services\OpportunityReviewConflict;
use App\Services\OpportunityReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('trata chave de revisão vazia como ausente e mantém replay e conflito por versão', function (string $key) {
    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user)->create([
        'readiness' => 'needs_review',
        'opportunity_version' => 1,
    ]);
    $reviews = app(OpportunityReviewService::class);

    $first = $reviews->review($user, $posting, 1, ['company' => 'Primeira'], $key);
    $replay = $reviews->review($user, $posting, 1, ['company' => 'Primeira'], $key);
    $second = $reviews->review($user, $posting, 2, ['company' => 'Segunda'], $key);

    expect($replay->id)->toBe($first->id)
        ->and($second->id)->not->toBe($first->id)
        ->and($posting->refresh()->company)->toBe('Segunda')
        ->and($posting->opportunity_version)->toBe(3)
        ->and(OpportunityReviewRevision::query()->count())->toBe(2);

    expect(fn () => $reviews->review($user, $posting, 1, ['company' => 'Obsoleta'], $key))
        ->toThrow(OpportunityReviewConflict::class, 'Opportunity version conflict');

    expect($posting->refresh()->company)->toBe('Segunda')
        ->and(OpportunityReviewRevision::query()->count())->toBe(2);
})->with(['vazia' => '', 'espaços' => '   ']);

it('deduplica itens após normalização e preserva origem existente', function (string $field, ConceptCategory $category, array $names, string $normalized) {
    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user)->create([
        'readiness' => 'needs_review',
        'opportunity_version' => 1,
    ]);
    $posting->items()->create([
        'category' => $category,
        'name' => $normalized,
        'source' => ItemSource::Ai,
    ]);

    $revision = app(OpportunityReviewService::class)->review($user, $posting, 1, [$field => $names]);

    $item = $posting->items()->where('category', $category->value)->sole();
    expect($item->name)->toBe($normalized)
        ->and($item->source)->toBe(ItemSource::Ai)
        ->and($revision->to_version)->toBe(2)
        ->and(OpportunityReviewRevision::query()->count())->toBe(1);
})->with([
    'requisitos' => ['requirements', ConceptCategory::Requirement, ['PHP', ' php '], 'php'],
    'aliases de tecnologias' => ['technologies', ConceptCategory::Skill, ['js', 'javascript'], 'JavaScript'],
]);

it('deduplica formas de candidatura equivalentes e preserva origem', function (array $first, array $duplicate) {
    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user)->create([
        'readiness' => 'needs_review',
        'opportunity_version' => 1,
    ]);
    $original = ApplicationMethodData::fromArray($first);
    $posting->applicationMethods()->create([
        ...$original->toArray(),
        'dedup_key' => $original->dedupKey(),
        'source' => ItemSource::Ai,
    ]);

    $revision = app(OpportunityReviewService::class)->review($user, $posting, 1, [
        'application_methods' => [$first, $duplicate],
    ]);

    $method = $posting->applicationMethods()->sole();
    expect($method->dedup_key)->toBe($original->dedupKey())
        ->and($method->source)->toBe(ItemSource::Ai)
        ->and($method->url)->toBe($original->url)
        ->and($revision->to_version)->toBe(2)
        ->and(OpportunityReviewRevision::query()->count())->toBe(1);
})->with([
    'email' => [['type' => 'email', 'email' => 'a@example.com'], ['type' => 'email', 'email' => 'A@Example.com']],
    'url' => [['type' => 'form', 'url' => 'https://example.com/apply'], ['type' => 'form', 'url' => 'https://example.com/apply/']],
]);

it('usa snapshot da triagem exata após recaptura e rejeita blocos posteriores sem escrita', function (bool $correctEvidence) {
    Queue::fake();
    $user = User::factory()->create();
    $content = 'Backend Engineer';
    $publication = Publication::factory()->for($user)->create([
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
    ]);
    PublicationObservation::factory()->create([
        'publication_id' => $publication->id,
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
        'evidence_block_ids' => ['triage-block'],
        'observed_at' => now()->subMinute(),
    ]);
    PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 1,
        'attempt' => 1,
        'status' => 'eligible',
    ]);
    $operation = app(AiOperationService::class)->enqueuePublicationTriage($user, $publication->id, 1);
    $triage = PublicationTriageResult::query()->create([
        'ai_operation_id' => $operation->id,
        'publication_id' => $publication->id,
        'content_version' => 1,
        'run_token' => (string) Str::uuid(),
        'attempt_token' => (string) Str::uuid(),
        'attempt' => 1,
        'status' => 'needs_review',
        'provider' => 'opencode_go',
        'model' => 'test',
        'schema_version' => 'v1',
        'input_fingerprint' => $operation->input_fingerprint,
        'output' => ['original' => true],
    ]);
    $evidence = [['quote' => $content, 'block_ids' => ['triage-block'], 'fields' => ['role']]];
    $posting = JobPosting::factory()->for($user)->create([
        'readiness' => 'needs_review',
        'opportunity_version' => 1,
        'role' => null,
        'company' => null,
        'description' => null,
        'source_url' => $publication->url,
        'evidence' => $evidence,
        'review_reasons' => ['missing_role'],
    ]);
    $source = OpportunitySource::query()->create([
        'job_posting_id' => $posting->id,
        'publication_id' => $publication->id,
        'is_legacy' => false,
        'publication_content_version' => 1,
        'publication_triage_result_id' => $triage->id,
        'evidence_block_ids' => ['triage-block'],
        'evidence' => $evidence,
    ]);
    PublicationObservation::factory()->create([
        'publication_id' => $publication->id,
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
        'evidence_block_ids' => ['recapture-block'],
        'observed_at' => now(),
    ]);
    $reviews = app(OpportunityReviewService::class);
    $laterEvidence = [['quote' => $content, 'block_ids' => ['recapture-block'], 'fields' => ['role']]];

    expect(fn () => $reviews->review($user, $posting, 1, ['evidence' => $laterEvidence]))
        ->toThrow(ValidationException::class);
    expect($posting->refresh()->opportunity_version)->toBe(1)
        ->and(OpportunityReviewRevision::query()->count())->toBe(0);

    $corrections = ['role' => $content];
    if ($correctEvidence) {
        $corrections['evidence'] = $evidence;
    }
    $reviews->review($user, $posting, 1, $corrections);

    expect($posting->refresh()->readiness)->toBe('ready')
        ->and($posting->review_reasons)->toBe([])
        ->and($source->refresh()->evidence)->toBe($evidence)
        ->and($triage->refresh()->output)->toBe(['original' => true]);

    $snapshot = $operation->input_snapshot;
    $snapshot['content_hash'] = str_repeat('0', 64);
    $operation->update(['input_snapshot' => $snapshot]);
    $posting->update(['readiness' => 'needs_review']);

    expect(fn () => $reviews->review($user, $posting, 2, ['evidence' => $evidence]))
        ->toThrow(ValidationException::class);
    expect($posting->refresh()->opportunity_version)->toBe(2)
        ->and(OpportunityReviewRevision::query()->count())->toBe(1);
})->with(['reavaliação' => false, 'correção de evidência' => true]);

it('aceita blocos da observação validada junto aos da origem sem triagem vinculada', function (string $blockId) {
    $user = User::factory()->create();
    $content = 'Backend Engineer';
    $publication = Publication::factory()->for($user)->create([
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
    ]);
    PublicationObservation::factory()->create([
        'publication_id' => $publication->id,
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
        'evidence_block_ids' => ['snapshot-block-2'],
    ]);
    $originalEvidence = [['quote' => $content, 'block_ids' => ['block-1'], 'fields' => ['role']]];
    $posting = JobPosting::factory()->for($user)->create([
        'readiness' => 'needs_review',
        'opportunity_version' => 1,
        'role' => null,
        'company' => null,
        'description' => null,
        'source_url' => $publication->url,
        'evidence' => $originalEvidence,
        'review_reasons' => ['missing_role'],
    ]);
    $source = OpportunitySource::query()->create([
        'job_posting_id' => $posting->id,
        'publication_id' => $publication->id,
        'is_legacy' => false,
        'publication_content_version' => 1,
        'evidence_block_ids' => ['block-1'],
        'evidence' => $originalEvidence,
    ]);
    $evidence = [['quote' => $content, 'block_ids' => [$blockId], 'fields' => ['role']]];

    app(OpportunityReviewService::class)->review($user, $posting, 1, [
        'role' => $content,
        'evidence' => $evidence,
    ]);

    expect($posting->refresh()->readiness)->toBe('ready')
        ->and($posting->evidence)->toBe($evidence)
        ->and($source->refresh()->evidence)->toBe($originalEvidence);
})->with(['origem' => 'block-1', 'observação' => 'snapshot-block-2']);
