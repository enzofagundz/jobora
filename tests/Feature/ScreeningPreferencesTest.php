<?php

use App\Agents\JobTriageAgent;
use App\Enums\JobCaptureTriageResult;
use App\Models\JobCapture;
use App\Models\ScreeningPreference;
use App\Models\User;
use App\Services\JobTriageService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function screeningTriageCapture(): JobCapture
{
    return JobCapture::factory()->create([
        'description' => str_repeat('Vaga para desenvolvedor PHP com Laravel. ', 5),
    ]);
}

function screeningTriagePrompt(JobCapture $capture, ?User $user): array
{
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

    $payload = screeningTriagePrompt(screeningTriageCapture(), $user);

    expect($payload['preferences'])->toBe(['PHP', 'Laravel']);
});

it('envia as rejeições do usuário no payload', function () {
    $user = User::factory()->create();
    ScreeningPreference::factory()->create([
        'user_id' => $user->id,
        'preferences' => [],
        'rejections' => ['presencial', 'estágio'],
    ]);

    $payload = screeningTriagePrompt(screeningTriageCapture(), $user);

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

    $capture = screeningTriageCapture();

    $payloadA = screeningTriagePrompt($capture, $userA);
    $payloadB = screeningTriagePrompt($capture, $userB);

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

    $capture = screeningTriageCapture();

    // Fake reage aos critérios do payload: prefere PHP → relevant; senão → rejected.
    JobTriageAgent::fake(function (string $prompt) use ($capture) {
        $payload = json_decode($prompt, true);
        $result = in_array('PHP', $payload['preferences'], true) ? 'relevant' : 'rejected';

        return ['results' => [
            ['capture_id' => $capture->id, 'is_job' => true, 'result' => $result, 'reason' => 'critério do usuário'],
        ]];
    });

    $service = app(JobTriageService::class);

    $service->triageBatch(collect([$capture]), $userA);
    expect($capture->refresh()->triage_result)->toBe(JobCaptureTriageResult::Relevant);

    $service->triageBatch(collect([$capture->refresh()]), $userB);
    expect($capture->refresh()->triage_result)->toBe(JobCaptureTriageResult::Rejected);
});

it('usuário sem critérios configurados envia listas vazias', function () {
    $user = User::factory()->create();

    $payload = screeningTriagePrompt(screeningTriageCapture(), $user);

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
