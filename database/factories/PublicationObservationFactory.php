<?php

namespace Database\Factories;

use App\Models\PageCapture;
use App\Models\Publication;
use App\Models\PublicationObservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublicationObservation>
 */
class PublicationObservationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'page_capture_id' => PageCapture::factory(),
            'publication_id' => Publication::factory(),
            'observed_at' => now(),
        ];
    }
}
