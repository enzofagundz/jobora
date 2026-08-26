<?php

use App\Agents\JobTriageAgent;
use App\Enums\JobCaptureTriageResult;
use App\Models\JobCapture;
use App\Models\ScreeningPreference;
use App\Models\User;
use App\Services\JobTriageService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function screeningTriageCapture(?User $user = null): JobCapture
{
    $factory = JobCapture::factory();

    if ($user !== null) {
        $factory = $factory->for($user, 'user');
    }

    return $factory->create([
        'description' => str_repeat('Vaga para desenvolvedor PHP com Laravel. ', 5),
    ]);
}

function screeningTriagePrompt(JobCapture $capture, ?User $user): array
{
    if ($user !== null && $capture->user_id !== $user->id) {
        $capture->forceFill(['user_id' => $user->id])->save();
        $capture->refresh();
    }

    $captured = null;

    JobTriageAgent::fake(function (string $prompt) use (&$captured, $capture) {
        $captured = $prompt;

        return ['results' => [
            ['capture_id' => $capture->id, 'is_job' => true, 'result' => 'relevant', 'reason' => 'ok'],
        ]];
    });

    app(JobTriageService::class)->triageBatch(collect([$capture]), $user);

    return json_decode($captured, true);
}

it('envia as preferências do usuário no payload', function () {
    $user = User::factory()->create();
    ScreeningPreference::factory()->create([
        'user_id' => $user->id,
        'preferences' => ['PHP', 'Laravel'],
        'rejections' => [],
    ]);

    $payload = screeningTriagePrompt(screeningTriageCapture($user), $user);

    expect($payload['preferences'])->toBe(['PHP', 'Laravel']);
});

it('envia as rejeições do usuário no payload', function () {
    $user = User::factory()->create();
    ScreeningPreference::factory()->create([
        'user_id' => $user->id,
        'preferences' => [],
        'rejections' => ['presencial', 'estágio'],
    ]);

    $payload = screeningTriagePrompt(screeningTriageCapture($user), $user);

    expect($payload['rejections'])->toBe(['presencial', 'estágio']);
});

it('cada usuário recebe somente os próprios critérios', function () {
    $userA = User::factory()->create();
    ScreeningPreference::factory()->create([
        'user_id' => $userA->id,
        'preferences' => ['PHP'],
        'rejections' => ['presencial'],
    ]);

    $userB = User::factory()->create();
    ScreeningPreference::factory()->create([
        'user_id' => $userB->id,
        'preferences' => ['Python'],
        'rejections' => ['remoto'],
    ]);

    $captureA = screeningTriageCapture($userA);
    $captureB = screeningTriageCapture($userB);

    $payloadA = screeningTriagePrompt($captureA, $userA);
    $payloadB = screeningTriagePrompt($captureB, $userB);

    expect($payloadA['preferences'])->toBe(['PHP'])
        ->and($payloadA['rejections'])->toBe(['presencial'])
        ->and($payloadB['preferences'])->toBe(['Python'])
        ->and($payloadB['rejections'])->toBe(['remoto']);
});

it('mesma captura pode produzir decisões diferentes dependendo do usuário', function () {
    $userA = User::factory()->create();
    ScreeningPreference::factory()->create([
        'user_id' => $userA->id,
        'preferences' => ['PHP', 'Laravel'],
        'rejections' => ['presencial'],
    ]);

    $userB = User::factory()->create();
    ScreeningPreference::factory()->create([
        'user_id' => $userB->id,
        'preferences' => ['Python', 'Django'],
        'rejections' => ['remoto'],
    ]);

    $captureA = screeningTriageCapture($userA);
    $captureB = screeningTriageCapture($userB);
    $captureB->forceFill(['description' => $captureA->description])->save();

    // Fake reage aos critérios do payload: prefere PHP → relevant; senão → rejected.
    JobTriageAgent::fake(function (string $prompt) use ($captureA) {
        $payload = json_decode($prompt, true);
        $result = in_array('PHP', $payload['preferences'], true) ? 'relevant' : 'rejected';

        // Captura id é o da requisição corrente; deduzir pelo prompt não é necessário: usar o primeiro id do payload
        $payloadIds = json_decode($prompt, true)['captures'] ?? [];
        $captureId = $payloadIds[0]['id'] ?? $captureA->id;

        return ['results' => [
            ['capture_id' => $captureId, 'is_job' => true, 'result' => $result, 'reason' => 'critério do usuário'],
        ]];
    });

    $service = app(JobTriageService::class);

    $service->triageBatch(collect([$captureA]), $userA);
    expect($captureA->refresh()->triage_result)->toBe(JobCaptureTriageResult::Relevant);

    $service->triageBatch(collect([$captureB]), $userB);
    expect($captureB->refresh()->triage_result)->toBe(JobCaptureTriageResult::Rejected);
});

it('usuário sem critérios configurados envia listas vazias', function () {
    $user = User::factory()->create();

    $payload = screeningTriagePrompt(screeningTriageCapture($user), $user);

    expect($payload['preferences'])->toBe([])
        ->and($payload['rejections'])->toBe([]);
});

it('mantém a prioridade das rejeições sobre preferências no prompt do agente', function () {
    $instructions = app(JobTriageAgent::class)->instructions();

    expect($instructions)->toContain('CONFLITO')
        ->and($instructions)->toContain('prioridade sobre qualquer preferência');
});

it('persiste preferências e rejeições como arrays', function () {
    $criteria = ScreeningPreference::factory()->create([
        'preferences' => ['PHP', 'remoto'],
        'rejections' => ['presencial'],
    ]);

    $fresh = ScreeningPreference::find($criteria->id);

    expect($fresh->preferences)->toBe(['PHP', 'remoto'])
        ->and($fresh->rejections)->toBe(['presencial']);
});
