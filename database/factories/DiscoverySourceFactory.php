<?php

namespace Database\Factories;

use App\Enums\DiscoverySourceSurface;
use App\Enums\SearchPlatform;
use App\Models\DiscoverySource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscoverySource>
 */
class DiscoverySourceFactory extends Factory
{
    public function definition(): array
    {
        $surface = DiscoverySourceSurface::LinkedInJobs;
        $url = 'https://www.linkedin.com/jobs/search/?keywords='.rawurlencode(fake()->unique()->word());

        return [
            'user_id' => User::factory(),
            'name' => 'Busca '.fake()->word(),
            'source' => SearchPlatform::LinkedIn->value,
            'surface' => $surface->value,
            'url' => $url,
            'canonical_url_hash' => hash('sha256', $url),
            'purpose' => null,
            'criteria' => null,
            'frequency_minutes' => 60,
            'active' => true,
            'version' => 1,
            'last_attempt_at' => null,
            'last_success_at' => null,
            'next_due_at' => now(),
        ];
    }
}
