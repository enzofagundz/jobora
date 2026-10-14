<?php

use App\Agents\AtsScorerAgent;
use App\Agents\ResumeAdaptationAgent;
use App\Agents\ResumeTranslationAgent;
use App\Agents\StrategicCompetencyAgent;
use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Enums\ResumeLanguage;
use App\Filament\Pages\ProfessionalCompetenciesPage;
use App\Filament\Resources\JobPostings\Pages\ApplicationPage;
use App\Jobs\ProcessAiOperation;
use App\Models\AiOperation;
use App\Models\JobPosting;
use App\Models\ProfessionalCompetency;
use App\Models\ResumeAdaptation;
use App\Models\User;
use App\Services\AiInputSnapshotService;
use App\Services\AiOperationService;
use App\Services\ResumeService;
use App\Support\Staleness;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('enqueues resume adaptation without calling the provider and stores the trigger snapshot', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Vaga inicial para PHP e Laravel.',
        'role' => 'Desenvolvedor PHP',
        'company' => 'Acme',
    ]);
    $posting->items()->create([
        'category' => 'skill',
        'name' => 'PHP',
        'source' => 'ai',
    ]);
    app(ResumeService::class)->save($user, 'Currículo inicial com PHP.', ResumeLanguage::PtBr);

    Livewire::actingAs($user)
        ->test(ApplicationPage::class, ['record' => $posting->id])
        ->callAction('adaptPtBr')
        ->assertSuccessful()
        ->assertNotified();

    $operation = AiOperation::query()->sole();

    expect($operation->operation_type)->toBe(AiOperationType::ResumeAdaptation)
        ->and($operation->status)->toBe(AiOperationStatus::Queued)
        ->and($operation->input_snapshot['resume'])->toBe('Currículo inicial com PHP.')
        ->and($operation->input_snapshot['job_description'])->toBe('Vaga inicial para PHP e Laravel.');

    Queue::assertPushed(ProcessAiOperation::class, function (ProcessAiOperation $job) use ($operation): bool {
        return $job->operationId === $operation->id
            && $job->runToken === $operation->run_token;
    });

    expect($operation->input_fingerprint)->toHaveLength(64);

    expect(DB::table('ai_operations')->where('id', $operation->id)->value('input_snapshot'))
        ->not->toContain('Currículo inicial com PHP.');
});

it('does not enqueue a duplicate adaptation while the current operation is active', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Vaga para PHP e Laravel.',
    ]);
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);

    $component = Livewire::actingAs($user)
        ->test(ApplicationPage::class, ['record' => $posting->id])
        ->callAction('adaptPtBr')
        ->assertSuccessful();

    $component
        ->callAction('adaptPtBr')
        ->assertSuccessful();

    expect(AiOperation::query()->count())->toBe(1);
    Queue::assertPushedTimes(ProcessAiOperation::class, 1);
});

it('marks the operation failed when the queue cannot accept the job', function () {
    $dispatches = [];
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')
        ->andReturnUsing(function (mixed $command) use (&$dispatches): mixed {
            $dispatches[] = $command;

            if ($command instanceof ProcessAiOperation) {
                throw new RuntimeException('fila indisponível');
            }

            return null;
        });
    $this->app->instance(Dispatcher::class, $dispatcher);

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Vaga para PHP.',
    ]);
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    Event::fake([DatabaseNotificationsSent::class]);

    expect(fn () => app(AiOperationService::class)->enqueueResumeAdaptation($user, $posting))
        ->toThrow(RuntimeException::class, 'fila indisponível');

    $operation = AiOperation::query()->sole();

    expect($operation->status)->toBe(AiOperationStatus::Failed)
        ->and($operation->failure_code)->toBe('processing_failed')
        ->and(collect($dispatches)->filter(fn (mixed $command): bool => $command instanceof ProcessAiOperation))->toHaveCount(1);
});

it('processes the trigger snapshot and persists the completed adaptation', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Descrição original da vaga para PHP.',
        'role' => 'Desenvolvedor PHP',
    ]);
    $posting->items()->create([
        'category' => 'skill',
        'name' => 'PHP',
        'source' => 'ai',
    ]);
    app(ResumeService::class)->save($user, 'Currículo original com PHP.', ResumeLanguage::PtBr);

    $operation = app(AiOperationService::class)->enqueueResumeAdaptation($user, $posting);

    app(ResumeService::class)->save($user, 'Currículo atualizado sem o contexto anterior.', ResumeLanguage::PtBr);
    $posting->update(['description' => 'Descrição atualizada da vaga.']);

    $capturedPrompt = null;
    ResumeAdaptationAgent::fake(function (string $prompt) use (&$capturedPrompt): array {
        $capturedPrompt = $prompt;

        return [
            'adapted_resume' => 'Currículo adaptado com PHP.',
            'changes' => ['Reorganização do resumo'],
            'covered_keywords' => ['PHP'],
            'missing_requirements' => [],
            'alerts' => [],
        ];
    });
    Event::fake([DatabaseNotificationsSent::class]);

    (new ProcessAiOperation($operation->id, $operation->run_token))
        ->handle(app(AiOperationService::class));

    $operation->refresh();
    $adaptation = ResumeAdaptation::query()->where('job_posting_id', $posting->id)->sole();
    $payload = json_decode($capturedPrompt, true);

    expect($operation->status)->toBe(AiOperationStatus::Completed)
        ->and($operation->attempts)->toBe(1)
        ->and($adaptation->adapted_content)->toBe('Currículo adaptado com PHP.')
        ->and($payload['resume'])->toBe('Currículo original com PHP.')
        ->and($payload['job_description'])->toBe('Descrição original da vaga para PHP.')
        ->and($operation->input_snapshot['resume'])->toBe('Currículo original com PHP.')
        ->and($adaptation->source_fingerprint)->toBe($operation->input_fingerprint)
        ->and($operation->notified_at)->not->toBeNull()
        ->and($user->notifications()->count())->toBe(1);
});

