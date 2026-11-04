<?php

namespace Database\Factories;

use App\Models\PageCapture;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PageCapture>
 */
class PageCaptureFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source' => 'linkedin',
            'surface' => 'linkedin_publications',
            'url' => 'https://www.linkedin.com/search/results/content/?keywords=vaga',
            'html_path' => null,
            'captured_at' => now(),
            'legacy_job_capture_id' => null,
        ];
    }
}
