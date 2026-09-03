<?php

use App\Enums\ItemSource;
use App\Enums\JobPostingStatus;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\JobReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('confirm keeps free text intact and unchanged items as ai', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $posting = JobPosting::factory()->for($user, 'user')->create([
        'status' => JobPostingStatus::NeedsReview,
        'suggested_data' => [
            'role' => 'Dev',
            'company' => null,
            'items' => [
                ['category' => 'skill', 'name' => 'Kafka'],
                ['category' => 'tool', 'name' => 'Docker'],
                ['category' => 'requirement', 'name' => 'disponibilidade para viagens'],
                ['category' => 'experience', 'name' => '3+ anos'],
            ],
            'application_methods' => [],
        ],
    ]);

    app(JobReviewService::class)->confirm($user, $posting, [
        ['category' => 'skill', 'name' => 'kafka'],
        ['category' => 'tool', 'name' => 'docker'],
        ['category' => 'requirement', 'name' => 'disponibilidade para viagens'],
        ['category' => 'experience', 'name' => '3+ anos'],
    ], [], 'Dev', null);

    $byKey = $posting->refresh()->items()->get()->mapWithKeys(
        fn ($item) => [$item->category->value.'|'.$item->name => $item->source]
    );

    expect($byKey['skill|Kafka'])->toBe(ItemSource::Ai)
        ->and($byKey['tool|Docker'])->toBe(ItemSource::Ai)
        ->and($byKey['requirement|disponibilidade para viagens'])->toBe(ItemSource::Ai)
        ->and($byKey['experience|3+ anos'])->toBe(ItemSource::Ai);
});

it('confirm respects an explicit user recategorization', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $posting = JobPosting::factory()->for($user, 'user')->create([
        'status' => JobPostingStatus::NeedsReview,
        'suggested_data' => [
            'role' => 'Dev',
            'company' => null,
            'items' => [
                ['category' => 'skill', 'name' => 'PHP'],
            ],
            'application_methods' => [],
        ],
    ]);

    // Correção manual deliberada para outra categoria: o usuário é
    // autoridade na revisão, então a categoria é mantida (só o nome
    // é normalizado) e a origem vira user por diferir da sugestão.
    app(JobReviewService::class)->confirm($user, $posting, [
        ['category' => 'tool', 'name' => 'php'],
    ], [], 'Dev', null);

    $items = $posting->refresh()->items()->get();

    expect($items)->toHaveCount(1)
        ->and($items->first()->category->value)->toBe('tool')
        ->and($items->first()->name)->toBe('PHP')
        ->and($items->first()->source)->toBe(ItemSource::User);
});
