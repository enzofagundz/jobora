<?php

use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Jobs\ProcessAiOperation;
use App\Models\AiOperation;
use App\Models\JobPosting;
use App\Models\MatchingEvaluation;
use App\Models\OpportunitySource;
use App\Models\Publication;
use App\Models\PublicationTriageResult;
use App\Models\User;
use App\Services\ProfessionalProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;

uses(RefreshDatabase::class);

function matchingCases(): array
{
    return json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/matching-v1.json'), true, 512, JSON_THROW_ON_ERROR)['cases'];
}

function matchingSetup(object $test, array $case, bool $confirmed = true): void
{
    Http::preventStrayRequests();
    config(['queue.default' => 'database', 'ai.providers.opencode_go.key' => 'test-only-key']);
    $test->owner = User::factory()->create();
    $client = Helpers::client();
    $test->integration = Helpers::integration($test->owner, $client, ['matching:read', 'matching:write', 'operations:read']);
    $test->token = Helpers::accessToken($test->owner, $client);
    $profiles = app(ProfessionalProfileService::class);
    $profiles->saveDraft($test->owner, $case['frozen']['profile']['snapshot'], 0);
    if ($confirmed) {
        $profiles->confirm($test->owner, 1);
        $profiles->saveDraft($test->owner, $case['frozen']['profile']['snapshot'], 2);
        $profiles->confirm($test->owner, 3);
    }
    $test->posting = JobPosting::factory()->for($test->owner)->create([...$case['frozen']['opportunity']['snapshot'], 'opportunity_version' => 3]);
    foreach ($case['frozen']['provenance'] as $provenance) {
        $publication = Publication::factory()->for($test->owner)->create(['content' => 'Conteúdo recapturado que não deve ser usado.']);
        $operation = AiOperation::query()->create([
            'user_id' => $test->owner->id, 'operation_key' => 'fixture:'.Str::uuid(), 'operation_type' => AiOperationType::PublicationTriage,
            'target_type' => 'publication', 'target_id' => $publication->id, 'status' => AiOperationStatus::Completed,
            'queued_at' => now(),
            'run_token' => (string) Str::uuid(), 'attempt_token' => (string) Str::uuid(), 'expires_at' => now()->addMinutes(5),
            'input_fingerprint' => str_repeat('a', 64), 'input_snapshot' => [
                'publication_id' => $publication->id, 'content_version' => $provenance['version'],
                'content' => $provenance['snapshot']['content'], 'content_hash' => hash('sha256', $provenance['snapshot']['content']),
            ],
        ]);
        $result = PublicationTriageResult::query()->create([
            'ai_operation_id' => $operation->id, 'publication_id' => $publication->id, 'content_version' => $provenance['version'],
            'run_token' => $operation->run_token, 'attempt_token' => $operation->attempt_token, 'attempt' => 1,
            'status' => 'completed', 'provider' => 'opencode_go', 'model' => 'deepseek-v4.1-flash', 'schema_version' => '1',
            'input_fingerprint' => str_repeat('a', 64), 'output' => [],
        ]);
        OpportunitySource::factory()->create([
            'job_posting_id' => $test->posting->id, 'publication_id' => $publication->id,
            'publication_content_version' => $provenance['version'], 'publication_triage_result_id' => $result->id,
        ]);
    }
}

function matchingCall(object $test, string $tool, array $arguments): array
{
    app('auth')->forgetGuards();

    return Helpers::decodeToolResult($test->withToken($test->token)->postJson('/mcp', Helpers::toolCall($tool, $arguments))->assertOk()->getContent());
}

function matchingRequest(object $test, array $overrides = []): array
{
    return matchingCall($test, 'request_matching', array_replace([
        'opportunity_id' => $test->posting->id, 'expected_opportunity_version' => 3, 'expected_profile_confirmed_version' => 4,
    ], $overrides));
}

