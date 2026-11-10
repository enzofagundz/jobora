<?php

use App\Enums\DiscoveryAccessOutcome;
use App\Enums\DiscoveryRunStatus;
use App\Models\DiscoveryRun;
use App\Models\DiscoverySource;
use App\Models\User;
use App\Services\Discovery\DiscoveryRunConflictException;
use App\Services\Discovery\DiscoveryRunService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function runService(): DiscoveryRunService
{
    return app(DiscoveryRunService::class);
}

function dueSource(User $user, array $attributes = []): DiscoverySource
{
    return DiscoverySource::factory()->for($user, 'user')->create(array_merge([
        'active' => true,
        'next_due_at' => now()->subMinute(),
    ], $attributes));
}

it('lists pending sources without creating reservations', function () {
    $user = User::factory()->create();
    dueSource($user, ['name' => 'Vencida']);
    dueSource($user, ['name' => 'Futura', 'next_due_at' => now()->addHour()]);
    dueSource($user, ['name' => 'Inativa', 'active' => false]);

    $page = runService()->pending($user);

    expect($page->total())->toBe(1)
        ->and($page->items()[0]->name)->toBe('Vencida')
        ->and(DiscoveryRun::query()->count())->toBe(0);
});

it('acquires a reservation with an opaque execution id and idempotent replay', function () {
    $user = User::factory()->create();
    $source = dueSource($user);

    $runs = runService()->acquire($user, null, 'idem-1');

    expect($runs)->toHaveCount(1);

    $run = $runs->first();

    expect($run->status)->toBe(DiscoveryRunStatus::Active)
        ->and($run->execution_id)->not->toBeEmpty()
        ->and($run->canonical_url_hash)->toBe($source->canonical_url_hash)
        ->and($run->reserved_at->diffInMinutes($run->expires_at))->toBe(30.0)
        ->and($source->refresh()->last_attempt_at)->not->toBeNull();

    $replay = runService()->acquire($user, null, 'idem-1');

    expect($replay)->toHaveCount(1)
        ->and($replay->first()->id)->toBe($run->id)
        ->and(DiscoveryRun::query()->count())->toBe(1);
});

it('reserves only one active run per source and skips inactive future and deleted sources', function () {
    $user = User::factory()->create();
    $source = dueSource($user);

    expect(runService()->acquire($user, null, 'first'))->toHaveCount(1)
        ->and(runService()->acquire($user, null, 'second'))->toHaveCount(0);

    $futureUser = User::factory()->create();
    dueSource($futureUser, ['next_due_at' => now()->addHour()]);
    expect(runService()->acquire($futureUser, null, 'future'))->toHaveCount(0);

    $inactiveUser = User::factory()->create();
    dueSource($inactiveUser, ['active' => false]);
    expect(runService()->acquire($inactiveUser, null, 'inactive'))->toHaveCount(0);

    $deletedUser = User::factory()->create();
    dueSource($deletedUser)->delete();
    expect(runService()->acquire($deletedUser, null, 'deleted'))->toHaveCount(0);

    expect($source->refresh()->last_attempt_at)->not->toBeNull();
});

it('expires reservations and allows a new takeover with a new execution id', function () {
    $user = User::factory()->create();
    dueSource($user);

    $old = runService()->acquire($user, null, 'old')->first();

    $expired = runService()->expireDue($old->expires_at->copy()->addMinute());

    expect($expired)->toBe(1)
        ->and($old->refresh()->status)->toBe(DiscoveryRunStatus::Expired)
        ->and($old->active_key)->toBeNull();

    $new = runService()->acquire($user, null, 'new', now: $old->expires_at->copy()->addMinute())->first();

    expect($new->id)->not->toBe($old->id)
        ->and($new->status)->toBe(DiscoveryRunStatus::Active);
});

it('rejects a stale callback without touching the new run', function () {
    $user = User::factory()->create();
    dueSource($user);

    $old = runService()->acquire($user, null, 'old')->first();
    runService()->expireDue($old->expires_at->copy()->addMinute());
    $new = runService()->acquire($user, null, 'new', now: $old->expires_at->copy()->addMinute())->first();

    expect(fn () => runService()->reportAccess($user, $old->execution_id, [
        'event_key' => 'stale-event',
        'outcome' => 'success',
        'final_url' => 'https://www.linkedin.com/jobs/search/?keywords=Laravel',
    ]))->toThrow(DiscoveryRunConflictException::class);

    expect($new->refresh()->last_access_at)->toBeNull()
        ->and($new->status)->toBe(DiscoveryRunStatus::Active);
});

