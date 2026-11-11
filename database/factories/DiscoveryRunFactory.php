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
        return [
            'user_id' => null,
            'discovery_source_id' => null,
            'agent_integration_id' => null,
            'execution_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'status' => DiscoveryRunStatus::Active,
            'active_key' => 'run:'.Str::uuid(),
            'canonical_url_hash' => null,
            'initial_url' => null,
            'surface' => null,
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (DiscoveryRun $run): void {
            $source = $run->discovery_source_id === null
                ? null
                : DiscoverySource::withTrashed()->find($run->discovery_source_id);

            $run->user_id ??= $source?->user_id ?? User::factory()->create()->id;

            $source ??= DiscoverySource::factory()
                ->for(User::query()->findOrFail($run->user_id), 'user')
                ->create();

            $run->discovery_source_id = $source->id;
            $run->canonical_url_hash ??= $source->canonical_url_hash;
            $run->initial_url ??= $source->url;
            $run->surface ??= $source->surface;
        });
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