it('keeps an adaptation usable but marks it stale when current inputs changed', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Vaga para PHP.',
    ]);
    $posting->items()->create([
        'category' => 'skill',
        'name' => 'PHP',
        'source' => 'ai',
    ]);
    app(ResumeService::class)->save($user, 'Currículo inicial com PHP.', ResumeLanguage::PtBr);

    $operation = app(AiOperationService::class)->enqueueResumeAdaptation($user, $posting);
    app(ResumeService::class)->save($user, 'Currículo atualizado com Laravel.', ResumeLanguage::PtBr);

    ResumeAdaptationAgent::fake([[
        'adapted_resume' => 'Currículo adaptado com PHP.',
        'changes' => [],
        'covered_keywords' => ['PHP'],
        'missing_requirements' => [],
        'alerts' => [],
    ]]);

    (new ProcessAiOperation($operation->id, $operation->run_token))
        ->handle(app(AiOperationService::class));

    $adaptation = ResumeAdaptation::query()->where('job_posting_id', $posting->id)->sole();
    $currentSnapshot = app(AiInputSnapshotService::class)->resumeAdaptation($user, $posting->fresh()->load('items'), ResumeLanguage::PtBr);

    expect($adaptation->adapted_content)->toBe('Currículo adaptado com PHP.')
        ->and($adaptation->source_fingerprint)->not->toBe($currentSnapshot['fingerprint'])
        ->and(Staleness::isAdaptationStale($adaptation))->toBeTrue();
});

it('preserves the previous adaptation and sends one safe notification after terminal failure', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Vaga para PHP.',
    ]);
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    $adaptation = ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Versão anterior preservada.',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    $operation = app(AiOperationService::class)->enqueueResumeAdaptation($user, $posting);
    Event::fake([DatabaseNotificationsSent::class]);

    $job = new ProcessAiOperation($operation->id, $operation->run_token);
    $job->failed(new RuntimeException('segredo do provedor indisponível'));
    $job->failed(new RuntimeException('segunda chamada'));

    $operation->refresh();
    $adaptation->refresh();
    $notification = $user->notifications()->sole();

    expect($operation->status)->toBe(AiOperationStatus::Failed)
        ->and($adaptation->adapted_content)->toBe('Versão anterior preservada.')
        ->and($notification->data['title'])->toBe('Falha na adequação')
        ->and($notification->data['body'])->not->toContain('segredo do provedor')
        ->and($operation->notified_at)->not->toBeNull();
});

it('keeps an operation retryable without sending a terminal notification', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Vaga para PHP.',
    ]);
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    $operation = app(AiOperationService::class)->enqueueResumeAdaptation($user, $posting);

    $calls = 0;
    ResumeAdaptationAgent::fake(function () use (&$calls): array {
        $calls++;

        if ($calls === 1) {
            throw new RuntimeException('provedor temporariamente indisponível');
        }

        return [
            'adapted_resume' => 'Currículo adaptado após retry.',
            'changes' => [],
            'covered_keywords' => [],
            'missing_requirements' => [],
            'alerts' => [],
        ];
    });
    Event::fake([DatabaseNotificationsSent::class]);

    $job = new ProcessAiOperation($operation->id, $operation->run_token);

    expect(fn () => $job->handle(app(AiOperationService::class)))
        ->toThrow(RuntimeException::class);

    $operation->refresh();

    expect($operation->status)->toBe(AiOperationStatus::Queued)
        ->and($operation->attempts)->toBe(1)
        ->and($user->notifications()->count())->toBe(0);

    $job->handle(app(AiOperationService::class));
    $operation->refresh();

    expect($operation->status)->toBe(AiOperationStatus::Completed)
        ->and($operation->attempts)->toBe(2)
        ->and($operation->failure_code)->toBeNull()
        ->and($user->notifications()->count())->toBe(1);
});

it('expires only queued operations that never reached a worker', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    $firstPosting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Primeira vaga para PHP.',
    ]);
    $secondPosting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Segunda vaga para PHP.',
    ]);
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);

    $queued = app(AiOperationService::class)->enqueueResumeAdaptation($user, $firstPosting);
    $queued->update([
        'queued_at' => now()->subMinutes(6),
        'expires_at' => now()->subMinute(),
    ]);

    $running = app(AiOperationService::class)->enqueueResumeAdaptation($user, $secondPosting);
    $running->update([
        'status' => AiOperationStatus::Running,
        'started_at' => now()->subMinutes(6),
    ]);
    Event::fake([DatabaseNotificationsSent::class]);

    $this->artisan('ai-operations:expire')->assertSuccessful();

    $queued->refresh();
    $running->refresh();

    expect($queued->status)->toBe(AiOperationStatus::Failed)
        ->and($queued->failure_code)->toBe('worker_unavailable')
        ->and($running->status)->toBe(AiOperationStatus::Running)
        ->and($user->notifications()->count())->toBe(1);
});

