<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\JobPosting;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Mutação transacional do acompanhamento de candidatura.
 *
 * O início é idempotente por vaga (hasOne + unique em job_posting_id) e o
 * histórico é append-only: toda movimentação grava um novo ApplicationEvent
 * sem nunca alterar ou apagar registros anteriores.
 */
final class ApplicationTrackingService
{
    public function start(JobPosting $posting): Application
    {
        return DB::transaction(function () use ($posting): Application {
            // Trava a linha da vaga para serializar inícios concorrentes.
            JobPosting::whereKey($posting->getKey())->lockForUpdate()->first();

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
                // ponytail: corrida no índice único; reaproveita a candidatura
                // já criada pela outra transação e não duplica o evento inicial.
                $application = Application::where('job_posting_id', $posting->getKey())->firstOrFail();
            }

            return $application;
        });
    }

    public function recordUpdate(
        Application $application,
        ApplicationStatus $status,
        ?string $title,
        ?string $notes,
        ?string $contact,
        CarbonInterface $occurredAt,
    ): ApplicationEvent {
        return DB::transaction(function () use ($application, $status, $title, $notes, $contact, $occurredAt): ApplicationEvent {
            $application = Application::whereKey($application->getKey())
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
