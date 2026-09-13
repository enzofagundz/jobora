<?php

namespace App\Mcp\Support;

use App\Models\Application;
use App\Models\ApplicationEvent;

/**
 * Representação pública de candidatura e eventos nas respostas MCP.
 */
final class ApplicationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Application $application): array
    {
        return [
            'id' => $application->id,
            'job_posting_id' => $application->job_posting_id,
            'status' => $application->status?->value,
            'created_at' => $application->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function event(ApplicationEvent $event): array
    {
        return [
            'id' => $event->id,
            'status' => $event->status?->value,
            'title' => $event->title,
            'notes' => $event->notes,
            'contact' => $event->contact,
            'occurred_at' => $event->occurred_at?->toIso8601String(),
        ];
    }
}