it('retries terminal notifications left pending during the expiration sweep', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    $operation = app(AiOperationService::class)->enqueueResumeAdaptation($user, $posting);
    $operation->update([
        'status' => AiOperationStatus::Failed,
        'failed_at' => now(),
        'failure_code' => 'processing_failed',
        'notified_at' => null,
    ]);
    Event::fake([DatabaseNotificationsSent::class]);

    app(AiOperationService::class)->expireWaiting();

    expect($operation->refresh()->notified_at)->not->toBeNull()
        ->and($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->sole()->data['title'])->toBe('Falha na adequação');
});

it('does not process an expired queued operation when its delayed job arrives', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Vaga para PHP.',
    ]);
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    $operation = app(AiOperationService::class)->enqueueResumeAdaptation($user, $posting);
    $operation->update(['expires_at' => now()->subMinute()]);

    ResumeAdaptationAgent::fake([]);
    Event::fake([DatabaseNotificationsSent::class]);

    (new ProcessAiOperation($operation->id, $operation->run_token))
        ->handle(app(AiOperationService::class));

    expect($operation->refresh()->status)->toBe(AiOperationStatus::Failed)
        ->and($operation->failure_code)->toBe('worker_unavailable')
        ->and($user->notifications()->sole()->data['title'])->toBe('Falha na adequação');

    ResumeAdaptationAgent::assertNeverPrompted();
});

it('enqueues strategic competency classification with a profile snapshot', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    app(ResumeService::class)->save($user, 'Currículo com PHP e Laravel.', ResumeLanguage::PtBr);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    $posting->items()->create([
        'category' => 'skill',
        'name' => 'PHP',
        'source' => 'ai',
    ]);
    StrategicCompetencyAgent::fake([]);

    Livewire::actingAs($user)
        ->test(ProfessionalCompetenciesPage::class)
        ->callAction('classifyWithAi')
        ->assertSuccessful()
        ->assertNotified();

    $operation = AiOperation::query()->sole();

    expect($operation->operation_type)->toBe(AiOperationType::StrategicCompetencyClassification)
        ->and($operation->status)->toBe(AiOperationStatus::Queued)
        ->and($operation->input_snapshot['resume'])->toBe('Currículo com PHP e Laravel.')
        ->and($operation->input_snapshot['candidate_concepts'])->toContain('PHP');

    Queue::assertPushed(ProcessAiOperation::class, function (ProcessAiOperation $job) use ($operation): bool {
        return $job->operationId === $operation->id
            && $job->runToken === $operation->run_token;
    });

    StrategicCompetencyAgent::assertNeverPrompted();
});

it('processes strategic classification from its snapshot and fingerprints AI rows', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    app(ResumeService::class)->save($user, 'Currículo original com PHP.', ResumeLanguage::PtBr);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    $posting->items()->create([
        'category' => 'skill',
        'name' => 'PHP',
        'source' => 'ai',
    ]);
    $operation = app(AiOperationService::class)->enqueueStrategicCompetencyClassification($user);
    app(ResumeService::class)->save($user, 'Currículo atualizado com Laravel.', ResumeLanguage::PtBr);

    $capturedPrompt = null;
    StrategicCompetencyAgent::fake(function (string $prompt) use (&$capturedPrompt): array {
        $capturedPrompt = $prompt;

        return [
            'competencies' => [
                ['concept' => 'PHP', 'strategic_role' => 'core'],
            ],
        ];
    });
    Event::fake([DatabaseNotificationsSent::class]);

    (new ProcessAiOperation($operation->id, $operation->run_token))
        ->handle(app(AiOperationService::class));

    $operation->refresh();
    $competency = ProfessionalCompetency::query()
        ->where('user_id', $user->id)
        ->where('concept', 'PHP')
        ->sole();
    $payload = json_decode($capturedPrompt, true);

    expect($operation->operation_type)->toBe(AiOperationType::StrategicCompetencyClassification)
        ->and($operation->status)->toBe(AiOperationStatus::Completed)
        ->and($payload['resume'])->toBe('Currículo original com PHP.')
        ->and($competency->source_fingerprint)->toBe($operation->input_fingerprint)
        ->and($user->notifications()->count())->toBe(1);
});

it('marks an AI competency stale when the profile changes after classification', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    app(ResumeService::class)->save($user, 'Currículo original com PHP.', ResumeLanguage::PtBr);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    $posting->items()->create([
        'category' => 'skill',
        'name' => 'PHP',
        'source' => 'ai',
    ]);
    $operation = app(AiOperationService::class)->enqueueStrategicCompetencyClassification($user);

    StrategicCompetencyAgent::fake([[
        'competencies' => [
            ['concept' => 'PHP', 'strategic_role' => 'core'],
        ],
    ]]);

    (new ProcessAiOperation($operation->id, $operation->run_token))
        ->handle(app(AiOperationService::class));

    app(ResumeService::class)->save($user, 'Currículo atualizado com Laravel.', ResumeLanguage::PtBr);
    $competency = ProfessionalCompetency::query()->where('user_id', $user->id)->sole();

    expect(Staleness::isCompetencyStale($competency))->toBeTrue();
});

