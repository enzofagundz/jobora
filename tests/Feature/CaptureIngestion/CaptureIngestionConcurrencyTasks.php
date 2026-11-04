<?php

namespace Tests\Feature\CaptureIngestion;

use App\Models\User;
use App\Services\Discovery\CaptureConflictException;
use App\Services\Discovery\CaptureIngestionService;

final class CaptureIngestionConcurrencyTasks
{
    public static function ingest(int $userId, string $payloadJson): string
    {
        try {
            [$capture, $created] = app(CaptureIngestionService::class)->ingest(
                User::query()->findOrFail($userId),
                json_decode($payloadJson, true),
            );

            return $created ? 'created' : 'replay';
        } catch (CaptureConflictException) {
            return 'conflict';
        }
    }
}
