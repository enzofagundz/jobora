<?php

use App\Models\PageCapture;
use App\Models\Publication;
use App\Models\PublicationIdentityConflict;
use App\Models\PublicationObservation;
use App\Models\User;
use App\Services\Discovery\ObservationRecorder;
use App\Services\Discovery\PublicationIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

$raceCleanup = new ArrayObject(['publications' => [], 'users' => []]);

it('resolve corrida de criação no PostgreSQL sem abortar a transação', function () use ($raceCleanup) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Corrida real exige segunda conexão PostgreSQL.');
    }

    config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);
    DB::purge('pgsql_race');

    $raceUserId = DB::connection('pgsql_race')->table('users')->insertGetId([
        'name' => 'Race User',
        'email' => 'race-'.uniqid().'@example.test',
        'password' => bcrypt('password'),
        'is_admin' => false,
        'can_use_ats_optimization' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $raceCleanup['users'][] = $raceUserId;

    $this->beforeApplicationDestroyed(function () use ($raceCleanup): void {
        config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);
        DB::purge('pgsql_race');

        if ($raceCleanup['publications'] !== []) {
            DB::connection('pgsql_race')->table('publications')->whereIn('id', $raceCleanup['publications'])->delete();
        }

        if ($raceCleanup['users'] !== []) {
            DB::connection('pgsql_race')->table('users')->whereIn('id', $raceCleanup['users'])->delete();
        }

        DB::purge('pgsql_race');
    });
    $user = User::query()->findOrFail($raceUserId);
    $injected = false;

    Publication::creating(function (Publication $publication) use ($user, &$injected, $raceCleanup): void {
        if ($injected) {
            return;
        }

        $injected = true;

        $raceCleanup['publications'][] = DB::connection('pgsql_race')->table('publications')->insertGetId([
            'user_id' => $user->id,
            'source' => 'linkedin',
            'external_id' => 'urn:li:activity:race',
            'url' => null,
            'canonical_url' => null,
            'canonical_url_hash' => null,
            'fingerprint' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        $publication = app(PublicationIdentityResolver::class)->resolve($user, 'linkedin', 'urn:li:activity:race', null);

        expect($publication->external_id)->toBe('urn:li:activity:race')
            ->and(Publication::query()->where('user_id', $user->id)->count())->toBe(1)
            ->and(DB::table('publications')->where('user_id', $user->id)->count())->toBe(1);
    } finally {
        Event::forget('eloquent.creating: '.Publication::class);
        DB::purge('pgsql_race');
    }
});

it('resolve colisão durante enriquecimento sem abortar a transação', function () use ($raceCleanup) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Corrida real exige segunda conexão PostgreSQL.');
    }

    config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);
    DB::purge('pgsql_race');

    $raceUserId = DB::connection('pgsql_race')->table('users')->insertGetId([
        'name' => 'Race Enrichment',
        'email' => 'race-enrichment-'.uniqid().'@example.test',
        'password' => bcrypt('password'),
        'is_admin' => false,
        'can_use_ats_optimization' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $raceCleanup['users'][] = $raceUserId;

    $this->beforeApplicationDestroyed(function () use ($raceCleanup): void {
        config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);
        DB::purge('pgsql_race');

        if ($raceCleanup['publications'] !== []) {
            DB::connection('pgsql_race')->table('publications')->whereIn('id', $raceCleanup['publications'])->delete();
        }

        if ($raceCleanup['users'] !== []) {
            DB::connection('pgsql_race')->table('users')->whereIn('id', $raceCleanup['users'])->delete();
        }

        DB::purge('pgsql_race');
    });
    $user = User::query()->findOrFail($raceUserId);
    $resolver = app(PublicationIdentityResolver::class);
    $publication = $resolver->resolve($user, 'linkedin', 'urn:li:activity:enrich', null);
    $canonical = $resolver->canonicalUrl('https://www.linkedin.com/feed/update/urn:li:activity:other/');
    $injected = false;

    Publication::updating(function (Publication $model) use ($user, $canonical, &$injected, $raceCleanup): void {
        if ($injected) {
            return;
        }

        $injected = true;

        $raceCleanup['publications'][] = DB::connection('pgsql_race')->table('publications')->insertGetId([
            'user_id' => $user->id,
            'source' => 'linkedin',
            'external_id' => 'urn:li:activity:enrich-other',
            'url' => null,
            'canonical_url' => $canonical,
            'canonical_url_hash' => hash('sha256', $canonical),
            'fingerprint' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        $resolved = $resolver->resolve($user, 'linkedin', 'urn:li:activity:enrich', 'https://www.linkedin.com/feed/update/urn:li:activity:other/');

        expect($resolved->is($publication))->toBeTrue()
            ->and(Publication::query()->where('user_id', $user->id)->count())->toBe(2)
            ->and(PublicationIdentityConflict::query()->where('user_id', $user->id)->count())->toBe(1)
            ->and(DB::table('publications')->where('user_id', $user->id)->count())->toBe(2);
    } finally {
        Event::forget('eloquent.updating: '.Publication::class);
        DB::purge('pgsql_race');
    }
});

it('retorna a observação existente em corrida sem abortar a transação', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Corrida real exige segunda conexão PostgreSQL.');
    }

    config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);
    DB::purge('pgsql_race');

    $raceUserId = DB::connection('pgsql_race')->table('users')->insertGetId([
        'name' => 'Race Observation',
        'email' => 'race-observation-'.uniqid().'@example.test',
        'password' => bcrypt('password'),
        'is_admin' => false,
        'can_use_ats_optimization' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $publicationId = DB::connection('pgsql_race')->table('publications')->insertGetId([
        'user_id' => $raceUserId,
        'source' => 'linkedin',
        'external_id' => 'urn:li:activity:obs-race',
        'url' => null,
        'canonical_url' => null,
        'canonical_url_hash' => null,
        'fingerprint' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pageCaptureId = DB::connection('pgsql_race')->table('page_captures')->insertGetId([
        'user_id' => $raceUserId,
        'source' => 'linkedin',
        'surface' => null,
        'url' => null,
        'html_path' => null,
        'captured_at' => now(),
        'legacy_job_capture_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $observationId = null;

    $this->beforeApplicationDestroyed(function () use ($raceUserId, $publicationId, $pageCaptureId, &$observationId): void {
        config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);
        DB::purge('pgsql_race');

        if ($observationId !== null) {
            DB::connection('pgsql_race')->table('publication_observations')->where('id', $observationId)->delete();
        }

        DB::connection('pgsql_race')->table('page_captures')->where('id', $pageCaptureId)->delete();
        DB::connection('pgsql_race')->table('publications')->where('id', $publicationId)->delete();
        DB::connection('pgsql_race')->table('users')->where('id', $raceUserId)->delete();
        DB::purge('pgsql_race');
    });

    $user = User::query()->findOrFail($raceUserId);
    $publication = Publication::query()->findOrFail($publicationId);
    $capture = PageCapture::query()->findOrFail($pageCaptureId);

    expect($capture->user_id)->toBe($user->id);

    $injected = false;

    PublicationObservation::creating(function () use ($capture, $publication, &$injected, &$observationId): void {
        if ($injected) {
            return;
        }

        $injected = true;

        $observationId = DB::connection('pgsql_race')->table('publication_observations')->insertGetId([
            'page_capture_id' => $capture->id,
            'publication_id' => $publication->id,
            'observed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        $observation = app(ObservationRecorder::class)->record($capture, $publication);

        expect($observation->id)->toBe($observationId)
            ->and(PublicationObservation::query()->where('page_capture_id', $capture->id)->count())->toBe(1)
            ->and(DB::table('publication_observations')->where('page_capture_id', $capture->id)->count())->toBe(1);
    } finally {
        Event::forget('eloquent.creating: '.PublicationObservation::class);
    }
});

it('retorna o conflito existente em corrida sem abortar a transação', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Corrida real exige segunda conexão PostgreSQL.');
    }

    config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);
    DB::purge('pgsql_race');

    $raceUserId = DB::connection('pgsql_race')->table('users')->insertGetId([
        'name' => 'Race Conflict',
        'email' => 'race-conflict-'.uniqid().'@example.test',
        'password' => bcrypt('password'),
        'is_admin' => false,
        'can_use_ats_optimization' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user = User::query()->findOrFail($raceUserId);
    $resolver = app(PublicationIdentityResolver::class);
    $resolver->resolve($user, 'linkedin', 'urn:li:activity:conflict-x', null);
    $resolver->resolve($user, 'linkedin', 'urn:li:activity:conflict-y', 'https://www.linkedin.com/feed/update/urn:li:activity:conflict-y/');

    $canonical = 'https://www.linkedin.com/feed/update/urn:li:activity:conflict-y';
    $identityHash = hash('sha256', implode('|', [$user->id, 'linkedin', 'urn:li:activity:conflict-x', $canonical]));
    $conflictId = null;
    $injected = false;

    $this->beforeApplicationDestroyed(function () use ($raceUserId, &$conflictId): void {
        config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);
        DB::purge('pgsql_race');

        if ($conflictId !== null) {
            DB::connection('pgsql_race')->table('publication_identity_conflicts')->where('id', $conflictId)->delete();
        }

        DB::connection('pgsql_race')->table('users')->where('id', $raceUserId)->delete();
        DB::purge('pgsql_race');
    });

    PublicationIdentityConflict::creating(function () use ($user, $identityHash, $canonical, &$injected, &$conflictId): void {
        if ($injected) {
            return;
        }

        $injected = true;

        $conflictId = DB::connection('pgsql_race')->table('publication_identity_conflicts')->insertGetId([
            'user_id' => $user->id,
            'source' => 'linkedin',
            'external_id' => 'urn:li:activity:conflict-x',
            'canonical_url' => $canonical,
            'identity_hash' => $identityHash,
            'publication_id' => null,
            'other_publication_id' => null,
            'reason' => 'external_id_url_mismatch',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        app(PublicationIdentityResolver::class)->resolve($user, 'linkedin', 'urn:li:activity:conflict-x', $canonical);

        expect(PublicationIdentityConflict::query()->where('identity_hash', $identityHash)->count())->toBe(1)
            ->and(PublicationIdentityConflict::query()->where('user_id', $user->id)->sole()->id)->toBe($conflictId)
            ->and(DB::table('publication_identity_conflicts')->where('identity_hash', $identityHash)->count())->toBe(1);
    } finally {
        Event::forget('eloquent.creating: '.PublicationIdentityConflict::class);
    }
});