it('does not mark a fingerprinted result stale when the input content is unchanged', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    app(ResumeService::class)->save($user, 'Currículo original com PHP.', ResumeLanguage::PtBr);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Vaga para PHP.',
    ]);
    $operation = app(AiOperationService::class)->enqueueResumeAdaptation($user, $posting);

    ResumeAdaptationAgent::fake([[
        'adapted_resume' => 'Currículo adaptado com PHP.',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]]);

    (new ProcessAiOperation($operation->id, $operation->run_token))
        ->handle(app(AiOperationService::class));

    $adaptation = ResumeAdaptation::query()->where('job_posting_id', $posting->id)->sole();
    app(ResumeService::class)->save($user, 'Currículo original com PHP.', ResumeLanguage::PtBr);

    expect(Staleness::isAdaptationStale($adaptation->fresh()))->toBeFalse();
});

it('generates the same snapshot fingerprint regardless of item insertion order', function () {
    $user = User::factory()->create();
    app(ResumeService::class)->save($user, 'Currículo com PHP e Laravel.', ResumeLanguage::PtBr);

    $firstPosting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Vaga para PHP e Laravel.',
    ]);
    $firstPosting->items()->createMany([
        ['category' => 'skill', 'name' => 'PHP', 'source' => 'ai'],
        ['category' => 'skill', 'name' => 'Laravel', 'source' => 'ai'],
    ]);

    $secondPosting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Vaga para PHP e Laravel.',
    ]);
    $secondPosting->items()->createMany([
        ['category' => 'skill', 'name' => 'Laravel', 'source' => 'ai'],
        ['category' => 'skill', 'name' => 'PHP', 'source' => 'ai'],
    ]);

    $snapshots = app(AiInputSnapshotService::class);
    $first = $snapshots->resumeAdaptation($user, $firstPosting->fresh()->load('items'), ResumeLanguage::PtBr);
    $second = $snapshots->resumeAdaptation($user, $secondPosting->fresh()->load('items'), ResumeLanguage::PtBr);

    expect($first['fingerprint'])->toBe($second['fingerprint'])
        ->and($first['payload']['positioning'])->toBe($second['payload']['positioning']);
});

it('enqueues ATS scoring without calling the provider and captures the adapted resume', function () {
    Queue::fake([ProcessAiOperation::class]);
    AtsScorerAgent::fake([]);

    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Vaga para PHP e Laravel.',
        'role' => 'Desenvolvedor PHP',
    ]);
    $posting->items()->create([
        'category' => 'skill',
        'name' => 'PHP',
        'source' => 'ai',
    ]);
    app(ResumeService::class)->save($user, 'Currículo original com PHP.', ResumeLanguage::PtBr);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Currículo adaptado com PHP.',
        'changes' => [],
        'covered_keywords' => ['PHP'],
        'missing_requirements' => [],
        'alerts' => [],
        'is_ats_optimized' => true,
        'ats_prompt' => 'Experiência comprovada em PHP.',
    ]);

    Livewire::actingAs($user)
        ->test(ApplicationPage::class, ['record' => $posting->id])
        ->call('simulateAtsScore')
        ->assertSuccessful()
        ->assertNotified();

    $operation = AiOperation::query()->sole();

    expect($operation->operation_type)->toBe(AiOperationType::AtsScore)
        ->and($operation->status)->toBe(AiOperationStatus::Queued)
        ->and($operation->input_snapshot['resume'])->toContain('Currículo adaptado com PHP.')
        ->and($operation->input_snapshot['resume'])->toContain('Experiência comprovada em PHP.')
        ->and($operation->input_snapshot['items'][0]['name'])->toBe('PHP');

    Queue::assertPushed(ProcessAiOperation::class, function (ProcessAiOperation $job) use ($operation): bool {
        return $job->operationId === $operation->id
            && $job->runToken === $operation->run_token;
    });

    AtsScorerAgent::assertNeverPrompted();
});

it('processes ATS scoring from its snapshot and persists the score on the adaptation', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Descrição original para PHP.',
        'role' => 'Desenvolvedor PHP',
    ]);
    $posting->items()->create([
        'category' => 'skill',
        'name' => 'PHP',
        'source' => 'ai',
    ]);
    app(ResumeService::class)->save($user, 'Currículo original com PHP.', ResumeLanguage::PtBr);
    $adaptation = ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Currículo adaptado original com PHP.',
        'changes' => [],
        'covered_keywords' => ['PHP'],
        'missing_requirements' => [],
        'alerts' => [],
        'is_ats_optimized' => true,
        'ats_prompt' => 'Experiência original em PHP.',
    ]);

    $operation = app(AiOperationService::class)->enqueueAtsScore($user, $posting);
    $adaptation->update(['adapted_content' => 'Currículo adaptado atual sem o snapshot.']);

    $capturedPrompt = null;
    AtsScorerAgent::fake(function (string $prompt) use (&$capturedPrompt): array {
        $capturedPrompt = $prompt;

        return [
            'score' => 8,
            'rationale' => 'Boa aderência aos requisitos conhecidos.',
        ];
    });
    Event::fake([DatabaseNotificationsSent::class]);

    (new ProcessAiOperation($operation->id, $operation->run_token))
        ->handle(app(AiOperationService::class));

    $operation->refresh();
    $adaptation->refresh();
    $payload = json_decode($capturedPrompt, true);

    expect($operation->status)->toBe(AiOperationStatus::Completed)
        ->and($adaptation->ats_score)->toBe(8)
        ->and($adaptation->ats_rationale)->toBe('Boa aderência aos requisitos conhecidos.')
        ->and($adaptation->ats_source_fingerprint)->toBe($operation->input_fingerprint)
        ->and($payload['resume'])->toContain('Currículo adaptado original com PHP.')
        ->and($payload['resume'])->toContain('Experiência original em PHP.')
        ->and($user->notifications()->sole()->data['title'])->toBe('Simulação ATS concluída');
});