it('replays access events idempotently without declaring a capture', function () {
    $user = User::factory()->create();
    $source = dueSource($user);
    $source->forceFill(['last_success_at' => now()->subDay()])->save();
    $run = runService()->acquire($user, null, 'idem')->first();

    $payload = [
        'event_key' => 'access-1',
        'outcome' => 'success',
        'final_url' => 'https://www.linkedin.com/jobs/search/?keywords=Laravel',
        'redirects' => ['https://www.linkedin.com/login'],
        'http_status' => 200,
    ];

    $first = runService()->reportAccess($user, $run->execution_id, $payload);
    $second = runService()->reportAccess($user, $run->execution_id, $payload);

    expect($second->id)->toBe($first->id)
        ->and($first->outcome)->toBe(DiscoveryAccessOutcome::Success)
        ->and($first->redirects)->toBe(['https://www.linkedin.com/login'])
        ->and(DiscoveryRun::query()->whereKey($run->id)->count())->toBe(1)
        ->and($run->refresh()->last_access_at)->not->toBeNull()
        ->and($run->page_capture_id)->toBeNull()
        ->and($source->refresh()->last_success_at->toDateString())->toBe(now()->subDay()->toDateString());
});

it('rejects an event key reused with different content', function () {
    $user = User::factory()->create();
    dueSource($user);
    $run = runService()->acquire($user, null, 'idem')->first();

    runService()->reportAccess($user, $run->execution_id, [
        'event_key' => 'access-1',
        'outcome' => 'success',
        'final_url' => 'https://www.linkedin.com/jobs/search/?keywords=Laravel',
    ]);

    expect(fn () => runService()->reportAccess($user, $run->execution_id, [
        'event_key' => 'access-1',
        'outcome' => 'failure',
        'error' => 'bloqueado',
    ]))->toThrow(DiscoveryRunConflictException::class);
});

it('requires a final url for success and records failures', function () {
    $user = User::factory()->create();
    dueSource($user);
    $run = runService()->acquire($user, null, 'idem')->first();

    expect(fn () => runService()->reportAccess($user, $run->execution_id, [
        'event_key' => 'missing-url',
        'outcome' => 'success',
    ]))->toThrow(ValidationException::class);

    $event = runService()->reportAccess($user, $run->execution_id, [
        'event_key' => 'failure-1',
        'outcome' => 'failure',
        'error' => 'login exigido',
    ]);

    expect($event->outcome)->toBe(DiscoveryAccessOutcome::Failure)
        ->and($run->refresh()->last_access_at)->not->toBeNull();
});

it('isolates runs by owner', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    dueSource($owner);
    $run = runService()->acquire($owner, null, 'idem')->first();

    expect(fn () => runService()->get($intruder, $run->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => runService()->reportAccess($intruder, $run->execution_id, [
        'event_key' => 'x',
        'outcome' => 'failure',
    ]))->toThrow(ModelNotFoundException::class);
});

it('resolves the active run for the extension by canonical search url', function () {
    $user = User::factory()->create();
    $source = dueSource($user);
    $run = runService()->acquire($user, null, 'idem')->first();

    $resolved = runService()->resolveForExtension(
        $user,
        str_replace('https://www.linkedin.com', 'https://WWW.LINKEDIN.COM', $source->url).'&trk=abc',
    );

    expect($resolved->id)->toBe($run->id)
        ->and($resolved->execution_id)->toBe($run->execution_id);

    expect(fn () => runService()->resolveForExtension($user, 'https://www.linkedin.com/jobs/search/?keywords=Outra'))
        ->toThrow(ModelNotFoundException::class);

    DiscoveryRun::factory()->for($user, 'user')->create([
        'discovery_source_id' => $source->id,
        'canonical_url_hash' => $source->canonical_url_hash,
    ]);

    expect(fn () => runService()->resolveForExtension($user, $source->url))
        ->toThrow(DiscoveryRunConflictException::class);
});
it('expires reservations through the scheduled command without executing discovery', function () {
    $user = User::factory()->create();
    dueSource($user);
    $run = runService()->acquire($user, null, 'idem')->first();

    $this->travelTo($run->expires_at->copy()->addMinute());

    $this->artisan('discovery-runs:expire')->assertSuccessful();

    expect($run->refresh()->status)->toBe(DiscoveryRunStatus::Expired)
        ->and(DiscoveryRun::query()->count())->toBe(1);
});

