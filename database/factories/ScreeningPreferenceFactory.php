<?php

namespace Database\Factories;

use App\Models\ScreeningPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScreeningPreference>
 */
class ScreeningPreferenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'preferences' => [],
            'rejections' => [],
        ];
    }
}
