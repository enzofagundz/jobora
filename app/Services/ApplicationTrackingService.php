<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\JobPosting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Mutação transacional do acompanhamento de candidatura.
 */
final class ApplicationTrackingService
{
    public function start(User $user, JobPosting $posting): Application
    {
        Gate::forUser($user)->authorize('update', $posting);

        if ($posting->user_id !== $user->id) {
            throw new \LogicException('Vaga não pertence ao usuário.');
        }

        return DB::transaction(function () use ($user, $posting): Application {
            $user->jobPostings()->whereKey($posting->getKey())->lockForUpdate()->first();

            $application = Application::where('job_posting_id', $posting->getKey())->first();

            if ($application !== null) {
                return $application;
            }

            try {
                $application = $posting->application()->create([
                    'status' => ApplicationStatus::Planned,
                ]);

                $application->events()->create([
                    'status' => ApplicationStatus::Planned,
                    'title' => 'Acompanhamento iniciado',
                    'occurred_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $application = Application::where('job_posting_id', $posting->getKey())->firstOrFail();
            }

            return $application;
        });
    }

    public function recordUpdate(
        User $user,
        Application $application,
        ApplicationStatus $status,
        ?string $title = null,
        ?string $notes = null,
        ?string $contact = null,
        ?CarbonInterface $occurredAt = null,
    ): ApplicationEvent {
        return DB::transaction(function () use ($user, $application, $status, $title, $notes, $contact, $occurredAt): ApplicationEvent {
            $application = Application::whereKey($application->getKey())
                ->whereHas('jobPosting', fn ($query) => $query->whereBelongsTo($user))
                ->lockForUpdate()
                ->firstOrFail();

            $title = filled($title)
                ? mb_substr(trim($title), 0, 255)
                : $status->getLabel();
            $notes = filled($notes) ? trim($notes) : null;
            $contact = filled($contact) ? mb_substr(trim($contact), 0, 255) : null;

            $application->update([
                'status' => $status,
            ]);

            return $application->events()->create([
                'status' => $status,
                'title' => $title,
                'notes' => $notes,
                'contact' => $contact,
                'occurred_at' => $occurredAt,
            ]);
        });
    }
}
