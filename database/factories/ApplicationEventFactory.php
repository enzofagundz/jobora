<?php

namespace Database\Factories;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\ApplicationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplicationEvent>
 */
class ApplicationEventFactory extends Factory
{
    protected $model = ApplicationEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $status = ApplicationStatus::Planned;

        return [
            'application_id' => Application::factory(),
            'status' => $status,
            'title' => 'Acompanhamento iniciado',
            'notes' => null,
            'contact' => null,
            'occurred_at' => now(),
        ];
    }

    public function status(ApplicationStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