it('acquires a batch and replays the exact batch without taking new sources', function () {
    $user = User::factory()->create();
    $first = dueSource($user);
    $second = dueSource($user);

    $batch = runService()->acquire($user, null, 'batch-key', limit: 2);

    expect($batch)->toHaveCount(2);

    $ids = $batch->pluck('id')->sort()->values()->all();

    $third = dueSource($user);
    $replay = runService()->acquire($user, null, 'batch-key', limit: 2);

    expect($replay->pluck('id')->sort()->values()->all())->toBe($ids)
        ->and(DiscoveryRun::query()->count())->toBe(2)
        ->and(DiscoveryRun::query()->where('discovery_source_id', $third->id)->exists())->toBeFalse();
});

it('returns the requested slice for pending offsets that are not page aligned', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $index) {
        dueSource($user, ['name' => 'Fonte '.$index, 'next_due_at' => now()->subMinutes(10 - $index)]);
    }

    $page = runService()->pending($user, 2, 1);

    expect($page->total())->toBe(5)
        ->and($page->items())->toHaveCount(2)
        ->and($page->items()[0]->name)->toBe('Fonte 2')
        ->and($page->items()[1]->name)->toBe('Fonte 3');
});

it('returns the requested slice for run offsets that are not page aligned', function () {
    $user = User::factory()->create();
    dueSource($user);
    dueSource($user);
    dueSource($user);

    runService()->acquire($user, null, 'batch', limit: 3);

    $page = runService()->paginateRuns($user, [], 2, 1);
    $expected = DiscoveryRun::query()->orderByDesc('id')->pluck('id')->all();

    expect($page->total())->toBe(3)
        ->and($page->items())->toHaveCount(2)
        ->and($page->items()[0]->id)->toBe($expected[1])
        ->and($page->items()[1]->id)->toBe($expected[2]);
});

it('persists the expiry when a stale callback arrives after the deadline', function () {
    $user = User::factory()->create();
    dueSource($user);
    $run = runService()->acquire($user, null, 'idem')->first();

    expect(fn () => runService()->reportAccess($user, $run->execution_id, [
        'event_key' => 'late',
        'outcome' => 'failure',
        'error' => 'tarde',
    ], $run->expires_at->copy()->addMinute()))->toThrow(DiscoveryRunConflictException::class);

    expect($run->refresh()->status)->toBe(DiscoveryRunStatus::Expired)
        ->and($run->active_key)->toBeNull();
});

it('rejects an event key replayed with different error details', function () {
    $user = User::factory()->create();
    dueSource($user);
    $run = runService()->acquire($user, null, 'idem')->first();

    runService()->reportAccess($user, $run->execution_id, [
        'event_key' => 'failure-1',
        'outcome' => 'failure',
        'error' => 'login exigido',
        'details' => ['step' => 'auth'],
    ]);

    expect(fn () => runService()->reportAccess($user, $run->execution_id, [
        'event_key' => 'failure-1',
        'outcome' => 'failure',
        'error' => 'captcha exigido',
        'details' => ['step' => 'auth'],
    ]))->toThrow(DiscoveryRunConflictException::class);
});

it('allows the same event key on different runs and users', function () {
    $owner = User::factory()->create();
    dueSource($owner);
    dueSource($owner);
    $runA = runService()->acquire($owner, null, 'batch', limit: 2)->first();
    $runB = DiscoveryRun::query()->whereKeyNot($runA->id)->firstOrFail();

    $payload = ['event_key' => 'access-1', 'outcome' => 'failure', 'error' => 'bloqueado'];

    runService()->reportAccess($owner, $runA->execution_id, $payload);
    runService()->reportAccess($owner, $runB->execution_id, $payload);

    $intruder = User::factory()->create();
    dueSource($intruder);
    $runC = runService()->acquire($intruder, null, 'idem')->first();

    runService()->reportAccess($intruder, $runC->execution_id, $payload);

    expect($runA->events()->count())->toBe(1)
        ->and($runB->events()->count())->toBe(1)
        ->and($runC->events()->count())->toBe(1);
});