it('enqueues ATS optimization changes without running them in the request', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'role' => 'Desenvolvedor PHP',
        'company' => 'Acme',
    ]);
    $posting->items()->create([
        'category' => 'skill',
        'name' => 'PHP',
        'source' => 'ai',
    ]);
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Currículo adaptado com PHP.',
        'changes' => [],
        'covered_keywords' => ['PHP'],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    Livewire::actingAs($user)
        ->test(ApplicationPage::class, ['record' => $posting->id])
        ->call('toggleAtsOptimization')
        ->assertSuccessful()
        ->assertNotified();

    $operation = AiOperation::query()->sole();
    $adaptation = ResumeAdaptation::query()->where('job_posting_id', $posting->id)->sole();

    expect($operation->operation_type)->toBe(AiOperationType::AtsOptimization)
        ->and($operation->status)->toBe(AiOperationStatus::Queued)
        ->and($operation->input_snapshot['enabled'])->toBeTrue()
        ->and($adaptation->is_ats_optimized)->toBeFalse()
        ->and($adaptation->ats_prompt)->toBeNull();

    Queue::assertPushed(ProcessAiOperation::class, function (ProcessAiOperation $job) use ($operation): bool {
        return $job->operationId === $operation->id
            && $job->runToken === $operation->run_token;
    });
});

it('does not enable ATS optimization when the resume has no matching keywords', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    $posting->items()->create([
        'category' => 'skill',
        'name' => 'Laravel',
        'source' => 'ai',
    ]);
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Currículo adaptado com PHP.',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    expect(fn () => app(AiOperationService::class)->enqueueAtsOptimization($user, $posting, true))
        ->toThrow(LogicException::class, 'palavras-chave');

    expect(AiOperation::query()->count())->toBe(0)
        ->and(ResumeAdaptation::query()->where('is_ats_optimized', true)->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('does not enqueue ATS optimization when the required adaptation is absent', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);

    Livewire::actingAs($user)
        ->test(ApplicationPage::class, ['record' => $posting->id])
        ->call('toggleAtsOptimization')
        ->assertSuccessful()
        ->assertNotified();

    expect(AiOperation::query()->count())->toBe(0)
        ->and(ResumeAdaptation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('applies the queued ATS optimization snapshot only when its worker runs', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'role' => 'Desenvolvedor PHP',
        'company' => 'Acme',
    ]);
    $posting->items()->create([
        'category' => 'skill',
        'name' => 'PHP',
        'source' => 'ai',
    ]);
    app(ResumeService::class)->save($user, 'Currículo original com PHP.', ResumeLanguage::PtBr);
    $adaptation = ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Currículo adaptado com PHP.',
        'changes' => [],
        'covered_keywords' => ['PHP'],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    $operation = app(AiOperationService::class)->enqueueAtsOptimization($user, $posting, true);
    app(ResumeService::class)->save($user, 'Currículo atualizado sem o contexto do snapshot.', ResumeLanguage::PtBr);
    Event::fake([DatabaseNotificationsSent::class]);

    (new ProcessAiOperation($operation->id, $operation->run_token))
        ->handle(app(AiOperationService::class));

    $operation->refresh();
    $adaptation->refresh();

    expect($operation->status)->toBe(AiOperationStatus::Completed)
        ->and($adaptation->is_ats_optimized)->toBeTrue()
        ->and($adaptation->ats_prompt)->toContain('PHP')
        ->and($adaptation->ats_prompt_fingerprint)->toBe($operation->input_fingerprint)
        ->and($adaptation->ats_prompt)->not->toContain('atualizado')
        ->and($user->notifications()->sole()->data['title'])->toBe('Otimização ATS concluída');
});

it('coordinates adaptation and ATS operations per posting', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Vaga para PHP.',
    ]);
    $posting->items()->create([
        'category' => 'skill',
        'name' => 'PHP',
        'source' => 'ai',
    ]);
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Currículo adaptado com PHP.',
        'changes' => [],
        'covered_keywords' => ['PHP'],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    app(AiOperationService::class)->enqueueResumeAdaptation($user, $posting);

    expect(fn () => app(AiOperationService::class)->enqueueAtsScore($user, $posting))
        ->toThrow(LogicException::class, 'operação');

    expect(AiOperation::query()->count())->toBe(1);
});

