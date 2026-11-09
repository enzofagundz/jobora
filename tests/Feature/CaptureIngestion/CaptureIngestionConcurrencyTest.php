<?php

use App\Models\DiscoverySource;
use App\Models\PageCapture;
use App\Models\User;
use App\Services\Discovery\DiscoveryRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\CaptureIngestion\CaptureIngestionConcurrencyTasks;

uses(RefreshDatabase::class);

function raceEnvelope(int $userId, string $executionId, string $url, string $idempotencyKey, string $html): array
{
    return [
        'format' => 'jobora.capture-envelope',
        'version' => 1,
        'capture' => [
            'idempotency_key' => $idempotencyKey,
            'execution_id' => $executionId,
            'source' => 'linkedin',
            'surface' => 'linkedin_jobs',
            'initial_url' => $url,
            'final_url' => $url,
            'captured_at' => now()->toIso8601String(),
            'coverage' => ['status' => 'complete', 'reason' => null, 'blocks_expected' => 1, 'blocks_captured' => 1],
            'html' => $html,
        ],
        'blocks' => [[
            'block_id' => 'card-1',
            'kind' => 'card',
            'platform_id' => 'p1',
            'permalink' => 'https://www.linkedin.com/jobs/view/1',
            'card_block_id' => null,
            'detail_block_id' => null,
        ]],
    ];
}

function raceHtml(string $suffix = ''): string
{
    return '<main data-capture-surface="linkedin_jobs" data-capture-version="1"><article data-block-id="card-1" data-kind="card" data-platform-id="p1" data-permalink="https://www.linkedin.com/jobs/view/1">Vaga '.$suffix.'</article></main>';
}

it('produces one capture and one winner object with two processes using the same key and payload', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Corrida real exige PostgreSQL.');
    }

    $user = User::factory()->create();
    DiscoverySourceFactoryForRace::create($user);
    $run = app(DiscoveryRunService::class)->acquire($user, null, 'run-race')->first();
    $payload = raceEnvelope($user->id, $run->execution_id, $run->initial_url, 'race-capture', raceHtml());
    $json = json_encode($payload);

    DB::commit();

    $first = Closure::bind(
        fn () => CaptureIngestionConcurrencyTasks::ingest($user->id, $json),
        null,
        CaptureIngestionConcurrencyTasks::class,
    );
    $second = Closure::bind(
        fn () => CaptureIngestionConcurrencyTasks::ingest($user->id, $json),
        null,
        CaptureIngestionConcurrencyTasks::class,
    );

    try {
        $results = Concurrency::run([$first, $second]);
        sort($results);

        expect($results)->toBe(['created', 'replay'])
            ->and(PageCapture::query()->count())->toBe(1)
            ->and(PageCapture::query()->sole()->payload_hash)->not->toBeNull();

        $run->refresh();
        expect($run->page_capture_id)->toBe(PageCapture::query()->sole()->id)
            ->and(Storage::disk('local')->allFiles('capture-envelopes/'.$user->id))->toHaveCount(1);
    } finally {
        Storage::disk('local')->deleteDirectory('capture-envelopes/'.$user->id);

        DB::table('page_captures')->delete();
        DB::table('discovery_run_events')->delete();
        DB::table('discovery_runs')->delete();
        DB::table('discovery_sources')->delete();
        DB::table('agent_integrations')->delete();

        RefreshDatabaseState::$migrated = false;
    }
});

it('keeps one winner and one conflict for divergent payloads sharing a key', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Corrida real exige PostgreSQL.');
    }

    $user = User::factory()->create();
    DiscoverySourceFactoryForRace::create($user);
    $run = app(DiscoveryRunService::class)->acquire($user, null, 'run-race-div')->first();

    $firstJson = json_encode(raceEnvelope($user->id, $run->execution_id, $run->initial_url, 'race-div', raceHtml('A')));
    $secondJson = json_encode(raceEnvelope($user->id, $run->execution_id, $run->initial_url, 'race-div', raceHtml('B')));

    DB::commit();

    $first = Closure::bind(
        fn () => CaptureIngestionConcurrencyTasks::ingest($user->id, $firstJson),
        null,
        CaptureIngestionConcurrencyTasks::class,
    );
    $second = Closure::bind(
        fn () => CaptureIngestionConcurrencyTasks::ingest($user->id, $secondJson),
        null,
        CaptureIngestionConcurrencyTasks::class,
    );

    try {
        $results = Concurrency::run([$first, $second]);
        sort($results);

        expect($results)->toBe(['conflict', 'created'])
            ->and(PageCapture::query()->count())->toBe(1)
            ->and(Storage::disk('local')->allFiles('capture-envelopes/'.$user->id))->toHaveCount(1);
    } finally {
        Storage::disk('local')->deleteDirectory('capture-envelopes/'.$user->id);

        DB::table('page_captures')->delete();
        DB::table('discovery_run_events')->delete();
        DB::table('discovery_runs')->delete();
        DB::table('discovery_sources')->delete();
        DB::table('agent_integrations')->delete();

        RefreshDatabaseState::$migrated = false;
    }
});

final class DiscoverySourceFactoryForRace
{
    public static function create(User $user): void
    {
        DiscoverySource::factory()->for($user, 'user')->create([
            'active' => true,
            'next_due_at' => now()->subMinute(),
        ]);
    }
}
