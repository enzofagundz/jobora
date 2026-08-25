<?php

namespace Database\Factories;

use App\DTO\ApplicationMethodData;
use App\Enums\ApplicationMethodType;
use App\Enums\ItemSource;
use App\Models\ApplicationMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplicationMethod>
 */
class ApplicationMethodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $data = new ApplicationMethodData(
            type: fake()->randomElement(ApplicationMethodType::cases()),
            platform: null,
            url: null,
            email: fake()->boolean() ? fake()->safeEmail() : null,
            phone: null,
            instructions: null,
        );

        return [
            'type' => $data->type,
            'platform' => $data->platform,
            'url' => $data->url,
            'email' => $data->email,
            'phone' => $data->phone,
            'instructions' => $data->instructions,
            'source' => ItemSource::Ai,
            'dedup_key' => $data->dedupKey(),
        ];
    }
}
