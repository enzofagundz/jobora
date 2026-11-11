<?php

use App\Jobs\ClassifyPublicationWithClef;
use App\Jobs\ProcessAiOperation;
use App\Models\Publication;
use App\Models\PublicationClefResult;
use App\Models\User;
use App\Services\AiOperationService;
use App\Services\CaptureTokenService;
use App\Services\Triage\JobPrefilter;
use App\Services\Triage\PrefilterPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('does not call Clef for an already queued job when prefilter is disabled', function () {
    config(['triage.prefilter.enabled' => false]);
    $publication = Publication::factory()->create();
    $result = PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 1,
        'attempt' => 1,
        'status' => 'pending',
    ]);
    $prefilter = Mockery::mock(JobPrefilter::class);
    $prefilter->shouldNotReceive('configuredModel');
    $prefilter->shouldNotReceive('classify');

    (new ClassifyPublicationWithClef($result->id))->handle($prefilter, app(PrefilterPolicy::class));

    expect($result->refresh()->status)->toBe('pending');
});

it('queues publication triage without a Clef decision when prefilter is disabled', function () {
    config(['triage.prefilter.enabled' => false]);
    Queue::fake();
    $user = User::factory()->create();
    $content = 'Vaga para desenvolvimento PHP.';
    $publication = Publication::factory()->for($user)->create([
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
    ]);

    $operation = app(AiOperationService::class)->enqueuePublicationTriage($user, $publication->id, 1);

    expect($operation->input_snapshot['clef_result_id'])->toBeNull();
    Queue::assertPushed(ProcessAiOperation::class);
});

it('does not create or queue Clef while extracting with prefilter disabled', function () {
    config(['triage.prefilter.enabled' => false]);
    Storage::fake('local');
    Queue::fake([ClassifyPublicationWithClef::class]);
    $user = User::factory()->create();
    $payload = json_decode(file_get_contents(base_path('fixtures/capture-envelope/v1/indeed-jobs.json')), true, flags: JSON_THROW_ON_ERROR);
    $payload['capture']['execution_id'] = null;
    $payload['capture']['captured_at'] = now()->toIso8601String();

    $this->withToken(app(CaptureTokenService::class)->issueToken($user))
        ->postJson('/api/page-captures', $payload)
        ->assertCreated();

    expect(Publication::query()->count())->toBe(1)
        ->and(PublicationClefResult::query()->count())->toBe(0);
    Queue::assertNotPushed(ClassifyPublicationWithClef::class);
});

it('keeps Clef gating and explicit bypass audit when enabled', function () {
    config(['triage.prefilter.enabled' => true]);
    Queue::fake();
    $user = User::factory()->create();
    $content = 'Vaga para desenvolvimento PHP.';
    $publication = Publication::factory()->for($user)->create([
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
    ]);

    expect(fn () => app(AiOperationService::class)->enqueuePublicationTriage($user, $publication->id, 1))
        ->toThrow(LogicException::class, 'A decisão Clef atual não permite solicitar triagem.');

    $result = PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 1,
        'attempt' => 1,
        'status' => 'barred',
    ]);
    expect(fn () => app(AiOperationService::class)->enqueuePublicationTriage($user, $publication->id, 1))
        ->toThrow(LogicException::class, 'A publicação não está elegível segundo a decisão Clef atual.');

    $operation = app(AiOperationService::class)->enqueuePublicationTriage($user, $publication->id, 1, bypassClef: true, reason: 'Revisão humana');

    expect($operation->input_snapshot)->toMatchArray([
        'clef_result_id' => $result->id,
        'clef_bypassed' => true,
        'clef_bypass_reason' => 'Revisão humana',
    ]);

    config(['triage.prefilter.enabled' => false]);
    expect(fn () => app(AiOperationService::class)->enqueuePublicationTriage($user, $publication->id, 2))
        ->toThrow(LogicException::class, 'A versão informada não é a versão atual da publicação.');
});
