<?php

namespace Database\Factories;

use App\Enums\JobPostingStatus;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobPosting>
 */
class JobPostingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'description' => fake()->paragraphs(3, true),
            'source_url' => null,
            'collected_at' => now(),
            'status' => JobPostingStatus::Pending,
            'role' => null,
            'company' => null,
            'suggested_data' => null,
            'error_message' => null,
            'user_id' => User::factory(),
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn () => ['status' => JobPostingStatus::Confirmed]);
    }

    public function needsReview(): static
    {
        return $this->state(fn () => ['status' => JobPostingStatus::NeedsReview]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => JobPostingStatus::Failed,
            'error_message' => 'Falha simulada na análise.',
        ]);
    }
}
