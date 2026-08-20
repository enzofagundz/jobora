<?php

namespace Database\Factories;

use App\Enums\ProfessionalCompetencySource;
use App\Enums\StrategicRole;
use App\Models\ProfessionalCompetency;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProfessionalCompetency>
 */
class ProfessionalCompetencyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'concept' => fake()->randomElement(['PHP', 'Laravel', 'PostgreSQL', 'Docker', 'AWS']),
            'strategic_role' => StrategicRole::Core,
            'source' => ProfessionalCompetencySource::Manual,
        ];
    }

    public function role(StrategicRole $role): static
    {
        return $this->state(fn () => ['strategic_role' => $role]);
    }

    public function source(ProfessionalCompetencySource $source): static
    {
        return $this->state(fn () => ['source' => $source]);
    }
}
