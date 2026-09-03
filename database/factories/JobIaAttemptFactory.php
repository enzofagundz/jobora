<?php

namespace Database\Factories;

use App\Models\JobIaAttempt;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;

/**
 * @extends Factory<JobIaAttempt>
 */
class JobIaAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_posting_id' => JobPosting::factory(),
            'user_id' => User::factory(),
            'operation' => 'job_extraction',
            'provider' => 'opencode_zen',
            'model' => 'mimo-v2.5-free',
            'status' => 'permanent_error',
            'error_class' => InvalidArgumentException::class,
            'error_message' => 'A resposta da IA não é um JSON válido.',
            'transient' => false,
            'latency_ms' => 100,
            'input_chars' => 500,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'cache_write_input_tokens' => 0,
            'cache_read_input_tokens' => 0,
            'reasoning_tokens' => 0,
            'attempt_no' => 1,
            'trigger' => 'auto_dispatch',
        ];
    }
}
