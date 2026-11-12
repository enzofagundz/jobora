<?php

use App\Models\Application;
use App\Models\ApplicationPreparation;
use App\Models\DocumentArtifact;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\DocumentArtifactService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function pruningArtifact(User $user, array $attributes = []): DocumentArtifact
{
    $artifact = DocumentArtifact::query()->create([
        'user_id' => $user->id, 'type' => 'resume', 'language' => 'pt-BR',
        'path' => 'documents/'.$user->id.'/'.Str::uuid().'.pdf',
        'sha256' => hash('sha256', 'pdf'), 'mime_type' => 'application/pdf', 'bytes' => 3,
        'expires_at' => now()->subMinute(), ...$attributes,
    ]);
    Storage::disk('local')->put($artifact->path, 'pdf');

    return $artifact;
}

it('bounds pruning and preserves preparation audit metadata without starving the backlog', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $application = Application::factory()->create(['job_posting_id' => JobPosting::factory()->for($user)]);
    $preparation = ApplicationPreparation::query()->create([
        'user_id' => $user->id, 'application_id' => $application->id, 'language' => 'pt-BR',
        'schema_version' => '1', 'state' => 'awaiting_approval', 'snapshot' => [], 'pending' => [],
        'source_versions' => [], 'source_hash' => str_repeat('a', 64), 'fingerprint' => str_repeat('b', 64),
    ]);
    $linked = pruningArtifact($user, ['preparation_id' => $preparation->id, 'updated_at' => now()->subDays(2)]);
    $expired = pruningArtifact($user, ['updated_at' => now()->subDay()]);
    $fresh = pruningArtifact($user, ['expires_at' => now()->addHour()]);
    $this->artisan('document-artifacts:prune', ['--limit' => 1])->assertSuccessful();
    Storage::disk('local')->assertMissing($linked->path);
    Storage::disk('local')->assertExists($expired->path);
    expect($linked->fresh()->sha256)->toBe($linked->sha256)
        ->and($linked->fresh()->revoked_at)->not->toBeNull();
    $this->artisan('document-artifacts:prune', ['--limit' => 1])->assertSuccessful();
    expect($expired->fresh())->toBeNull()->and($preparation->fresh())->not->toBeNull();
    Storage::disk('local')->assertMissing($expired->path);
    Storage::disk('local')->assertExists($fresh->path);
});

it('removes revoked PDFs immediately and prunes their standalone metadata', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $artifact = pruningArtifact($user, ['expires_at' => now()->addHour()]);
    $service = app(DocumentArtifactService::class);
    $service->revoke($user, $artifact->id);
    Storage::disk('local')->assertMissing($artifact->path);
    expect($artifact->fresh()->revoked_at)->not->toBeNull();
    expect($service->pruneExpired())->toBe(1)->and($artifact->fresh())->toBeNull();
});

it('retains metadata when PDF deletion fails and refuses unrelated paths', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $artifact = pruningArtifact($user);
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->with($artifact->path)->once()->andReturnTrue();
    $disk->shouldReceive('delete')->with($artifact->path)->once()->andReturnFalse();
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);
    expect(fn () => app(DocumentArtifactService::class)->pruneExpired())->toThrow(LogicException::class)
        ->and($artifact->fresh())->not->toBeNull();
});

it('rejects pruning paths outside the artifact owner directory', function () {
    Storage::fake('local');
    $artifact = pruningArtifact(User::factory()->create(), ['path' => 'private/keep.pdf']);
    expect(fn () => app(DocumentArtifactService::class)->pruneExpired())->toThrow(LogicException::class);
    Storage::disk('local')->assertExists($artifact->path);
});
