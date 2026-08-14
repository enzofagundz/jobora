<?php

namespace Database\Factories;

use App\Enums\ConceptCategory;
use App\Enums\ItemSource;
use App\Models\JobPosting;
use App\Models\JobPostingItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobPostingItem>
 */
class JobPostingItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'job_posting_id' => JobPosting::factory(),
            'category' => fake()->randomElement(ConceptCategory::all()),
            'name' => fake()->unique()->word(),
            'source' => ItemSource::Ai,
        ];
    }

    public function category(ConceptCategory $category): static
    {
        return $this->state(fn () => ['category' => $category]);
    }
}
