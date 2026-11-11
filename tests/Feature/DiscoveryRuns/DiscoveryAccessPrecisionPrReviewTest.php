<?php

use App\Models\DiscoveryRunEvent;
use App\Models\DiscoverySource;
use App\Models\User;
use App\Services\Discovery\DiscoveryRunConflictException;
use App\Services\Discovery\DiscoveryRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('replays access timestamps with milliseconds at stored seconds precision', function () {
    $this->travelTo(now()->startOfSecond());
    $user = User::factory()->create();
    DiscoverySource::factory()->for($user)->create(['active' => true, 'next_due_at' => now()->subMinute()]);
    $service = app(DiscoveryRunService::class);
    $run = $service->acquire($user, null, 'precision-review')->first();
    $occurredAt = now()->addSeconds(5)->addMilliseconds(123);
    $now = now()->addSeconds(10);
    $payload = [
        'event_key' => 'precision-access',
        'outcome' => 'success',
        'final_url' => 'https://www.linkedin.com/jobs/search/?keywords=PHP',
        'occurred_at' => $occurredAt->toISOString(),
    ];

    $event = $service->reportAccess($user, $run->execution_id, $payload, $now);
    $replay = $service->reportAccess($user, $run->execution_id, $payload, $now);

    expect($replay->id)->toBe($event->id)
        ->and($event->refresh()->occurred_at->microsecond)->toBe(0)
        ->and(DiscoveryRunEvent::query()->count())->toBe(1);

    expect(fn () => $service->reportAccess($user, $run->execution_id, [
        ...$payload,
        'occurred_at' => $occurredAt->copy()->addSecond()->toISOString(),
    ], $now))->toThrow(DiscoveryRunConflictException::class);
});

it('rejects future fractional access times before normalizing precision', function () {
    $this->travelTo(now()->startOfSecond());
    $user = User::factory()->create();
    DiscoverySource::factory()->for($user)->create(['active' => true, 'next_due_at' => now()->subMinute()]);
    $service = app(DiscoveryRunService::class);
    $run = $service->acquire($user, null, 'future-precision-review')->first();

    expect(fn () => $service->reportAccess($user, $run->execution_id, [
        'event_key' => 'future-precision-access',
        'outcome' => 'failure',
        'occurred_at' => now()->addMilliseconds(123)->toISOString(),
    ], now()))->toThrow(ValidationException::class);
    expect(DiscoveryRunEvent::query()->count())->toBe(0);
});
