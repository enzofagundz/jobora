<?php

namespace Database\Factories;

use App\Enums\DiscoveryAccessOutcome;
use App\Models\DiscoveryRun;
use App\Models\DiscoveryRunEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DiscoveryRunEvent>
 */
class DiscoveryRunEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'discovery_run_id' => DiscoveryRun::factory(),
            'event_key' => (string) Str::uuid(),
            'outcome' => DiscoveryAccessOutcome::Success,
            'final_url' => 'https://www.linkedin.com/jobs/search/?keywords=Laravel',
            'http_status' => 200,
            'redirects' => [],
            'details' => null,
            'error' => null,
            'occurred_at' => now(),
        ];
    }
}