it('keeps the ATS score usable but marks it stale when its source changes', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Vaga para PHP.',
    ]);
    $posting->items()->create([
        'category' => 'skill',
        'name' => 'PHP',
        'source' => 'ai',
    ]);
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    $adaptation = ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Currículo adaptado com PHP.',
        'changes' => [],
        'covered_keywords' => ['PHP'],
        'missing_requirements' => [],
        'alerts' => [],
    ]);
    $operation = app(AiOperationService::class)->enqueueAtsScore($user, $posting);

    AtsScorerAgent::fake([[
        'score' => 7,
        'rationale' => 'Boa aderência.',
    ]]);

    (new ProcessAiOperation($operation->id, $operation->run_token))
        ->handle(app(AiOperationService::class));

    $adaptation->refresh();
    $adaptation->update(['adapted_content' => 'Nova adaptação com PHP.']);

    expect($adaptation->ats_score)->toBe(7)
        ->and(Staleness::isAtsScoreStale($adaptation->fresh()))->toBeTrue();
});

it('uses the timestamp fallback for legacy ATS scores without a fingerprint', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Vaga para PHP.',
    ]);
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    $adaptation = ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Currículo adaptado com PHP.',
        'changes' => [],
        'covered_keywords' => ['PHP'],
        'missing_requirements' => [],
        'alerts' => [],
        'ats_score' => 8,
        'ats_rationale' => 'Boa aderência.',
    ]);

    $adaptation->forceFill(['updated_at' => now()->subMinute()])->save();

    expect($adaptation->fresh()->ats_source_fingerprint)->toBeNull()
        ->and(Staleness::isAtsScoreStale($adaptation->fresh()))->toBeTrue();
});

it('marks the queued ATS prompt stale when its captured profile changes', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    $posting->items()->create([
        'category' => 'skill',
        'name' => 'PHP',
        'source' => 'ai',
    ]);
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Currículo adaptado com PHP.',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]);
    $operation = app(AiOperationService::class)->enqueueAtsOptimization($user, $posting, true);

    (new ProcessAiOperation($operation->id, $operation->run_token))
        ->handle(app(AiOperationService::class));

    $adaptation = ResumeAdaptation::query()->where('job_posting_id', $posting->id)->sole();
    app(ResumeService::class)->save($user, 'Currículo atualizado sem PHP.', ResumeLanguage::PtBr);

    expect($adaptation->is_ats_optimized)->toBeTrue()
        ->and(Staleness::isAtsPromptStale($adaptation->fresh()))->toBeTrue();
});

it('schedules periodic expiration of queued AI operations', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('ai-operations:expire')
        ->assertSuccessful();
});

it('reclaims a running operation left behind after a worker timeout', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Vaga para PHP.',
    ]);
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    $operation = app(AiOperationService::class)->enqueueResumeAdaptation($user, $posting);
    $operation->update([
        'status' => AiOperationStatus::Running,
        'attempts' => 1,
        'started_at' => now()->subMinutes(4),
        'last_attempt_started_at' => now()->subMinutes(4),
    ]);

    ResumeAdaptationAgent::fake([[
        'adapted_resume' => 'Currículo adaptado com PHP.',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]]);

    (new ProcessAiOperation($operation->id, $operation->run_token))
        ->handle(app(AiOperationService::class));

    expect($operation->refresh()->status)->toBe(AiOperationStatus::Completed)
        ->and($operation->attempts)->toBe(2);
});

it('does not let a stale worker overwrite a recovered ATS score', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Vaga para PHP.',
    ]);
    $posting->items()->create([
        'category' => 'skill',
        'name' => 'PHP',
        'source' => 'ai',
    ]);
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Currículo adaptado com PHP.',
        'changes' => [],
        'covered_keywords' => ['PHP'],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    $service = app(AiOperationService::class);
    $operation = $service->enqueueAtsScore($user, $posting);
    $oldAttemptToken = $operation->attempt_token;
    $recoveredAttemptToken = $oldAttemptToken;
    $calls = 0;
    $model = (string) config('ai.providers.'.config('ai.default').'.models.text.default');

    AtsScorerAgent::fake(function () use (&$calls, &$recoveredAttemptToken, $operation, $service, $model): array {
        $calls++;

        if ($calls === 1) {
            $operation->update(['last_attempt_started_at' => now()->subSeconds(131)]);
            config()->set('ai.providers.'.config('ai.default').'.models.text.default', $model.'-recovered');

            try {
                $service->process($operation->id, $operation->run_token, $recoveredAttemptToken);
            } finally {
                config()->set('ai.providers.'.config('ai.default').'.models.text.default', $model);
            }

            return [
                'score' => 1,
                'rationale' => 'Resultado antigo.',
            ];
        }

        return [
            'score' => 9,
            'rationale' => 'Resultado recuperado.',
        ];
    });

    expect(fn () => (new ProcessAiOperation($operation->id, $operation->run_token, $oldAttemptToken))
        ->handle($service))
        ->toThrow(RuntimeException::class);

    $operation->refresh();
    $adaptation = ResumeAdaptation::query()->where('job_posting_id', $posting->id)->sole();

    expect($recoveredAttemptToken)->not->toBe($oldAttemptToken)
        ->and($operation->status)->toBe(AiOperationStatus::Completed)
        ->and($operation->attempts)->toBe(2)
        ->and($adaptation->ats_score)->toBe(9)
        ->and($adaptation->ats_rationale)->toBe('Resultado recuperado.');
});

