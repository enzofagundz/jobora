<?php

use App\Agents\JobTriageAgent;
use App\Enums\JobCaptureTriageResult;
use App\Enums\JobCaptureTriageStatus;
use App\Models\JobCapture;
use App\Models\ScreeningPreference;
use App\Models\User;
use App\Services\JobTriageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

function triageCaptures(int $count = 2, ?User $owner = null): Collection
{
    $owner ??= User::factory()->create();

    return Collection::times($count, fn () => JobCapture::factory()->for($owner, 'user')->create([
        'description' => str_repeat('Vaga para desenvolvedor PHP com Laravel. ', 5),
    ]));
}

it('classifica posts de curadoria de vagas como não-vaga no prompt', function () {
    $instructions = app(JobTriageAgent::class)->instructions();

    expect($instructions)->toContain('curadoria ou agregação de vagas')
        ->and($instructions)->toContain('Telegram')
        ->and($instructions)->toContain('not_a_job');
});

it('persists results by capture_id regardless of response order', function () {
    $captures = triageCaptures(2);
    [$c1, $c2] = $captures->all();
    $user = $c1->user;

    JobTriageAgent::fake([[
        'results' => [
            ['capture_id' => $c2->id, 'is_job' => true, 'result' => 'relevant', 'reason' => 'Vaga de Laravel.'],
            ['capture_id' => $c1->id, 'is_job' => true, 'result' => 'rejected', 'reason' => 'Vaga de Tech Lead.'],
        ],
    ]]);

    app(JobTriageService::class)->triageBatch(collect([$c1, $c2]), $user);

    expect($c1->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Completed)
        ->and($c1->refresh()->triage_result)->toBe(JobCaptureTriageResult::Rejected)
        ->and($c1->refresh()->triage_reason)->toBe('Vaga de Tech Lead.')
        ->and($c1->refresh()->triage_error)->toBeNull()
        ->and($c2->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Completed)
        ->and($c2->refresh()->triage_result)->toBe(JobCaptureTriageResult::Relevant)
        ->and($c2->refresh()->triage_reason)->toBe('Vaga de Laravel.');
});

it('persists not_a_job distinct from rejected', function () {
    $captures = triageCaptures(2);
    [$c1, $c2] = $captures->all();
    $user = $c1->user;

    JobTriageAgent::fake([[
        'results' => [
            ['capture_id' => $c1->id, 'is_job' => false, 'result' => 'not_a_job', 'reason' => 'Artigo sobre carreira.'],
            ['capture_id' => $c2->id, 'is_job' => true, 'result' => 'rejected', 'reason' => 'CLT presencial.'],
        ],
    ]]);

    app(JobTriageService::class)->triageBatch(collect([$c1, $c2]), $user);

    expect($c1->refresh()->triage_result)->toBe(JobCaptureTriageResult::NotAJob)
        ->and($c2->refresh()->triage_result)->toBe(JobCaptureTriageResult::Rejected);
});

it('persists uncertain', function () {
    $captures = triageCaptures(1);
    [$c1] = $captures->all();
    $user = $c1->user;

    JobTriageAgent::fake([[
        'results' => [
            ['capture_id' => $c1->id, 'is_job' => true, 'result' => 'uncertain', 'reason' => 'Sem requisitos na descrição.'],
        ],
    ]]);

    app(JobTriageService::class)->triageBatch(collect([$c1]), $user);

    expect($c1->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Completed)
        ->and($c1->refresh()->triage_result)->toBe(JobCaptureTriageResult::Uncertain)
        ->and($c1->refresh()->triage_reason)->toBe('Sem requisitos na descrição.');
});

it('sends ids, preferences and rejections in the payload', function () {
    $user = User::factory()->create();
    ScreeningPreference::factory()->create([
        'user_id' => $user->id,
        'preferences' => ['Laravel'],
        'rejections' => ['Tech Lead'],
    ]);

    [$c1] = triageCaptures(1, $user)->all();

    $capturedPrompt = null;

    JobTriageAgent::fake(function (string $prompt) use (&$capturedPrompt, $c1) {
        $capturedPrompt = $prompt;

        return ['results' => [
            ['capture_id' => $c1->id, 'is_job' => true, 'result' => 'relevant', 'reason' => 'ok'],
        ]];
    });

    app(JobTriageService::class)->triageBatch(collect([$c1]), $user);

    $payload = json_decode($capturedPrompt, true);

    expect($payload['captures'][0]['id'])->toBe($c1->id)
        ->and($payload['preferences'])->toBe(['Laravel'])
        ->and($payload['rejections'])->toBe(['Tech Lead']);
});

it('marks captures without a valid decision as failed', function () {
    $captures = triageCaptures(2);
    [$c1, $c2] = $captures->all();
    $user = $c1->user;

    JobTriageAgent::fake([[
        'results' => [
            ['capture_id' => $c1->id, 'is_job' => true, 'result' => 'relevant', 'reason' => 'ok'],
        ],
    ]]);

    app(JobTriageService::class)->triageBatch(collect([$c1, $c2]), $user);

    expect($c1->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Completed)
        ->and($c2->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Failed)
        ->and($c2->refresh()->triage_result)->toBeNull()
        ->and($c2->refresh()->triage_reason)->toBeNull()
        ->and($c2->refresh()->triage_error)->toBe('A resposta da IA não contém um resultado válido para esta captura.');
});

it('marks all captures failed when the response is not valid JSON', function () {
    $captures = triageCaptures(2);
    $user = $captures->first()->user;

    JobTriageAgent::fake(['isto não é um JSON válido']);

    app(JobTriageService::class)->triageBatch($captures, $user);

    foreach ($captures as $capture) {
        expect($capture->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Failed)
            ->and($capture->refresh()->triage_result)->toBeNull()
            ->and($capture->refresh()->triage_error)->not->toBeNull();
    }
});

it('marks all captures failed when the provider throws', function () {
    $captures = triageCaptures(2);
    $user = $captures->first()->user;

    JobTriageAgent::fake(fn () => throw new RuntimeException('Provider indisponível'));

    app(JobTriageService::class)->triageBatch($captures, $user);

    foreach ($captures as $capture) {
        expect($capture->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Failed)
            ->and($capture->refresh()->triage_error)->toContain('Provider indisponível');
    }
});
