<?php

namespace Database\Factories;

use App\Models\PublicationIdentityConflict;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublicationIdentityConflict>
 */
class PublicationIdentityConflictFactory extends Factory
{
    public function definition(): array
    {
        $externalId = 'urn:li:activity:'.fake()->unique()->numberBetween(1_000_000_000, 9_000_000_000_000_000_000);

        return [
            'user_id' => User::factory(),
            'source' => 'linkedin',
            'external_id' => $externalId,
            'canonical_url' => 'https://www.linkedin.com/feed/update/'.$externalId,
            'publication_id' => null,
            'other_publication_id' => null,
            'reason' => PublicationIdentityConflict::ExternalIdUrlMismatch,
        ];
    }
}