it('fails a final reclaimed timeout without accepting an older queue attempt', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    $operation = app(AiOperationService::class)->enqueueResumeAdaptation($user, $posting);
    $originalAttemptToken = $operation->attempt_token;
    $operation->update([
        'status' => AiOperationStatus::Running,
        'attempts' => 3,
        'attempt_token' => (string) Str::uuid(),
        'last_attempt_started_at' => now()->subMinutes(4),
    ]);
    Event::fake([DatabaseNotificationsSent::class]);

    $staleJob = new ProcessAiOperation($operation->id, $operation->run_token, $originalAttemptToken);
    $staleQueueJob = Mockery::mock(Job::class);
    $staleQueueJob->shouldReceive('attempts')->andReturn(2);
    $staleJob->job = $staleQueueJob;
    $staleJob->failed(new RuntimeException('timeout antigo'));

    expect($operation->refresh()->status)->toBe(AiOperationStatus::Running);

    $finalJob = new ProcessAiOperation($operation->id, $operation->run_token, $originalAttemptToken);
    $finalQueueJob = Mockery::mock(Job::class);
    $finalQueueJob->shouldReceive('attempts')->andReturn(3);
    $finalJob->job = $finalQueueJob;
    $finalJob->failed(new TimeoutExceededException('timeout final'));

    expect($operation->refresh()->status)->toBe(AiOperationStatus::Failed)
        ->and($operation->failure_code)->toBe('processing_failed');
});

it('shows polling and the active operation state on the application page', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);

    $component = Livewire::actingAs($user)
        ->test(ApplicationPage::class, ['record' => $posting->id])
        ->callAction('adaptPtBr')
        ->assertSuccessful();

    expect($component->html())
        ->toContain('wire:poll.5s="refreshAiOperation"')
        ->toContain('Adequação do currículo em andamento');
});

it('clears ATS consent when an asynchronous adaptation source changes', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    $posting->items()->create([
        'category' => 'skill',
        'name' => 'PHP',
        'source' => 'ai',
    ]);
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Currículo adaptado com PHP.',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
        'is_ats_optimized' => true,
        'ats_prompt' => 'Experiência comprovada em PHP.',
        'ats_prompt_fingerprint' => 'prompt-fingerprint',
    ]);

    $operation = app(AiOperationService::class)->enqueueAtsOptimization($user, $posting, false);
    $component = Livewire::actingAs($user)
        ->test(ApplicationPage::class, ['record' => $posting->id])
        ->set('atsConsent', true);

    (new ProcessAiOperation($operation->id, $operation->run_token))
        ->handle(app(AiOperationService::class));

    $component
        ->call('refreshAiOperation')
        ->assertSet('atsConsent', false);
});

it('renders a persisted ATS score after the page refreshes', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Currículo adaptado com PHP.',
        'changes' => [],
        'covered_keywords' => ['PHP'],
        'missing_requirements' => [],
        'alerts' => [],
        'ats_score' => 8,
        'ats_rationale' => 'Boa aderência.',
        'is_ats_optimized' => true,
        'ats_prompt' => 'Experiência comprovada em PHP.',
    ]);

    Livewire::actingAs($user)
        ->test(ApplicationPage::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertSee('Nota simulada ATS: 8/10')
        ->assertSee('Boa aderência.');
});

it('shows polling and the active classification state on the competencies page', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    $posting->items()->create([
        'category' => 'skill',
        'name' => 'PHP',
        'source' => 'ai',
    ]);

    $component = Livewire::actingAs($user)
        ->test(ProfessionalCompetenciesPage::class)
        ->callAction('classifyWithAi')
        ->assertSuccessful();

    expect($component->html())
        ->toContain('wire:poll.5s="refreshAiOperation"')
        ->toContain('Classificação estratégica em andamento');
});

it('enqueues a resume translation with the pt-BR snapshot', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    app(ResumeService::class)->save($user, 'Currículo original com PHP.', ResumeLanguage::PtBr);

    $operation = app(AiOperationService::class)->enqueueResumeTranslation($user);

    expect($operation->operation_type)->toBe(AiOperationType::ResumeTranslation)
        ->and($operation->status)->toBe(AiOperationStatus::Queued)
        ->and($operation->target_type)->toBe('user')
        ->and($operation->job_posting_id)->toBeNull()
        ->and($operation->input_snapshot['resume'])->toBe('Currículo original com PHP.')
        ->and($operation->input_fingerprint)->toHaveLength(64);

    Queue::assertPushed(ProcessAiOperation::class);
});

it('refuses the translation when the pt-BR resume is empty', function () {
    $user = User::factory()->create();

    expect(fn () => app(AiOperationService::class)->enqueueResumeTranslation($user))
        ->toThrow(LogicException::class, 'Cadastre o currículo em português');
});

it('does not enqueue a duplicate translation while the current one is active', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);

    app(AiOperationService::class)->enqueueResumeTranslation($user);

    expect(fn () => app(AiOperationService::class)->enqueueResumeTranslation($user))
        ->toThrow(LogicException::class)
        ->and(AiOperation::query()->count())->toBe(1);
});

