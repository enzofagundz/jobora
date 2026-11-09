<?php

namespace Tests\Feature\DiscoveryRuns;

use App\Models\AgentIntegration;
use App\Models\User;
use App\Services\Discovery\DiscoveryRunService;

final class RunConcurrencyTasks
{
    public static function acquire(int $userId, int $integrationId, string $key, int $limit = 1): int
    {
        return app(DiscoveryRunService::class)->acquire(
            User::query()->findOrFail($userId),
            AgentIntegration::query()->findOrFail($integrationId),
            $key,
            $limit,
        )->count();
    }

    /**
     * @return array<int, int>
     */
    public static function acquireIds(int $userId, int $integrationId, string $key, int $limit = 1): array
    {
        return app(DiscoveryRunService::class)->acquire(
            User::query()->findOrFail($userId),
            AgentIntegration::query()->findOrFail($integrationId),
            $key,
            $limit,
        )->pluck('id')->sort()->values()->all();
    }
}
