<?php

namespace Database\Factories;

use App\Models\JobCapture;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobCapture>
 */
class JobCaptureFactory extends Factory
{
    public function definition(): array
    {
        $externalId = 'urn:li:activity:'.fake()->unique()->numberBetween(1_000_000_000, 9_000_000_000_000_000_000);

        return [
            'source' => 'linkedin',
            'external_id' => $externalId,
            'dedup_key' => 'ext:'.$externalId,
            'url' => 'https://www.linkedin.com/feed/update/'.$externalId,
            'search_url' => null,
            'title' => null,
            'description' => fake()->paragraphs(2, true),
            'company' => null,
            'location' => null,
            'author_name' => null,
            'author_profile_url' => null,
            'captured_at' => now(),
            'user_id' => User::factory(),
        ];
    }
}
