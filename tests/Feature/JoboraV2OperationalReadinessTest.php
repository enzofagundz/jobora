<?php

use App\Enums\AiOperationType;
use App\Enums\ResumeLanguage;
use App\Models\AiOperation;
use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\JobPosting;
use App\Models\OpportunitySource;
use App\Models\PageCapture;
use App\Models\ProfessionalProfile;
use App\Models\Publication;
use App\Models\PublicationClefResult;
use App\Models\PublicationObservation;
use App\Models\PublicationTriageResult;
use App\Models\Resume;
use App\Models\User;
use App\Services\CaptureTokenService;
use App\Services\Discovery\DiscoveryRunService;
use App\Services\Discovery\DiscoverySourceService;
use App\Services\DocumentArtifactService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\FakePdfBuilder;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;
use Tests\Feature\CaptureIngestion\CaptureEnvelopeFixture;

trait GuardsJoboraV2OperationalDatabase
{
    use RefreshDatabase;

    private ?string $originalPassportKeyPath = null;

    public function beforeRefreshingDatabase(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $database = $connection->getDatabaseName();
        $safeSqlite = $driver === 'sqlite'
            && dirname($database) === '/tmp/opencode'
            && realpath(dirname($database)) === '/tmp/opencode'
            && preg_match('/\Ajobora38(?:-[a-z0-9]+)*-testing\.sqlite\z/', basename($database)) === 1
            && is_file($database)
            && ! is_link($database)
            && realpath($database) === $database;
        $safePostgres = $driver === 'pgsql'
            && $database === 'jobora_t22_20261107_testing'
            && env('JOBORA_OPERATIONAL_PG_DATABASE') === $database;
        if (! app()->environment('testing') || (! $safeSqlite && ! $safePostgres)) {
            throw new RuntimeException('Readiness exige APP_ENV=testing e banco descartável exclusivo; RefreshDatabase bloqueado.');
        }
        if ($connection->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== $driver || ($safePostgres && $connection->selectOne('select current_database() as name', [], false)->name !== $database)) {
            throw new RuntimeException('Conexão efetiva difere do banco de teste reservado; RefreshDatabase bloqueado.');
        }
        if ($safeSqlite && $connection->selectOne('PRAGMA database_list')->file !== $database) {
            throw new RuntimeException('SQLite efetivo difere do arquivo descartável reservado; RefreshDatabase bloqueado.');
        }

        $root = getenv('JOBORA38_STORAGE_ROOT') ?: sys_get_temp_dir().'/opencode/jobora38-operational-'.Str::uuid();
        if (! str_starts_with($root, '/tmp/opencode/jobora38-operational-')) {
            throw new RuntimeException('Storage operacional exige root exclusivo em /tmp/opencode/jobora38-operational-*.');
        }
        app()->useStoragePath($root);
        File::ensureDirectoryExists($root.'/framework/testing');
        $this->originalPassportKeyPath = Passport::$keyPath;
        Passport::loadKeysFrom($root);
        $this->beforeApplicationDestroyed(function (): void {
            Passport::$keyPath = $this->originalPassportKeyPath;
        });
        config([
            'filesystems.disks.local.root' => $root.'/app/private',
            'queue.connections.database.connection' => DB::getDefaultConnection(),
            'queue.connections.database.table' => 'jobs',
            'queue.connections.database.queue' => 'default',
            'queue.failed.database' => DB::getDefaultConnection(),
            'queue.batching.database' => DB::getDefaultConnection(),
        ]);
    }
}

uses(GuardsJoboraV2OperationalDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    config(['capture.ingestion.disk' => 'local', 'capture.ingestion.path' => 'capture-envelopes']);
});

