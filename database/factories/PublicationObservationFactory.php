<?php

namespace Database\Factories;

use App\Models\PageCapture;
use App\Models\Publication;
use App\Models\PublicationObservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublicationObservation>
 */
class PublicationObservationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'page_capture_id' => null,
            'publication_id' => null,
            'observed_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (PublicationObservation $observation): void {
            $capture = $observation->page_capture_id === null
                ? null
                : PageCapture::query()->find($observation->page_capture_id);

            $publication = $observation->publication_id === null
                ? null
                : Publication::query()->find($observation->publication_id);

            $userId = $capture?->user_id ?? $publication?->user_id ?? User::factory()->create()->id;
            $user = User::query()->findOrFail($userId);

            $capture ??= PageCapture::factory()->for($user, 'user')->create();
            $publication ??= Publication::factory()->for($user, 'user')->create();

            $observation->page_capture_id = $capture->id;
            $observation->publication_id = $publication->id;
        });
    }
}
