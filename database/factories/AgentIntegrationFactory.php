<?php

namespace Database\Factories;

use App\Enums\AgentIntegrationScope;
use App\Models\AgentIntegration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AgentIntegration>
 */
class AgentIntegrationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'oauth_client_id' => (string) Str::uuid(),
            'name' => fake()->words(2, true),
            'scopes' => AgentIntegrationScope::defaults(),
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }

    /**
     * @param  array<int, AgentIntegrationScope|string>  $scopes
     */
    public function withScopes(array $scopes): static
    {
        return $this->state(fn (): array => [
            'scopes' => array_map(
                static fn (AgentIntegrationScope|string $scope): string => $scope instanceof AgentIntegrationScope ? $scope->value : $scope,
                $scopes,
            ),
        ]);
    }
}
