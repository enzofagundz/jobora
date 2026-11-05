<?php

use App\Agents\JobTriageAgent;
use App\Enums\JobCaptureTriageResult;
use App\Enums\JobCaptureTriageStatus;
use App\Enums\TriageOrigin;
use App\Jobs\PrefilterJobCaptures;
use App\Jobs\TriageJobCaptures;
use App\Models\JobCapture;
use App\Models\User;
use App\Services\JobTriageService;
use App\Services\Triage\JobPrefilter;
use App\Services\Triage\PrefilterPolicy;
use App\Services\Triage\TriageBatchNotifier;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Prompts\AgentPrompt;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();

    Queue::fake([PrefilterJobCaptures::class, TriageJobCaptures::class]);

    config([
        'triage.prefilter.enabled' => true,
        'triage.prefilter.account_id' => 'account-123',
        'triage.prefilter.api_token' => 'token-abc',
        'triage.prefilter.model' => '@cf/cloudflare/clef-flash',
        'triage.prefilter.base_url' => 'https://api.cloudflare.com/client/v4',
        'triage.prefilter.min_job_probability' => 0.50,
    ]);
});

function prefilterCapture(User $user, string $description, JobCaptureTriageStatus $status = JobCaptureTriageStatus::Processing): JobCapture
{
    return JobCapture::factory()->for($user, 'user')->create([
        'description' => $description,
        'triage_status' => $status,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function prefilterResponse(float $isJob = 0.87, array $overrides = []): array
{
    return array_replace_recursive([
        'errors' => [],
        'messages' => [],
        'result' => [
            'answers' => [
                'is_job' => ['type' => 'noul', 'noul' => $isJob],
                'non_job_kind' => [
                    'type' => 'choice',
                    'choice' => 'editorial_content',
                    'probabilities' => [
                        'candidate_offering' => 0.02,
                        'job_curation' => 0.03,
                        'editorial_content' => 0.90,
                        'promotion' => 0.03,
                        'other' => 0.02,
                    ],
                    'confidence' => 0.91,
                ],
            ],
        ],
        'success' => true,
    ], $overrides);
}

/**
 * @param  array<string, float>  $byDescription
 */
function fakePrefilterByDescription(array $byDescription): void
{
    Http::fake(function (Request $request) use ($byDescription) {
        $description = (string) data_get($request->data(), 'state.post.description');

        return Http::response(prefilterResponse($byDescription[$description] ?? 0.87));
    });
}

/**
 * @param  array<int, int>  $ids
 */
function runPrefilter(array $ids, User $user): void
{
    (new PrefilterJobCaptures($ids, $user->id))->handle(
        app(JobPrefilter::class),
        app(PrefilterPolicy::class),
        app(TriageBatchNotifier::class),
    );
}

it('bars a capture below the threshold and forwards the rest to the llm', function (): void {
    $barred = prefilterCapture($this->user, 'non-job');
    $approved = prefilterCapture($this->user, 'job');

    fakePrefilterByDescription(['non-job' => 0.12, 'job' => 0.87]);

    runPrefilter([$barred->id, $approved->id], $this->user);

    $barred->refresh();

    expect($barred->triage_status)->toBe(JobCaptureTriageStatus::Completed)
        ->and($barred->triage_result)->toBe(JobCaptureTriageResult::NotAJob)
        ->and($barred->triage_origin)->toBe(TriageOrigin::Prefilter)
        ->and($barred->triage_reason)->toStartWith('Pré-filtro: ')
        ->and($barred->triage_reason)->toContain('(p=0,12)')
        ->and($barred->triage_error)->toBeNull()
        ->and($approved->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Processing);

    Queue::assertPushed(TriageJobCaptures::class, function (TriageJobCaptures $job) use ($barred, $approved): bool {
        $triageIds = $job->triageIds;
        sort($triageIds);

        return $job->captureIds === [$barred->id, $approved->id]
            && $triageIds === [$approved->id];
    });

    JobTriageAgent::assertNeverPrompted();
});

it('triages only the forwarded captures and records the llm origin', function (): void {
    $barred = prefilterCapture($this->user, 'non-job');
    $approved = prefilterCapture($this->user, 'job');

    fakePrefilterByDescription(['non-job' => 0.12, 'job' => 0.87]);

    runPrefilter([$barred->id, $approved->id], $this->user);

    $triageJob = null;

    Queue::assertPushed(TriageJobCaptures::class, function (TriageJobCaptures $job) use (&$triageJob): bool {
        $triageJob = $job;

        return true;
    });

    JobTriageAgent::fake([[
        'results' => [
            ['capture_id' => $approved->id, 'is_job' => true, 'result' => 'relevant', 'reason' => 'Vaga de Laravel.'],
        ],
    ]]);

    $triageJob->handle(app(JobTriageService::class));

    expect($approved->refresh()->triage_result)->toBe(JobCaptureTriageResult::Relevant)
        ->and($approved->refresh()->triage_origin)->toBe(TriageOrigin::Llm)
        ->and($barred->refresh()->triage_result)->toBe(JobCaptureTriageResult::NotAJob)
        ->and($barred->refresh()->triage_origin)->toBe(TriageOrigin::Prefilter);

    JobTriageAgent::assertPrompted(function (AgentPrompt $prompt) use ($approved, $barred): bool {
        return str_contains($prompt->prompt, '"id":'.$approved->id)
            && ! str_contains($prompt->prompt, '"id":'.$barred->id);
    });
});

it('forwards a capture to the llm when the prefilter fails', function (): void {
    $capture = prefilterCapture($this->user, 'job');

    Http::fake(['*' => Http::response('boom', 500)]);
    Event::fake([MessageLogged::class]);

    runPrefilter([$capture->id], $this->user);

    expect($capture->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Processing);

    Queue::assertPushed(TriageJobCaptures::class, function (TriageJobCaptures $job) use ($capture): bool {
        return $job->triageIds === [$capture->id] && $job->captureIds === [$capture->id];
    });

    Event::assertDispatched(MessageLogged::class, function (MessageLogged $event) use ($capture): bool {
        return $event->message === 'Job captures prefiltered.'
            && ($event->context['operation'] ?? null) === 'job_triage_prefilter'
            && ($event->context['prefilter_failed_ids'] ?? null) === [$capture->id]
            && ($event->context['prefilter_failed_count'] ?? null) === 1
            && ($event->context['barred_count'] ?? null) === 0
            && ($event->context['approved_count'] ?? null) === 0
            && ($event->context['forwarded_count'] ?? null) === 1;
    });
});

it('logs the batch counts in the ai channel', function (): void {
    $barred = prefilterCapture($this->user, 'non-job');
    $approved = prefilterCapture($this->user, 'job');

    fakePrefilterByDescription(['non-job' => 0.12, 'job' => 0.87]);
    Event::fake([MessageLogged::class]);

    runPrefilter([$barred->id, $approved->id], $this->user);

    Event::assertDispatched(MessageLogged::class, function (MessageLogged $event) use ($barred, $approved): bool {
        return $event->message === 'Job captures prefiltered.'
            && ($event->context['operation'] ?? null) === 'job_triage_prefilter'
            && ($event->context['job_capture_ids'] ?? null) === [$barred->id, $approved->id]
            && ($event->context['barred_count'] ?? null) === 1
            && ($event->context['approved_count'] ?? null) === 1
            && ($event->context['forwarded_count'] ?? null) === 1
            && ($event->context['prefilter_failed_count'] ?? null) === 0
            && ($event->context['prefilter_failed_ids'] ?? null) === [];
    });
});

it('dispatches the triage directly when the flag is off', function (): void {
    config(['triage.prefilter.enabled' => false]);

    $capture = prefilterCapture($this->user, 'job', JobCaptureTriageStatus::Pending);

    app(JobTriageService::class)->startBatch(collect([$capture]), $this->user);

    Queue::assertPushed(TriageJobCaptures::class);
    Queue::assertNotPushed(PrefilterJobCaptures::class);
    Http::assertNothingSent();
});

it('notifies once and does not dispatch triage when every capture is barred', function (): void {
    Event::fake([DatabaseNotificationsSent::class]);

    $first = prefilterCapture($this->user, 'non-job one');
    $second = prefilterCapture($this->user, 'non-job two');

    Http::fake(['*' => Http::response(prefilterResponse(0.12))]);

    runPrefilter([$first->id, $second->id], $this->user);

    Queue::assertNotPushed(TriageJobCaptures::class);

    expect($this->user->notifications()->count())->toBe(1);

    $notification = $this->user->notifications()->first();

    expect($notification->data['title'])->toBe('Triagem concluída')
        ->and($notification->data['body'])->toBe('0 captura(s) encaminhada(s) à IA, 2 barrada(s) pelo pré-filtro e 0 falha(s).')
        ->and($notification->data['actions'])->toHaveCount(1)
        ->and($notification->data['actions'][0]['label'])->toBe('Ver capturas')
        ->and($notification->data['actions'][0]['url'])->toContain('job-captures');

    Event::assertDispatched(DatabaseNotificationsSent::class, 1);
});

it('forwards every capture to the llm when every prefilter request fails', function (): void {
    $first = prefilterCapture($this->user, 'job one');
    $second = prefilterCapture($this->user, 'job two');

    Http::fake(['*' => Http::response('boom', 500)]);

    runPrefilter([$first->id, $second->id], $this->user);

    Queue::assertPushed(TriageJobCaptures::class, function (TriageJobCaptures $job) use ($first, $second): bool {
        $ids = $job->triageIds;
        sort($ids);

        return $ids === [$first->id, $second->id];
    });

    expect($this->user->notifications()->count())->toBe(0)
        ->and($first->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Processing)
        ->and($second->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Processing);
});

it('does not touch captures from another user', function (): void {
    $other = User::factory()->create();
    $foreign = prefilterCapture($other, 'job');

    runPrefilter([$foreign->id], $this->user);

    expect($foreign->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Processing);

    Queue::assertNothingPushed();
});

it('resets processing captures to failed when the prefilter job fails', function (): void {
    $processing = prefilterCapture($this->user, 'job');
    $completed = prefilterCapture($this->user, 'job', JobCaptureTriageStatus::Completed);

    (new PrefilterJobCaptures([$processing->id, $completed->id], $this->user->id))
        ->failed(new RuntimeException('timeout prefilter'));

    expect($processing->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Failed)
        ->and($completed->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Completed);

    $notification = $this->user->notifications()->first();

    expect($notification->data['title'])->toBe('Falha na triagem')
        ->and($notification->data['body'])->toBe('Nenhuma captura foi processada com sucesso.');
});
