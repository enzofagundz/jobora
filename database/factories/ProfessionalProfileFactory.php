<?php

namespace Database\Factories;

use App\Models\ProfessionalProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProfessionalProfile> */
class ProfessionalProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'version' => 0,
            'draft' => ['experiences' => [], 'education' => [], 'competencies' => [], 'compensation_expectation' => null],
            'confirmed_version' => 0,
        ];
    }
}
