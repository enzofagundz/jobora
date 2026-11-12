<?php

use App\Models\JobCapture;
use App\Models\JobPosting;
use App\Models\OpportunitySource;
use App\Models\PageCapture;
use App\Models\Publication;
use App\Models\Resume;
use App\Models\ResumeAdaptation;
use App\Models\User;
use App\Services\Discovery\LegacyDomainBackfill;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('preserva duas contas V1 durante a cadeia de migrations e o backfill repetido', function () {
    if (! app()->environment('testing')) {
        throw new RuntimeException('Upgrade sintético exige APP_ENV=testing; nenhuma migration foi executada.');
    }

    $postgresDatabase = env('JOBORA_UPGRADE_PG_DATABASE');
    $postgresConfig = config('database.connections.pgsql');
    if ($postgresDatabase !== null && ($postgresDatabase !== 'jobora_t24_upgrade_20261107_testing' || ($postgresConfig['driver'] ?? null) !== 'pgsql')) {
        throw new RuntimeException('Upgrade PostgreSQL exige driver pgsql e banco exclusivo jobora_t24_upgrade_20261107_testing; nenhuma conexão foi aberta.');
    }

    $original = DB::getDefaultConnection();
    $connection = 'jobora38_legacy_upgrade';
    $originalStorage = storage_path();
    app()->useStoragePath('/tmp/opencode/jobora38-operational-upgrade-'.Str::uuid());
    $directory = storage_path('framework/testing');
    File::ensureDirectoryExists($directory);
    $database = $postgresDatabase ?? tempnam($directory, 'jobora38-upgrade-');
    $restoredDatabase = null;
    $restoredConnection = $connection.'_restored';
    $driver = $postgresDatabase !== null ? 'pgsql' : 'sqlite';
    config([
        "database.connections.{$connection}" => $postgresDatabase !== null
            ? [...$postgresConfig, 'database' => $database, 'url' => null]
            : ['driver' => 'sqlite', 'database' => $database, 'foreign_key_constraints' => true],
        'capture.api_token' => '',
    ]);

    try {
        DB::setDefaultConnection($connection);
        Schema::clearResolvedInstance('db.schema');
        if (DB::connection()->getDriverName() !== $driver || DB::connection()->getDatabaseName() !== $database || DB::connection()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== $driver) {
            throw new RuntimeException('Conexão descartável do upgrade não corresponde ao banco reservado.');
        }
        if ($postgresDatabase !== null && DB::connection()->selectOne('select current_database() as name', [], false)->name !== $database) {
            throw new RuntimeException('PostgreSQL efetivo difere do banco exclusivo reservado; nenhuma migration foi executada.');
        }
        if (DB::connection($connection)->getSchemaBuilder()->getTables() !== []) {
            throw new RuntimeException('Banco exclusivo do upgrade contém tabelas preexistentes; nenhuma migration foi executada.');
        }

        $paths = collect(File::glob(database_path('migrations/*.php')))->sort()->values();
        $beforeV2 = $paths->filter(fn (string $path): bool => basename($path) < '2026_11_04_200001_create_discovery_domain_tables.php')->all();
        expect(Artisan::call('migrate', ['--database' => $connection, '--path' => $beforeV2, '--realpath' => true, '--force' => true]))->toBe(0);
        expect(Schema::hasTable('page_captures'))->toBeFalse();

        $owners = User::factory()->count(2)->create();
        $promotedIds = [];
        $deletedIds = [];
        foreach ($owners as $index => $owner) {
            $posting = JobPosting::factory()->for($owner, 'user')->confirmed()->create([
                'description' => "Descrição V1 da conta {$index}: PHP e Laravel.",
                'role' => "Cargo {$index}",
            ]);
            $posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'user']);
            $posting->applicationMethods()->create([
                'type' => 'email', 'email' => "jobs{$index}@example.com", 'source' => 'user', 'dedup_key' => "email-{$index}",
            ]);
            foreach (['pt-BR', 'en'] as $language) {
                Resume::factory()->for($owner)->create(['language' => $language, 'content' => "Perfil {$index} {$language}: PHP"]);
                ResumeAdaptation::create([
                    'job_posting_id' => $posting->id, 'language' => $language,
                    'adapted_content' => "Adequação {$index} {$language}", 'changes' => ['Ordem'], 'alerts' => [],
                ]);
            }
            $applicationId = DB::table('applications')->insertGetId([
                'job_posting_id' => $posting->id, 'status' => 'submitted', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('application_events')->insert([
                'application_id' => $applicationId, 'status' => 'submitted', 'title' => 'Envio registrado no V1',
                'notes' => "Histórico {$index}", 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $promotedIds[] = $owner->jobCaptures()->create([
                'source' => 'linkedin', 'external_id' => 'urn:li:activity:legacy-shared',
                'dedup_key' => 'ext:legacy-shared', 'description' => "Publicação preservada {$index}",
                'job_posting_id' => $posting->id, 'captured_at' => now(),
            ])->id;
            foreach (['from=serp', 'utm_campaign=second'] as $tracking) {
                $owner->jobCaptures()->create([
                    'source' => 'indeed', 'url' => 'https://br.indeed.com/viewjob?jk=legacy123&'.$tracking,
                    'dedup_key' => 'url:'.$tracking, 'description' => "Indeed {$index}", 'captured_at' => now(),
                ]);
            }
            $deleted = $owner->jobCaptures()->create([
                'source' => 'linkedin', 'dedup_key' => 'fingerprint:deleted', 'description' => 'Excluída no V1', 'captured_at' => now(),
            ]);
            $deleted->delete();
            $deletedIds[] = $deleted->id;
        }

        $tables = ['users', 'job_postings', 'job_posting_items', 'application_methods', 'resumes', 'resume_adaptations', 'applications', 'application_events', 'job_captures'];
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = ['columns' => Schema::getColumnListing($table), 'rows' => DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all()];
        }

        expect(Artisan::call('migrate', ['--database' => $connection, '--force' => true]))->toBe(0);
        $first = app(LegacyDomainBackfill::class)->run();
        $second = app(LegacyDomainBackfill::class)->run();

        expect($first)->toMatchArray(['page_captures' => 8, 'publications' => 6, 'observations' => 8, 'opportunity_sources' => 2, 'failed' => []])
            ->and($second)->toMatchArray(['page_captures' => 0, 'publications' => 0, 'observations' => 0, 'opportunity_sources' => 0, 'skipped' => 8, 'failed' => []]);
        foreach ($snapshot as $table => $state) {
            expect(DB::table($table)->select($state['columns'])->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($state['rows']);
        }

        foreach ($owners as $index => $owner) {
            expect(PageCapture::withTrashed()->where('user_id', $owner->id)->count())->toBe(4)
                ->and(Publication::where('user_id', $owner->id)->count())->toBe(3);
            $publication = Publication::where('user_id', $owner->id)->where('source', 'indeed')->sole();
            expect($publication->canonical_url)->toBe('https://br.indeed.com/viewjob?jk=legacy123')
                ->and($publication->observations()->count())->toBe(2)
                ->and(PageCapture::withTrashed()->where('legacy_job_capture_id', $deletedIds[$index])->sole()->trashed())->toBeTrue();
            $promoted = JobCapture::findOrFail($promotedIds[$index]);
            $source = OpportunitySource::where('job_posting_id', $promoted->job_posting_id)->sole();
            expect($source->is_legacy)->toBeTrue()
                ->and($source->publication->user_id)->toBe($owner->id)
                ->and($promoted->jobPosting->readiness)->toBeNull();
        }

        foreach (['application_approvals', 'application_preparations', 'application_submission_attempts', 'document_artifacts', 'professional_profiles', 'matching_evaluations'] as $table) {
            expect(DB::table($table)->count())->toBe(0);
        }

        if ($postgresDatabase === null) {
            $v2Snapshot = [];
            foreach (['page_captures', 'publications', 'publication_observations', 'opportunity_sources'] as $table) {
                $v2Snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
            }
            $restoredDatabase = tempnam($directory, 'jobora38-restored-');
            $source = new SQLite3($database, SQLITE3_OPEN_READONLY);
            $backup = new SQLite3($restoredDatabase, SQLITE3_OPEN_READWRITE);
            try {
                expect($source->backup($backup))->toBeTrue();
                expect($backup->querySingle('PRAGMA integrity_check'))->toBe('ok');
            } finally {
                $backup->close();
                $source->close();
            }

            config(["database.connections.{$restoredConnection}" => [
                'driver' => 'sqlite', 'database' => $restoredDatabase, 'foreign_key_constraints' => true,
            ]]);
            DB::setDefaultConnection($restoredConnection);
            Schema::clearResolvedInstance('db.schema');
            $restored = DB::connection($restoredConnection);
            if ($restored->getDriverName() !== 'sqlite' || $restored->getDatabaseName() !== $restoredDatabase || $restored->selectOne('PRAGMA database_list')->file !== realpath($restoredDatabase)) {
                throw new RuntimeException('Restore SQLite não corresponde ao segundo arquivo exclusivo reservado.');
            }
            expect($restored->select('PRAGMA foreign_key_check'))->toBe([]);
            foreach ($snapshot as $table => $state) {
                expect($restored->table($table)->select($state['columns'])->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($state['rows']);
            }
            foreach ($v2Snapshot as $table => $rows) {
                expect($restored->table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($rows);
            }
            foreach ($owners as $owner) {
                expect(PageCapture::withTrashed()->where('user_id', $owner->id)->count())->toBe(4)
                    ->and(Publication::where('user_id', $owner->id)->count())->toBe(3);
            }
            foreach (['application_approvals', 'application_preparations', 'application_submission_attempts', 'document_artifacts', 'professional_profiles', 'matching_evaluations'] as $table) {
                expect($restored->table($table)->count())->toBe(0);
            }
        }
    } finally {
        app('migrator')->setConnection($original);
        DB::setDefaultConnection($original);
        Schema::clearResolvedInstance('db.schema');
        DB::purge($connection);
        if ($restoredDatabase !== null) {
            DB::purge($restoredConnection);
            File::delete($restoredDatabase);
        }
        if ($postgresDatabase === null) {
            File::delete($database);
        }
        app()->useStoragePath($originalStorage);
    }
});
