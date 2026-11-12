<?php

namespace Database\Factories;

use App\Models\Publication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Publication>
 */
class PublicationFactory extends Factory
{
    public function definition(): array
    {
        $externalId = 'urn:li:activity:'.fake()->unique()->numberBetween(1_000_000_000, 9_000_000_000_000_000_000);
        $canonicalUrl = 'https://www.linkedin.com/feed/update/'.$externalId;

        return [
            'user_id' => User::factory(),
            'source' => 'linkedin',
            'external_id' => $externalId,
            'url' => $canonicalUrl,
            'canonical_url' => $canonicalUrl,
            'canonical_url_hash' => hash('sha256', $canonicalUrl),
            'fingerprint' => null,
        ];
    }
}
