<?php

use App\Models\AgentIntegration;
use App\Models\DiscoveryRun;
use App\Models\DiscoverySource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\Feature\DiscoveryRuns\RunConcurrencyTasks;

uses(RefreshDatabase::class);

it('allows only one reservation with two integrations racing on postgres', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Corrida real exige PostgreSQL.');
    }

    $user = User::factory()->create();
    $source = DiscoverySource::factory()->for($user, 'user')->create([
        'active' => true,
        'next_due_at' => now()->subMinute(),
    ]);
    $first = AgentIntegration::factory()->for($user, 'user')->create();
    $second = AgentIntegration::factory()->for($user, 'user')->create();

    DB::commit();

    $firstTask = Closure::bind(
        fn () => RunConcurrencyTasks::acquire($user->id, $first->id, 'race-first'),
        null,
        RunConcurrencyTasks::class,
    );

    $secondTask = Closure::bind(
        fn () => RunConcurrencyTasks::acquire($user->id, $second->id, 'race-second'),
        null,
        RunConcurrencyTasks::class,
    );

    try {
        $results = Concurrency::run([$firstTask, $secondTask]);

        expect(array_sum($results))->toBe(1)
            ->and(DiscoveryRun::query()
                ->where('discovery_source_id', $source->id)
                ->where('status', 'active')
                ->count())->toBe(1);
    } finally {
        DB::table('discovery_run_events')->delete();
        DB::table('discovery_runs')->delete();
        DB::table('discovery_sources')->delete();
        DB::table('agent_integrations')->delete();

        RefreshDatabaseState::$migrated = false;
    }
});

it('replays the same batch across two processes without acquiring new sources', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Corrida real exige PostgreSQL.');
    }

    $user = User::factory()->create();
    DiscoverySource::factory()->for($user, 'user')->count(3)->create([
        'active' => true,
        'next_due_at' => now()->subMinute(),
    ]);
    $first = AgentIntegration::factory()->for($user, 'user')->create();
    $second = AgentIntegration::factory()->for($user, 'user')->create();

    DB::commit();

    $firstTask = Closure::bind(
        fn () => RunConcurrencyTasks::acquireIds($user->id, $first->id, 'batch-race', 2),
        null,
        RunConcurrencyTasks::class,
    );
    $secondTask = Closure::bind(
        fn () => RunConcurrencyTasks::acquireIds($user->id, $second->id, 'batch-race', 2),
        null,
        RunConcurrencyTasks::class,
    );

    try {
        $results = Concurrency::run([$firstTask, $secondTask]);

        sort($results[0]);
        sort($results[1]);

        expect($results[0])->toBe($results[1])
            ->and($results[0])->toHaveCount(2)
            ->and(DiscoveryRun::query()->where('status', 'active')->count())->toBe(2)
            ->and(DB::table('discovery_acquisitions')->where('idempotency_key', 'batch-race')->count())->toBe(1);
    } finally {
        DB::table('page_captures')->delete();
        DB::table('discovery_run_events')->delete();
        DB::table('discovery_runs')->delete();
        DB::table('discovery_acquisitions')->delete();
        DB::table('discovery_sources')->delete();
        DB::table('agent_integrations')->delete();

        RefreshDatabaseState::$migrated = false;
    }
});