function matchingFixtureResponse(array $case, array $payload): array
{
    $frozen = $payload['frozen'];
    $criteria = $case['expected']['criteria'];
    $fixtureAnchors = array_column($case['frozen']['anchors'], null, 'id');
    foreach ($criteria as &$criterion) {
        $criterion['explanation'] = 'Justificativa livre do modelo.';
        foreach ($criterion['evidence'] as &$evidence) {
            $original = $fixtureAnchors[$evidence['anchor_id']];
            $anchor = collect($frozen['anchors'])->first(fn (array $anchor): bool => $anchor['source'] === $original['source'] && $anchor['path'] === $original['path']);
            $evidence['anchor_id'] = $anchor['id'];
        }
        unset($evidence);
    }
    unset($criterion);

    return [
        'schema_version' => 'matching-result-v1', 'criteria_version' => 'matching-v1', 'input_fingerprint' => $payload['input_fingerprint'],
        'basis' => [
            'profile_id' => $frozen['profile']['id'], 'confirmed_version' => $frozen['profile']['confirmed_version'],
            'opportunity_id' => $frozen['opportunity']['id'], 'opportunity_version' => $frozen['opportunity']['opportunity_version'],
            'profile_hash' => $frozen['profile']['hash'], 'opportunity_hash' => $frozen['opportunity']['hash'],
        ], 'criteria' => $criteria, 'summary' => $case['expected']['summary'],
    ];
}

function matchingProvider(array $case, ?Closure $mutate = null): void
{
    Http::fake(function ($request) use ($case, $mutate) {
        $messages = $request['messages'];
        $payload = json_decode($messages[array_key_last($messages)]['content'], true, 512, JSON_THROW_ON_ERROR);
        $response = matchingFixtureResponse($case, $payload);
        $response = $mutate === null ? $response : $mutate($response, $payload);

        return Http::response(['choices' => [['message' => ['content' => is_string($response) ? $response : json_encode($response)], 'finish_reason' => 'stop']]]);
    });
}

function matchingWork(): void
{
    for ($index = 0; $index < 5; $index++) {
        $next = DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<=', now()->timestamp)->orderBy('id')->first();
        if ($next === null || json_decode($next->payload, true)['displayName'] === ProcessAiOperation::class) {
            break;
        }
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 3]);
    }
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 3]);
}

function matchingPendingJobs(): array
{
    return DB::table('jobs')->get()->filter(fn ($job): bool => json_decode($job->payload, true)['displayName'] === ProcessAiOperation::class)->all();
}

it('evaluates the approved labelled fixture through authenticated MCP and a real worker', function (array $case) {
    matchingSetup($this, $case);
    $request = matchingRequest($this);
    expect($request['ok'] ?? null)->toBeTrue();
    Http::assertNothingSent();
    matchingProvider($case);
    matchingWork();
    $operation = matchingCall($this, 'get_ai_operation', ['id' => $request['data']['operation_id']]);
    expect($operation['data']['status'])->toBe('completed');
    $read = matchingCall($this, 'get_matching', ['id' => $operation['data']['matching_evaluation_id']]);
    expect($read['data']['stale'])->toBeFalse()->and($read['data']['result']['summary'])->toBe($case['expected']['summary']);
    foreach ($case['expected']['criteria'] as $index => $expected) {
        $actual = $read['data']['result']['criteria'][$index];
        foreach (['id', 'kind', 'status', 'reason_code', 'missing_paths'] as $field) {
            expect($actual[$field])->toBe($expected[$field]);
        }
        expect(array_column($actual['evidence'], 'quote'))->toBe(array_column($expected['evidence'], 'quote'));
    }
    $stored = MatchingEvaluation::query()->sole();
    expect($stored->getRawOriginal('result'))->not->toContain('Justificativa')
        ->and($stored->prompt_tokens)->toBeNull()->and($stored->completion_tokens)->toBeNull();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://opencode.ai/inference/go/openai/v1/chat/completions' && $request['model'] === 'deepseek-v4.1-flash');
})->with(fn (): array => array_column(array_map(fn (array $case): array => ['name' => $case['id'], 'value' => [$case]], matchingCases()), 'value', 'name'));