it('poda somente bruto vencido e reconcilia órfãos sem apagar resultados ou histórico', function () {
    $this->travelTo(now()->startOfSecond());
    $owner = User::factory()->create();
    $posting = JobPosting::factory()->for($owner)->confirmed()->create();
    $application = Application::factory()->for($posting)->create();
    ApplicationEvent::factory()->for($application)->create(['notes' => 'Histórico que deve sobreviver à retenção.']);
    $publication = Publication::factory()->for($owner)->create(['content' => 'Publicação estruturada preservada', 'content_version' => 1]);
    PublicationClefResult::create([
        'publication_id' => $publication->id, 'content_version' => 1, 'attempt' => 1,
        'status' => 'completed', 'probability' => 0.9, 'classified_at' => now(),
    ]);
    $operation = AiOperation::create([
        'user_id' => $owner->id, 'operation_key' => 'retention:'.Str::uuid(), 'operation_type' => AiOperationType::PublicationTriage,
        'target_type' => 'publication', 'target_id' => $publication->id, 'status' => 'completed', 'queued_at' => now(),
        'run_token' => (string) Str::uuid(), 'attempt_token' => (string) Str::uuid(), 'expires_at' => now()->addMinutes(5),
        'input_fingerprint' => str_repeat('a', 64), 'input_snapshot' => ['publication_id' => $publication->id, 'content_version' => 1],
    ]);
    $result = PublicationTriageResult::create([
        'ai_operation_id' => $operation->id, 'publication_id' => $publication->id, 'content_version' => 1,
        'run_token' => $operation->run_token, 'attempt_token' => $operation->attempt_token, 'attempt' => 1,
        'status' => 'completed', 'provider' => 'synthetic', 'model' => 'synthetic', 'schema_version' => '1',
        'input_fingerprint' => str_repeat('a', 64), 'output' => ['summary' => 'Resultado preservado'],
    ]);
    OpportunitySource::factory()->create([
        'job_posting_id' => $posting->id, 'publication_id' => $publication->id, 'publication_triage_result_id' => $result->id,
    ]);

    $expired = PageCapture::factory()->for($owner)->create(['html_path' => "capture-envelopes/{$owner->id}/expired.html", 'raw_expires_at' => now()]);
    $current = PageCapture::factory()->for($owner)->create(['html_path' => "capture-envelopes/{$owner->id}/current.html", 'raw_expires_at' => now()->addSecond()]);
    $legacy = PageCapture::factory()->for($owner)->create(['html_path' => "capture-envelopes/{$owner->id}/legacy.html", 'raw_expires_at' => null]);
    $missing = PageCapture::factory()->for($owner)->create(['html_path' => "capture-envelopes/{$owner->id}/missing.html", 'raw_expires_at' => now()->addSecond()]);
    PublicationObservation::factory()->create(['page_capture_id' => $expired->id, 'publication_id' => $publication->id]);
    foreach ([$expired, $current, $legacy] as $capture) {
        Storage::disk('local')->put($capture->html_path, '<main>Bruto sintético</main>');
    }
    $oldOrphan = 'capture-envelopes/orphan-old.html';
    $newOrphan = 'capture-envelopes/orphan-new.html';
    foreach ([$oldOrphan, $newOrphan] as $path) {
        Storage::disk('local')->put($path, '<main>Órfão sintético</main>');
    }
    touch(Storage::disk('local')->path($oldOrphan), now()->subDay()->timestamp);
    touch(Storage::disk('local')->path($newOrphan), now()->timestamp);

    $tables = ['publications', 'publication_clef_results', 'publication_triage_results', 'ai_operations', 'publication_observations', 'opportunity_sources', 'job_postings', 'applications', 'application_events'];
    $snapshot = [];
    foreach ($tables as $table) {
        $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }
    $expiredPath = $expired->html_path;
    $this->artisan('captures:prune-raw')->expectsOutput('Capturas expiradas: 1')->assertSuccessful();
    Storage::disk('local')->assertMissing($expiredPath);
    Storage::disk('local')->assertExists([$current->html_path, $legacy->html_path]);
    expect($expired->refresh()->html_path)->toBeNull()
        ->and($expired->raw_deleted_at->equalTo(now()))->toBeTrue();

    $this->artisan('captures:reconcile-orphans')->assertSuccessful();
    Storage::disk('local')->assertMissing($oldOrphan);
    Storage::disk('local')->assertExists($newOrphan);
    expect($missing->refresh()->html_path)->not->toBeNull();
    $this->travel(1)->seconds();
    $this->artisan('captures:reconcile-orphans')->assertSuccessful();
    expect($missing->refresh()->html_path)->toBeNull()
        ->and($missing->raw_deleted_at)->not->toBeNull()
        ->and($legacy->refresh()->html_path)->not->toBeNull()
        ->and($legacy->raw_deleted_at)->toBeNull();
    foreach ($snapshot as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($rows);
    }
    expect(PageCapture::count())->toBe(4);
    Http::assertNothingSent();
});

