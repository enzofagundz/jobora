<?php

namespace Database\Factories;

use App\Models\JobPosting;
use App\Models\OpportunitySource;
use App\Models\Publication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpportunitySource>
 */
class OpportunitySourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'job_posting_id' => JobPosting::factory(),
            'publication_id' => Publication::factory(),
            'is_legacy' => false,
        ];
    }
}