it('rejects unconfirmed profiles without reading draft or resume as a fallback', function () {
    matchingSetup($this, matchingCases()[0], confirmed: false);
    $request = matchingRequest($this, ['expected_profile_confirmed_version' => 0]);
    expect($request['error']['code'] ?? null)->toBe('profile_not_confirmed');
    Http::assertNothingSent();
    $this->assertDatabaseCount('matching_evaluations', 0);
    $this->assertDatabaseCount('ai_operations', 0);
});

it('requires readiness ready even for legacy confirmed postings', function (?string $readiness) {
    matchingSetup($this, matchingCases()[0]);
    $this->posting->update(['readiness' => $readiness, 'status' => 'confirmed']);
    expect(matchingRequest($this)['error']['code'] ?? null)->toBe('opportunity_not_ready');
    Http::assertNothingSent();
    $this->assertDatabaseCount('ai_operations', 0);
})->with([null, 'needs_review']);

it('rejects stale expected versions and extra input before dispatch', function () {
    matchingSetup($this, matchingCases()[0]);
    expect(matchingRequest($this, ['expected_opportunity_version' => 2])['error']['code'] ?? null)->toBe('conflict')
        ->and(matchingRequest($this, ['expected_profile_confirmed_version' => 3])['error']['code'] ?? null)->toBe('conflict')
        ->and(matchingRequest($this, ['user_id' => 999])['error']['code'] ?? null)->toBe('validation')
        ->and(matchingRequest($this, ['criteria_version' => 'invented'])['error']['code'] ?? null)->toBe('validation');
    Http::assertNothingSent();
    $this->assertDatabaseCount('ai_operations', 0);
});

it('reuses identical input without dispatch and keeps drafts out of validity', function () {
    $case = matchingCases()[0];
    matchingSetup($this, $case);
    $first = matchingRequest($this);
    expect(matchingRequest($this)['data']['operation_id'])->toBe($first['data']['operation_id']);
    $this->assertDatabaseCount('jobs', 1);
    matchingProvider($case);
    matchingWork();
    $operation = matchingCall($this, 'get_ai_operation', ['id' => $first['data']['operation_id']]);
    $id = $operation['data']['matching_evaluation_id'];
    $before = matchingCall($this, 'get_matching', ['id' => $id])['data'];
    app(ProfessionalProfileService::class)->saveDraft($this->owner, array_replace($case['frozen']['profile']['snapshot'], ['competencies' => ['JavaScript'], 'preferences' => ['Changed draft']]), 4);
    $replay = matchingRequest($this);
    expect($replay['data']['operation_id'])->toBe($first['data']['operation_id'])
        ->and($replay['data']['matching_evaluation_id'])->toBe($id)
        ->and(matchingCall($this, 'get_matching', ['id' => $id])['data'])->toEqual($before);
    expect(matchingPendingJobs())->toHaveCount(0);
    Http::assertSentCount(1);
});

it('keeps stale history after confirmation and opportunity changes without automatic matching', function () {
    $case = matchingCases()[0];
    matchingSetup($this, $case);
    matchingRequest($this);
    matchingProvider($case);
    matchingWork();
    $id = MatchingEvaluation::query()->sole()->id;
    app(ProfessionalProfileService::class)->saveDraft($this->owner, $case['frozen']['profile']['snapshot'], 4);
    app(ProfessionalProfileService::class)->confirm($this->owner, 5);
    $history = matchingCall($this, 'get_matching', ['opportunity_id' => $this->posting->id, 'limit' => 1])['data'];
    expect($history['current'])->toBeNull()->and($history['history']['items'][0]['stale'])->toBeTrue();
    expect(matchingPendingJobs())->toHaveCount(0);
    expect(matchingRequest($this)['error']['code'] ?? null)->toBe('conflict');
    matchingRequest($this, ['expected_profile_confirmed_version' => 6]);
    matchingWork();
    $history = matchingCall($this, 'get_matching', ['opportunity_id' => $this->posting->id, 'limit' => 1])['data'];
    expect($history['current']['id'])->not->toBe($id)
        ->and($history['history']['total'])->toBe(2)->and($history['history']['next_offset'])->toBe(1);
    $this->posting->update(['opportunity_version' => 4, 'description' => 'Atualizado']);
    expect(matchingCall($this, 'get_matching', ['opportunity_id' => $this->posting->id])['data']['current'])->toBeNull();
    expect(matchingPendingJobs())->toHaveCount(0);
    $this->assertDatabaseCount('matching_evaluations', 2);
});