it('processes the translation snapshot and stores the english version with the source fingerprint', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    app(ResumeService::class)->save($user, 'Currículo original com PHP.', ResumeLanguage::PtBr);

    $operation = app(AiOperationService::class)->enqueueResumeTranslation($user);

    $capturedPrompt = null;
    ResumeTranslationAgent::fake(function (string $prompt) use (&$capturedPrompt): array {
        $capturedPrompt = $prompt;

        return ['translated_resume' => "English resume.\nPHP experience."];
    });
    Event::fake([DatabaseNotificationsSent::class]);

    (new ProcessAiOperation($operation->id, $operation->run_token))
        ->handle(app(AiOperationService::class));

    $operation->refresh();
    $english = app(ResumeService::class)->current($user, ResumeLanguage::En);

    expect($operation->status)->toBe(AiOperationStatus::Completed)
        ->and($english?->content)->toBe("English resume.\nPHP experience.")
        ->and($english?->source_fingerprint)->toBe($operation->input_fingerprint)
        ->and(json_decode($capturedPrompt, true)['resume'])->toBe('Currículo original com PHP.')
        ->and($operation->notified_at)->not->toBeNull()
        ->and($user->notifications()->count())->toBe(1);
});

it('rejects a translation that introduces concepts absent from the original resume', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    app(ResumeService::class)->save($user, 'Currículo original com PHP.', ResumeLanguage::PtBr);
    $operation = app(AiOperationService::class)->enqueueResumeTranslation($user);

    ResumeTranslationAgent::fake([['translated_resume' => 'English resume with Kubernetes.']]);

    expect(fn () => (new ProcessAiOperation($operation->id, $operation->run_token))->handle(app(AiOperationService::class)))
        ->toThrow(RuntimeException::class);

    expect(app(ResumeService::class)->content($user, ResumeLanguage::En))->toBeNull();
});

it('marks the english version stale when pt-BR changes after the translation', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    app(ResumeService::class)->save($user, 'Currículo original com PHP.', ResumeLanguage::PtBr);
    $operation = app(AiOperationService::class)->enqueueResumeTranslation($user);

    ResumeTranslationAgent::fake([['translated_resume' => 'English resume with PHP.']]);
    (new ProcessAiOperation($operation->id, $operation->run_token))->handle(app(AiOperationService::class));

    $english = app(ResumeService::class)->current($user, ResumeLanguage::En);

    expect(Staleness::isResumeTranslationStale($english))->toBeFalse();

    app(ResumeService::class)->save($user, 'Currículo atualizado com PHP e Laravel.', ResumeLanguage::PtBr);

    expect(Staleness::isResumeTranslationStale($english->fresh()))->toBeTrue();
});

it('keeps the source fingerprint when the english version is edited by hand', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    app(ResumeService::class)->save($user, 'Currículo original com PHP.', ResumeLanguage::PtBr);
    $operation = app(AiOperationService::class)->enqueueResumeTranslation($user);

    ResumeTranslationAgent::fake([['translated_resume' => 'English resume with PHP.']]);
    (new ProcessAiOperation($operation->id, $operation->run_token))->handle(app(AiOperationService::class));

    app(ResumeService::class)->save($user, 'Hand edited english resume.', ResumeLanguage::En);

    $english = app(ResumeService::class)->current($user, ResumeLanguage::En);

    expect($english->content)->toBe('Hand edited english resume.')
        ->and($english->source_fingerprint)->toBe($operation->input_fingerprint);
});

it('notifies a terminal translation failure', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    $operation = app(AiOperationService::class)->enqueueResumeTranslation($user);
    Event::fake([DatabaseNotificationsSent::class]);

    $job = new ProcessAiOperation($operation->id, $operation->run_token);
    $job->failed(new RuntimeException('falha controlada'));

    expect($operation->refresh()->status)->toBe(AiOperationStatus::Failed)
        ->and($user->notifications()->sole()->data['title'])->toBe('Falha na tradução');
});

it('enfileira a adequação EN capturando o currículo em inglês', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Vaga para PHP.',
    ]);
    $posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'ai']);
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    app(ResumeService::class)->save($user, 'English resume with PHP.', ResumeLanguage::En);

    $operation = app(AiOperationService::class)->enqueueResumeAdaptation($user, $posting, ResumeLanguage::En);

    expect($operation->input_snapshot['language'])->toBe('en')
        ->and($operation->input_snapshot['resume'])->toBe('English resume with PHP.')
        ->and($operation->operation_key)->toContain(':en');
});

it('recusa enfileirar adequação sem currículo no idioma pedido', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);

    expect(fn () => app(AiOperationService::class)->enqueueResumeAdaptation($user, $posting, ResumeLanguage::En))
        ->toThrow(LogicException::class, 'currículo em inglês');

    expect(AiOperation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('bloqueia uma segunda operação ativa na vaga mesmo em outro idioma', function () {
    Queue::fake([ProcessAiOperation::class]);

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    app(ResumeService::class)->save($user, 'English resume with PHP.', ResumeLanguage::En);

    app(AiOperationService::class)->enqueueResumeAdaptation($user, $posting, ResumeLanguage::PtBr);

    expect(fn () => app(AiOperationService::class)->enqueueResumeAdaptation($user, $posting, ResumeLanguage::En))
        ->toThrow(LogicException::class, 'vaga');

    expect(AiOperation::query()->count())->toBe(1);
});
