<?php

use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Jobs\ProcessAiOperation;
use App\Models\AiOperation;
use App\Models\Publication;
use App\Models\PublicationClefResult;
use App\Models\PublicationTriageResult;
use App\Models\User;
use App\Services\AiOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('recupera apenas operações de triagem já solicitadas', function () {
    Queue::fake();
    $user = User::factory()->create();
    $requested = Publication::factory()->for($user)->create([
        'content' => 'Requested publication content.',
        'content_hash' => hash('sha256', 'Requested publication content.'),
        'content_version' => 1,
    ]);
    $eligibleOnly = Publication::factory()->for($user)->create([
        'content' => 'Eligible but never requested.',
        'content_hash' => hash('sha256', 'Eligible but never requested.'),
        'content_version' => 1,
    ]);
    $staleRequested = Publication::factory()->for($user)->create([
        'content' => 'Previously running requested publication.',
        'content_hash' => hash('sha256', 'Previously running requested publication.'),
        'content_version' => 1,
    ]);
    foreach ([$requested, $eligibleOnly, $staleRequested] as $publication) {
        PublicationClefResult::query()->create([
            'publication_id' => $publication->id,
            'content_version' => 1,
            'attempt' => 1,
            'status' => 'eligible',
        ]);
    }
    $runToken = (string) Str::uuid();
    $attemptToken = (string) Str::uuid();
    $operation = AiOperation::query()->create([
        'user_id' => $user->id,
        'operation_key' => "user:{$user->id}:publication_triage:publication:{$requested->id}:version:1",
        'operation_type' => AiOperationType::PublicationTriage,
        'target_type' => 'publication',
        'target_id' => $requested->id,
        'run_token' => $runToken,
        'attempt_token' => $attemptToken,
        'status' => AiOperationStatus::Queued,
        'attempts' => 0,
        'queued_at' => now()->subMinutes(2),
        'expires_at' => now()->addMinutes(3),
        'input_snapshot' => ['publication_id' => $requested->id],
        'input_fingerprint' => hash('sha256', 'snapshot'),
    ]);
    $staleRunToken = (string) Str::uuid();
    AiOperation::query()->create([
        'user_id' => $user->id,
        'operation_key' => "user:{$user->id}:publication_triage:publication:{$staleRequested->id}:version:1",
        'operation_type' => AiOperationType::PublicationTriage,
        'target_type' => 'publication',
        'target_id' => $staleRequested->id,
        'run_token' => $staleRunToken,
        'attempt_token' => (string) Str::uuid(),
        'status' => AiOperationStatus::Running,
        'attempts' => 1,
        'queued_at' => now()->subMinutes(3),
        'started_at' => now()->subMinutes(3),
        'last_attempt_started_at' => now()->subMinutes(3),
        'expires_at' => now()->addMinutes(3),
        'input_snapshot' => ['publication_id' => $staleRequested->id],
        'input_fingerprint' => hash('sha256', 'stale-snapshot'),
    ]);

    $this->artisan('publication-triages:recover')->assertSuccessful();

    Queue::assertPushed(ProcessAiOperation::class, fn (ProcessAiOperation $job): bool => $job->operationId === $operation->id
        && $job->runToken === $runToken
        && $job->attemptToken === $attemptToken);
    Queue::assertPushed(ProcessAiOperation::class, fn (ProcessAiOperation $job): bool => $job->runToken === $staleRunToken
        && $job->attemptToken === null);
    expect(AiOperation::query()->count())->toBe(2)
        ->and(Publication::query()->count())->toBe(3);
    expect((new ProcessAiOperation($operation->id, $runToken, $attemptToken))->uniqueFor())->toBe(300);
});

it('registra resultado sanitizado quando operação solicitada expira sem worker', function () {
    $user = User::factory()->create();
    $publication = Publication::factory()->for($user)->create([
        'content' => 'Expired publication content.',
        'content_hash' => hash('sha256', 'Expired publication content.'),
        'content_version' => 1,
    ]);
    $operation = AiOperation::query()->create([
        'user_id' => $user->id,
        'operation_key' => "user:{$user->id}:publication_triage:publication:{$publication->id}:version:1",
        'operation_type' => AiOperationType::PublicationTriage,
        'target_type' => 'publication',
        'target_id' => $publication->id,
        'run_token' => (string) Str::uuid(),
        'attempt_token' => (string) Str::uuid(),
        'status' => AiOperationStatus::Queued,
        'attempts' => 0,
        'queued_at' => now()->subMinutes(6),
        'expires_at' => now()->subMinute(),
        'input_snapshot' => [
            'publication_id' => $publication->id,
            'content_version' => 1,
            'provider' => 'opencode_zen',
            'model' => 'deepseek-v4.1-flash-free',
            'schema_version' => 'publication-triage-v1',
        ],
        'input_fingerprint' => hash('sha256', 'snapshot'),
    ]);

    expect(app(AiOperationService::class)->expire($operation->id, $operation->run_token))->toBeTrue()
        ->and($operation->fresh()->failure_code)->toBe('worker_unavailable')
        ->and(PublicationTriageResult::query()->sole()->error_code)->toBe('worker_unavailable');
});