it('rejects every approved invalid result vector without partial evaluations', function (array $vector) {
    $case = matchingCases()[0];
    matchingSetup($this, $case);
    $request = matchingRequest($this);
    matchingProvider($case, function (array $response) use ($vector): array {
        data_set($response, $vector['path'], $vector['value']);

        return $response;
    });
    matchingWork();
    $operation = matchingCall($this, 'get_ai_operation', ['id' => $request['data']['operation_id']])['data'];
    expect($operation['status'])->toBe('failed')->and($operation['failure_code'])->toBe('invalid_output')
        ->and($operation['matching_evaluation_id'])->toBeNull();
    $this->assertDatabaseCount('matching_evaluations', 0);
})->with(fn (): array => array_map(fn (array $vector): array => [$vector], json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/matching-v1.json'), true, 512, JSON_THROW_ON_ERROR)['invalid_outputs']));

it('fails closed on malformed raw output', function (string $text) {
    matchingSetup($this, matchingCases()[0]);
    $request = matchingRequest($this);
    matchingProvider(matchingCases()[0], fn () => $text);
    matchingWork();
    expect(matchingCall($this, 'get_ai_operation', ['id' => $request['data']['operation_id']])['data']['failure_code'])->toBe('invalid_output');
    $this->assertDatabaseCount('matching_evaluations', 0);
})->with(['not json', '[]', '{"schema_version":"matching-result-v1"}']);

it('enforces owner isolation and explicit matching capabilities', function () {
    $case = matchingCases()[0];
    matchingSetup($this, $case);
    matchingRequest($this);
    matchingProvider($case);
    matchingWork();
    $id = MatchingEvaluation::query()->sole()->id;
    $this->integration->update(['scopes' => ['profile:read', 'profile:write', 'operations:read']]);
    expect(matchingRequest($this)['error']['code'] ?? null)->toBe('forbidden')
        ->and(matchingCall($this, 'get_matching', ['id' => $id])['error']['code'] ?? null)->toBe('forbidden');
    $other = User::factory()->create();
    $client = Helpers::client();
    Helpers::integration($other, $client, ['matching:read', 'matching:write']);
    $this->token = Helpers::accessToken($other, $client);
    expect(matchingRequest($this)['error']['code'] ?? null)->toBe('not_found')
        ->and(matchingCall($this, 'get_matching', ['id' => $id])['error']['code'] ?? null)->toBe('not_found')
        ->and(matchingCall($this, 'get_matching', ['opportunity_id' => $this->posting->id])['error']['code'] ?? null)->toBe('not_found');
    $this->assertDatabaseCount('matching_evaluations', 1);
});

it('revalidates authorization before provider calls', function (string $change) {
    matchingSetup($this, matchingCases()[0]);
    $request = matchingRequest($this);
    match ($change) {
        'disabled' => $this->owner->forceFill(['disabled_at' => now()])->save(),
        'revoked' => $this->integration->update(['revoked_at' => now()]),
        'scope' => $this->integration->update(['scopes' => ['matching:read', 'operations:read']]),
    };
    matchingProvider(matchingCases()[0]);
    matchingWork();
    expect(AiOperation::findOrFail($request['data']['operation_id'])->failure_code)->toBe('authorization_revoked');
    Http::assertNothingSent();
    $this->assertDatabaseCount('matching_evaluations', 0);
})->with(['disabled', 'revoked', 'scope']);

it('revalidates authorization before persisting provider output', function () {
    matchingSetup($this, matchingCases()[0]);
    $request = matchingRequest($this);
    matchingProvider(matchingCases()[0], function (array $response): array {
        $this->integration->update(['revoked_at' => now()]);

        return $response;
    });
    matchingWork();
    expect(AiOperation::findOrFail($request['data']['operation_id'])->failure_code)->toBe('authorization_revoked');
    $this->assertDatabaseCount('matching_evaluations', 0);
});

it('pins triage provenance rather than recaptured publication content', function () {
    $case = collect(matchingCases())->firstWhere('id', 'publication_aggregate_evidence');
    matchingSetup($this, $case);
    $request = matchingRequest($this);
    Publication::query()->update(['content' => 'Outra recaptura sem PHP', 'content_version' => 99]);
    matchingProvider($case);
    matchingWork();
    $operation = matchingCall($this, 'get_ai_operation', ['id' => $request['data']['operation_id']]);
    $evaluation = matchingCall($this, 'get_matching', ['id' => $operation['data']['matching_evaluation_id']])['data'];
    expect($evaluation['stale'])->toBeFalse()
        ->and($evaluation['provenance'][0]['snapshot']['content'])->toBe('Requisito PHP.')
        ->and($evaluation['provenance'][0]['version'])->toBe(1);
});

it('reports absent source snapshots without fabricating publication or DOM anchors', function () {
    $case = matchingCases()[0];
    matchingSetup($this, $case);
    $publication = Publication::factory()->for($this->owner)->create();
    $source = OpportunitySource::factory()->create(['job_posting_id' => $this->posting->id, 'publication_id' => $publication->id, 'publication_triage_result_id' => null]);
    matchingRequest($this);
    matchingProvider($case);
    matchingWork();
    $evaluation = matchingCall($this, 'get_matching', ['id' => MatchingEvaluation::query()->sole()->id])['data'];
    expect($evaluation['provenance'])->toBe([])
        ->and($evaluation['provenance_warnings'])->toBe(['source_snapshot_unavailable:'.$source->id])
        ->and(array_column($evaluation['anchors'], 'source'))->not->toContain('publication');
});

it('recovers requested work after lost dispatch without scanning opportunities', function () {
    matchingSetup($this, matchingCases()[0]);
    $request = matchingRequest($this);
    $operation = AiOperation::findOrFail($request['data']['operation_id']);
    DB::table('jobs')->delete();
    $operation->update(['queued_at' => now()->subMinutes(2)]);
    JobPosting::factory()->for($this->owner)->create(['readiness' => 'ready']);
    $this->artisan('matching:recover')->assertSuccessful();
    matchingProvider(matchingCases()[0]);
    matchingWork();
    expect(matchingCall($this, 'get_ai_operation', ['id' => $operation->id])['data']['status'])->toBe('completed');
    $this->assertDatabaseCount('matching_evaluations', 1);
    $this->assertDatabaseCount('ai_operations', 1);
});

it('fences the provider response after recovery reclaims its attempt', function () {
    matchingSetup($this, matchingCases()[0]);
    $request = matchingRequest($this);
    $operation = AiOperation::findOrFail($request['data']['operation_id']);
    $attempt = $operation->attempt_token;
    $recovered = false;
    matchingProvider(matchingCases()[0], function (array $response) use ($operation, &$recovered): array {
        if (! $recovered) {
            $recovered = true;
            $operation->update(['last_attempt_started_at' => now()->subSeconds(140)]);
            Artisan::call('matching:recover');
        }

        return $response;
    });
    matchingWork();
    expect($operation->refresh()->attempt_token)->not->toBe($attempt);
    $this->assertDatabaseCount('matching_evaluations', 0);
    matchingWork();
    expect(matchingCall($this, 'get_ai_operation', ['id' => $operation->id])['data']['status'])->toBe('completed');
    $this->assertDatabaseCount('matching_evaluations', 1);
});

it('never persists a response after operation expiry', function () {
    matchingSetup($this, matchingCases()[0]);
    $request = matchingRequest($this);
    matchingProvider(matchingCases()[0], function (array $response) use ($request): array {
        AiOperation::findOrFail($request['data']['operation_id'])->update(['expires_at' => now()->subSecond()]);

        return $response;
    });
    matchingWork();
    expect(matchingCall($this, 'get_ai_operation', ['id' => $request['data']['operation_id']])['data']['failure_code'])->toBe('worker_unavailable');
    $this->assertDatabaseCount('matching_evaluations', 0);
});

it('bounds explicit retries and exposes when the failed basis can no longer be retried', function () {
    matchingSetup($this, matchingCases()[0]);
    $request = matchingRequest($this);
    matchingProvider(matchingCases()[0], fn () => 'invalid output');
    matchingWork();
    expect(matchingCall($this, 'get_ai_operation', ['id' => $request['data']['operation_id']])['data']['retry_allowed'])->toBeTrue();
    for ($attempt = 2; $attempt <= 3; $attempt++) {
        expect(matchingRequest($this, ['retry' => true])['data']['operation_id'])->toBe($request['data']['operation_id']);
        matchingWork();
    }
    expect(matchingCall($this, 'get_ai_operation', ['id' => $request['data']['operation_id']])['data']['retry_allowed'])->toBeFalse()
        ->and(matchingRequest($this, ['retry' => true])['error']['code'] ?? null)->toBe('precondition');
    $this->assertDatabaseCount('matching_evaluations', 0);
});

it('does not advertise retry for a failed evaluation after the profile is confirmed again', function () {
    matchingSetup($this, matchingCases()[0]);
    $request = matchingRequest($this);
    matchingProvider(matchingCases()[0], fn () => 'invalid');
    matchingWork();
    app(ProfessionalProfileService::class)->saveDraft($this->owner, matchingCases()[0]['frozen']['profile']['snapshot'], 4);
    app(ProfessionalProfileService::class)->confirm($this->owner, 5);
    expect(matchingCall($this, 'get_ai_operation', ['id' => $request['data']['operation_id']])['data']['retry_allowed'])->toBeFalse();
    expect(matchingPendingJobs())->toHaveCount(0);
});

it('rejects technical token substitutions rather than accepting partial names as met', function (string $actual, string $required, string $quote) {
    $case = matchingCases()[0];
    $case['frozen']['profile']['snapshot']['competencies'] = [$actual];
    $case['frozen']['opportunity']['snapshot']['requirements'] = [$required];
    $case['expected']['criteria'][0]['evidence'][0]['quote'] = $quote;
    $case['expected']['criteria'][0]['evidence'][1]['quote'] = $required;
    matchingSetup($this, $case);
    $request = matchingRequest($this);
    matchingProvider($case);
    matchingWork();
    expect(matchingCall($this, 'get_ai_operation', ['id' => $request['data']['operation_id']])['data']['failure_code'])->toBe('invalid_output');
    $this->assertDatabaseCount('matching_evaluations', 0);
})->with([
    ['JavaScript', 'Java', 'JavaScript'], ['JavaScript', 'Java', 'Java'],
    ['JAVASCRIPT', 'Java', 'JAVASCRIPT'], ['jAvAsCrIpT', 'Java', 'jAvAsCrIpT'],
    ['préJAVASCRIPT', 'Java', 'préJAVASCRIPT'],
    ['C++', 'C', 'C++'], ['C++', 'C', 'C'], ['C#', 'C', 'C#'], ['C#', 'C', 'C'],
    ['Node.js', 'Node', 'Node.js'], ['Node.js', 'Node', 'Node'], ['.NET', 'NET', '.NET'], ['.NET', 'NET', 'NET'],
    ['NODE.JS', 'Node', 'NODE.JS'], ['nOdE.Js', 'Node', 'nOdE.Js'],
]);

it('accepts complete technical terms as literal bilateral support', function (string $term) {
    $case = matchingCases()[0];
    $case['frozen']['profile']['snapshot']['competencies'] = [$term];
    $case['frozen']['opportunity']['snapshot']['requirements'] = [$term];
    $case['expected']['criteria'][0]['evidence'][0]['quote'] = $term;
    $case['expected']['criteria'][0]['evidence'][1]['quote'] = $term;
    matchingSetup($this, $case);
    $request = matchingRequest($this);
    matchingProvider($case);
    matchingWork();
    expect(matchingCall($this, 'get_ai_operation', ['id' => $request['data']['operation_id']])['data']['status'])->toBe('completed');
})->with(['Java', 'C', 'C++', 'C#', 'Node.js', '.NET']);

it('freezes explicit role and employment conditions as textual anchors', function () {
    $case = matchingCases()[0];
    matchingSetup($this, $case);
    $this->posting->update(['role' => 'Desenvolvedor PHP', 'company' => 'Empresa exemplo', 'employment_type' => 'CLT']);
    matchingRequest($this);
    matchingProvider($case);
    matchingWork();
    $read = matchingCall($this, 'get_matching', ['id' => MatchingEvaluation::query()->sole()->id])['data'];
    $anchors = array_column($read['anchors'], 'text', 'path');
    expect($anchors)->toHaveKeys(['/role', '/company', '/employment_type'])
        ->and($anchors['/employment_type'])->toBe('CLT');
});

it('marks configuration and source hash changes stale without automatic dispatch', function () {
    matchingSetup($this, matchingCases()[0]);
    matchingRequest($this);
    matchingProvider(matchingCases()[0]);
    matchingWork();
    $id = MatchingEvaluation::query()->sole()->id;
    foreach (['criteria_version' => 'matching-v2', 'schema_version' => 'matching-result-v2', 'model' => 'new-model'] as $field => $value) {
        $original = config('ai.matching.'.$field);
        config(['ai.matching.'.$field => $value]);
        expect(matchingCall($this, 'get_matching', ['id' => $id])['data']['stale'])->toBeTrue();
        config(['ai.matching.'.$field => $original]);
    }
    $this->posting->update(['description' => 'Mudança sem incremento indevido de versão']);
    expect(matchingCall($this, 'get_matching', ['id' => $id])['data']['stale'])->toBeTrue()
        ->and(matchingPendingJobs())->toHaveCount(0);
});

it('never presents a late result as current after a new confirmed basis is requested', function () {
    $case = matchingCases()[0];
    matchingSetup($this, $case);
    $first = matchingRequest($this);
    $changed = false;
    matchingProvider($case, function (array $response) use ($case, &$changed): array {
        if (! $changed) {
            $changed = true;
            app(ProfessionalProfileService::class)->saveDraft($this->owner, $case['frozen']['profile']['snapshot'], 4);
            app(ProfessionalProfileService::class)->confirm($this->owner, 5);
            matchingRequest($this, ['expected_profile_confirmed_version' => 6]);
        }

        return $response;
    });
    matchingWork();
    $history = matchingCall($this, 'get_matching', ['opportunity_id' => $this->posting->id])['data'];
    expect($history['current'])->toBeNull()->and($history['history']['items'][0]['stale'])->toBeTrue();
    matchingWork();
    $history = matchingCall($this, 'get_matching', ['opportunity_id' => $this->posting->id])['data'];
    expect($history['current']['operation_id'])->not->toBe($first['data']['operation_id'])
        ->and($history['current']['result']['basis']['confirmed_version'])->toBe(6)
        ->and($history['history']['total'])->toBe(2);
});

it('does not fall back or redirect after provider failure', function (int $status) {
    matchingSetup($this, matchingCases()[0]);
    $request = matchingRequest($this);
    $default = config('ai.default');
    $options = [];
    Http::fake(function ($request, array $sentOptions) use ($status, &$options) {
        $options = $sentOptions;

        return Http::response(['error' => ['message' => 'provider unavailable']], $status);
    });
    matchingWork();
    $read = matchingCall($this, 'get_ai_operation', ['id' => $request['data']['operation_id']])['data'];
    expect($read['status'])->toBe('queued')->and($read['failure_code'])->toBe('retrying')
        ->and($options['allow_redirects'])->toBeFalse()->and(config('ai.default'))->toBe($default);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://opencode.ai/inference/go/openai/v1/chat/completions');
    $this->assertDatabaseCount('matching_evaluations', 0);
})->with([302, 402, 429, 500]);

it('bounds automatic provider retries and fails consultably', function () {
    matchingSetup($this, matchingCases()[0]);
    $request = matchingRequest($this);
    Http::fake(['*' => Http::response(['error' => ['message' => 'unavailable']], 500)]);
    matchingWork();
    $this->travel(11)->seconds();
    matchingWork();
    $this->travel(31)->seconds();
    matchingWork();
    $read = matchingCall($this, 'get_ai_operation', ['id' => $request['data']['operation_id']])['data'];
    expect($read['status'])->toBe('failed')->and($read['attempts'])->toBe(3)
        ->and($read['failure_code'])->toBe('processing_failed')->and($read['retry_allowed'])->toBeFalse();
    Http::assertSentCount(3);
    $this->assertDatabaseCount('matching_evaluations', 0);
});

it('ignores a queue message from a previous run after explicit retry', function () {
    matchingSetup($this, matchingCases()[0]);
    $request = matchingRequest($this);
    $operation = AiOperation::findOrFail($request['data']['operation_id']);
    $oldRun = $operation->run_token;
    $oldAttempt = $operation->attempt_token;
    $calls = 0;
    matchingProvider(matchingCases()[0], function (array $response) use (&$calls): array|string {
        return ++$calls === 1 ? 'invalid output' : $response;
    });
    matchingWork();
    matchingRequest($this, ['retry' => true]);
    ProcessAiOperation::dispatch($operation->id, $oldRun, $oldAttempt);
    matchingWork();
    matchingWork();
    $this->assertDatabaseCount('matching_evaluations', 1);
    Http::assertSentCount(2);
    expect(matchingCall($this, 'get_ai_operation', ['id' => $operation->id])['data']['status'])->toBe('completed');
});

it('expires undispatched operations without sending provider requests', function () {
    matchingSetup($this, matchingCases()[0]);
    $request = matchingRequest($this);
    AiOperation::findOrFail($request['data']['operation_id'])->update(['expires_at' => now()->subSecond()]);
    $this->artisan('matching:recover')->assertSuccessful();
    expect(matchingCall($this, 'get_ai_operation', ['id' => $request['data']['operation_id']])['data']['failure_code'])->toBe('worker_unavailable');
    matchingProvider(matchingCases()[0]);
    matchingWork();
    Http::assertNothingSent();
    $this->assertDatabaseCount('matching_evaluations', 0);
});

it('rejects unsupported status and path combinations as a whole', function (string $path, mixed $value) {
    matchingSetup($this, matchingCases()[0]);
    $request = matchingRequest($this);
    matchingProvider(matchingCases()[0], function (array $response) use ($path, $value): array {
        data_set($response, $path, $value);

        return $response;
    });
    matchingWork();
    expect(matchingCall($this, 'get_ai_operation', ['id' => $request['data']['operation_id']])['data']['failure_code'])->toBe('invalid_output');
    $this->assertDatabaseCount('matching_evaluations', 0);
})->with([
    ['criteria.1.status', 'incompatible'], ['criteria.1.status', 'invented'],
    ['criteria.1.missing_paths', ['profile:/invented']], ['criteria.1.reason_code', 'invented'],
    ['criteria.0.missing_paths', ['profile:/competencies']], ['criteria.0.confidence', 100],
    ['criteria.0.evidence.0.snapshot_hash', str_repeat('a', 64)], ['schema_version', 'invented'],
]);