it('nega download depois da perda de acesso sem apagar o PDF ou o histórico', function (string $change, int $status) {
    $this->freezeTime();
    config(['app.url' => 'https://localhost']);
    Pdf::swap(new class extends FakePdfBuilder
    {
        public function save(string $path): self
        {
            parent::save($path);
            file_put_contents($path, '%PDF-synthetic-operational');

            return $this;
        }
    });
    $owner = User::factory()->create();
    $client = Helpers::client();
    $integration = Helpers::integration($owner, $client);
    $token = Helpers::accessToken($owner, $client);
    $posting = JobPosting::factory()->for($owner)->confirmed()->create();
    $application = Application::factory()->for($posting)->create();
    $event = ApplicationEvent::factory()->for($application)->create();
    $application->refresh();
    $event->refresh();
    $service = app(DocumentArtifactService::class);
    $artifact = $service->render($owner, ['adapted_content' => 'Perfil sintético PHP'], ResumeLanguage::PtBr);
    $artifact->save();
    $delivery = $service->deliver($owner, $artifact);
    app('auth')->forgetGuards();
    $bytes = $this->withToken($token)->get($delivery['download_url'])->assertOk()->streamedContent();
    expect(hash('sha256', $bytes))->toBe($delivery['sha256'])
        ->and($delivery)->not->toHaveKey('path');

    match ($change) {
        'expired_link' => $this->travel(16)->minutes(),
        'expired_artifact' => DB::table('document_artifacts')->where('id', $artifact->id)->update(['expires_at' => now()->subSecond()]),
        'revoked_artifact' => $service->revoke($owner, $artifact->id),
        'revoked_integration' => $integration->update(['revoked_at' => now()]),
        'other_owner' => null,
        'anonymous' => $token = '',
    };
    if ($change === 'other_owner') {
        $other = User::factory()->create();
        $otherClient = Helpers::client();
        Helpers::integration($other, $otherClient);
        $token = Helpers::accessToken($other, $otherClient);
    }
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson($delivery['download_url'])->assertStatus($status);
    expect(Storage::disk('local')->get($artifact->path))->toBe($bytes)
        ->and($application->fresh()->getAttributes())->toBe($application->getAttributes())
        ->and($event->fresh()->getAttributes())->toBe($event->getAttributes());
    Http::assertNothingSent();
})->with([
    ['expired_link', 403], ['expired_artifact', 410], ['revoked_artifact', 410],
    ['revoked_integration', 403], ['other_owner', 404], ['anonymous', 401],
]);

