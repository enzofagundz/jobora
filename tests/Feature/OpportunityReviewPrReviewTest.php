<?php

use App\Models\JobPosting;
use App\Models\OpportunityReviewRevision;
use App\Models\User;
use App\Services\OpportunityReviewConflict;
use App\Services\OpportunityReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
