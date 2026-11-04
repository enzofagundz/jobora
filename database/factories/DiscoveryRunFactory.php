<?php

namespace Database\Factories;

use App\Enums\DiscoveryRunStatus;
use App\Models\DiscoveryRun;
use App\Models\DiscoverySource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DiscoveryRun>
 */
class DiscoveryRunFactory extends Factory
{
    public function definition(): array
    {
        $source = DiscoverySource::factory();

        return [
            'user_id' => User::factory(),
            'discovery_source_id' => $source,
            'agent_integration_id' => null,
            'execution_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'status' => DiscoveryRunStatus::Active,
            'active_key' => 'run:'.Str::uuid(),
            'canonical_url_hash' => str_repeat('a', 64),
            'initial_url' => 'https://www.linkedin.com/jobs/search/?keywords=Laravel',
            'surface' => 'linkedin_jobs',
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => DiscoveryRunStatus::Expired,
            'active_key' => null,
            'expires_at' => now()->subMinute(),
        ]);
    }
}
