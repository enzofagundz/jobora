<?php

namespace Database\Factories;

use App\Models\JobPosting;
use App\Models\OpportunitySource;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpportunitySource>
 */
class OpportunitySourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'job_posting_id' => null,
            'publication_id' => null,
            'is_legacy' => false,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (OpportunitySource $source): void {
            $posting = $source->job_posting_id === null
                ? null
                : JobPosting::query()->find($source->job_posting_id);

            $publication = $source->publication_id === null
                ? null
                : Publication::query()->find($source->publication_id);

            $userId = $posting?->user_id ?? $publication?->user_id ?? User::factory()->create()->id;
            $user = User::query()->findOrFail($userId);

            $posting ??= JobPosting::factory()->for($user, 'user')->create();
            $publication ??= Publication::factory()->for($user, 'user')->create();

            $source->job_posting_id = $posting->id;
            $source->publication_id = $publication->id;
        });
    }
}