it('suspende novas reservas e revoga captura sem destruir a fonte ou a captura anterior', function () {
    $owner = User::factory()->create();
    $service = app(DiscoverySourceService::class);
    $source = $service->create($owner, [
        'name' => 'Fonte sintética', 'source' => 'linkedin', 'surface' => 'linkedin_publications',
        'url' => 'https://www.linkedin.com/search/results/content/?keywords=php', 'frequency_minutes' => 60,
    ]);
    $credentials = app(CaptureTokenService::class);
    $token = $credentials->issueToken($owner);
    $this->withToken($token)->postJson('/api/page-captures', CaptureEnvelopeFixture::make('before-suspension'))->assertCreated();
    $capture = PageCapture::sole();
    $path = $capture->html_path;
    $bytes = Storage::disk('local')->get($path);
    expect(app(DiscoveryRunService::class)->pending($owner)->total())->toBe(1);
    $service->deactivate($owner, $source->id, $source->version);
    expect(app(DiscoveryRunService::class)->pending($owner)->total())->toBe(0)
        ->and(app(DiscoveryRunService::class)->acquire($owner, null, 'after-suspension'))->toHaveCount(0);
    $credentials->revokeToken($owner);
    foreach (['before-suspension', 'after-revocation'] as $key) {
        $this->withToken($token)->postJson('/api/page-captures', CaptureEnvelopeFixture::make($key))->assertUnauthorized();
    }
    expect($source->fresh()->active)->toBeFalse()
        ->and($capture->fresh()->getAttributes())->toBe($capture->getAttributes())
        ->and(Storage::disk('local')->get($path))->toBe($bytes);
    $this->assertDatabaseCount('page_captures', 1);
    $this->assertDatabaseCount('discovery_runs', 0);
    Http::assertNothingSent();
});

it('bloqueia efeitos do worker pendente após revogar integração e preserva movimentações', function () {
    config([
        'app.url' => 'https://localhost', 'queue.default' => 'database',
        'queue.connections.database.connection' => DB::getDefaultConnection(),
        'queue.connections.database.queue' => 'default',
    ]);
    $owner = User::factory()->create();
    $client = Helpers::client();
    $integration = Helpers::integration($owner, $client);
    $token = Helpers::accessToken($owner, $client);
    ProfessionalProfile::factory()->create([
        'user_id' => $owner->id, 'version' => 1, 'confirmed_version' => 1,
        'confirmed_snapshot' => ['competencies' => ['PHP']], 'draft' => ['competencies' => ['PHP']],
    ]);
    Resume::factory()->for($owner)->create(['language' => 'pt-BR', 'content' => 'Perfil sintético PHP']);
    $posting = JobPosting::factory()->for($owner)->create(['readiness' => 'ready', 'opportunity_version' => 1]);
    $application = Application::factory()->for($posting)->create();
    $event = ApplicationEvent::factory()->for($application)->create();
    $event->refresh();
    $request = Helpers::decodeToolResult($this->withToken($token)->postJson('/mcp', Helpers::toolCall('prepare_application', [
        'job_posting_id' => $posting->id, 'expected_opportunity_version' => 1, 'expected_profile_confirmed_version' => 1,
        'destination' => ['type' => 'email', 'email' => 'synthetic@example.com'],
    ]))->assertOk()->getContent());
    expect($request['ok'])->toBeTrue()
        ->and($request['data']['operation_ids'])->toHaveCount(1);
    $operation = AiOperation::findOrFail($request['data']['operation_ids'][0]);
    expect($operation->status->value)->toBe('queued');
    $this->assertDatabaseCount('jobs', 1);
    $integration->update(['revoked_at' => now()]);
    $workerException = null;
    Event::listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event) use (&$workerException): void {
        $workerException = $event->exception;
    });
    expect(Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 1]))->toBe(0);
    expect($workerException)->toBeInstanceOf(AuthorizationException::class)
        ->and($operation->refresh()->status->value)->toBe('queued')
        ->and($operation->failure_code)->toBe('retrying')
        ->and($event->fresh()->getAttributes())->toBe($event->getAttributes());
    $this->assertDatabaseCount('application_events', 1);
    $this->assertDatabaseCount('applications', 1);
    $this->assertDatabaseCount('resume_adaptations', 0);
    $this->assertDatabaseCount('document_artifacts', 0);
    $this->assertDatabaseCount('application_submission_attempts', 0);
    $this->assertDatabaseCount('jobs', 1);
    expect(DB::table('jobs')->sole()->attempts)->toBe(1);
    Http::assertNothingSent();
});
