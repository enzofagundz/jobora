<?php

use App\Jobs\ProcessAiOperation;
use App\Models\AiOperation;
use App\Models\ProfileImportProposal;
use App\Models\Resume;
use App\Models\User;
use App\Services\ProfessionalProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['queue.default' => 'database', 'ai.providers.opencode_go.key' => 'test-only-key']);
    $this->owner = User::factory()->create();
    $client = Helpers::client();
    $this->integration = Helpers::integration($this->owner, $client, ['profile:read', 'profile:write', 'operations:read']);
    $this->token = Helpers::accessToken($this->owner, $client);
    $service = app(ProfessionalProfileService::class);
    $service->saveDraft($this->owner, [
        'experiences' => [], 'education' => [], 'competencies' => ['Old'],
        'preferences' => ['Remoto'], 'restrictions' => ['Viagens'], 'compensation_expectation' => 'R$ 10000',
    ], 0);
    $this->base = $service->confirm($this->owner, 1);
    $this->resume = Resume::factory()->for($this->owner)->create(['language' => 'pt-BR', 'content' => "Acme PHP\nUniversidade X"]);
});

function importCall(object $test, string $tool, array $arguments = []): array
{
    app('auth')->forgetGuards();

    return Helpers::decodeToolResult($test->withToken($test->token)->postJson('/mcp', Helpers::toolCall($tool, $arguments))->assertOk()->getContent());
}

function importOutput(): array
{
    return [
        'candidate' => [
            'experiences' => [['company' => 'Acme', 'role' => null, 'period' => null, 'description' => null]],
            'education' => [['institution' => 'Universidade X', 'qualification' => null, 'period' => null]], 'competencies' => ['PHP'],
        ],
        'evidence' => [
            ['path' => 'experiences.0.company', 'block_id' => 'block-1', 'quote' => 'Acme PHP'],
            ['path' => 'competencies.0', 'block_id' => 'block-1', 'quote' => 'Acme PHP'],
            ['path' => 'education.0.institution', 'block_id' => 'block-2', 'quote' => 'Universidade X'],
        ],
    ];
}

function importProvider(array|string $output): void
{
    Http::fake(['*' => Http::response([
        'choices' => [['message' => ['content' => is_array($output) ? json_encode($output) : $output], 'finish_reason' => 'stop']],
        'model' => 'deepseek-v4.1-flash',
    ])]);
}

function importWork(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 3]);
}

function importProposal(object $test, string $language = 'pt-BR'): array
{
    $request = importCall($test, 'request_profile_import', ['expected_version' => 2, 'language' => $language]);
    importProvider(importOutput());
    importWork();

    return importCall($test, 'get_ai_operation', ['id' => $request['data']['operation_id']])['data'];
}

it('extracts visible legacy HTML text and retains the raw source fingerprint', function () {
    $raw = '<p class="PHP">R&amp;D</p>';
    $this->resume->update(['content' => $raw]);
    $request = importCall($this, 'request_profile_import', ['expected_version' => 2]);
    importProvider([
        'candidate' => ['experiences' => [], 'education' => [], 'competencies' => ['R&D']],
        'evidence' => [['path' => 'competencies.0', 'block_id' => 'block-1', 'quote' => 'R&D']],
    ]);
    importWork();
    $operation = importCall($this, 'get_ai_operation', ['id' => $request['data']['operation_id']]);
    expect($operation['data']['status'])->toBe('completed');
    $proposal = importCall($this, 'get_profile_import', ['id' => $operation['data']['profile_import_proposal_id']]);
    expect($proposal['data']['source']['fingerprint'])->toBe(hash('sha256', $raw))
        ->and($proposal['data']['source']['source_stale'])->toBeFalse()
        ->and($proposal['data']['candidate']['competencies'])->toBe(['R&D']);
    Http::assertSent(fn ($request): bool => str_contains(json_encode($request['messages']), 'R&D')
        && ! str_contains(json_encode($request['messages']), 'PHP'));
    expect(app(ProfessionalProfileService::class)->get($this->owner))->toEqual($this->base);
});

it('rejects facts supported only by legacy HTML attributes', function () {
    $raw = '<p class="PHP">Enfermeiro</p>';
    $this->resume->update(['content' => $raw]);
    $request = importCall($this, 'request_profile_import', ['expected_version' => 2]);
    importProvider([
        'candidate' => ['experiences' => [], 'education' => [], 'competencies' => ['PHP']],
        'evidence' => [['path' => 'competencies.0', 'block_id' => 'block-1', 'quote' => $raw]],
    ]);
    importWork();
    expect(importCall($this, 'get_ai_operation', ['id' => $request['data']['operation_id']])['data']['failure_code'])->toBe('invalid_output');
    $this->assertDatabaseCount('profile_import_proposals', 0);
    expect(app(ProfessionalProfileService::class)->get($this->owner))->toEqual($this->base);
});

it('rejects legacy markup without visible content before creating any data', function () {
    $other = User::factory()->create();
    $client = Helpers::client();
    Helpers::integration($other, $client, ['profile:write']);
    $this->token = Helpers::accessToken($other, $client);
    Resume::factory()->for($other)->create(['language' => 'pt-BR', 'content' => '<p class="PHP"> </p>']);
    expect(importCall($this, 'request_profile_import', ['expected_version' => 0])['error']['code'] ?? null)->toBe('precondition')
        ->and($other->professionalProfile()->exists())->toBeFalse();
    $this->assertDatabaseCount('ai_operations', 0);
    Http::assertNothingSent();
});

it('rejects embedded words as factual support even when the quote crops the surrounding word', function (string $content, string $quote) {
    $this->resume->update(['content' => $content]);
    $request = importCall($this, 'request_profile_import', ['expected_version' => 2]);
    importProvider([
        'candidate' => ['experiences' => [], 'education' => [], 'competencies' => ['Java']],
        'evidence' => [['path' => 'competencies.0', 'block_id' => 'block-1', 'quote' => $quote]],
    ]);
    importWork();
    expect(importCall($this, 'get_ai_operation', ['id' => $request['data']['operation_id']])['data']['failure_code'])->toBe('invalid_output');
    $this->assertDatabaseCount('profile_import_proposals', 0);
    expect(app(ProfessionalProfileService::class)->get($this->owner))->toEqual($this->base);
})->with([
    'full word' => ['JavaScript', 'JavaScript'],
    'cropped quote' => ['JavaScript', 'Java'],
    'unicode prefix' => ['préJava', 'préJava'],
    'unicode suffix' => ['Javação', 'Javação'],
    'combining mark' => ["Java\u{0301}", "Java\u{0301}"],
]);

it('accepts literal technical names and standalone Java with Unicode lexical boundaries', function () {
    $content = 'C++, .NET; Node.js / Java.';
    $this->resume->update(['content' => $content]);
    $request = importCall($this, 'request_profile_import', ['expected_version' => 2]);
    $competencies = ['C++', '.NET', 'Node.js', 'Java'];
    $evidence = [];
    foreach ($competencies as $index => $competency) {
        $evidence[] = ['path' => "competencies.{$index}", 'block_id' => 'block-1', 'quote' => $content];
    }
    importProvider(['candidate' => ['experiences' => [], 'education' => [], 'competencies' => $competencies], 'evidence' => $evidence]);
    importWork();
    $operation = importCall($this, 'get_ai_operation', ['id' => $request['data']['operation_id']]);
    expect($operation['data']['status'])->toBe('completed');
    expect(importCall($this, 'get_profile_import', ['id' => $operation['data']['profile_import_proposal_id']])['data']['candidate']['competencies'])->toBe($competencies);
});

it('rejects shortened technical tokens even with cropped quotes', function (string $content, string $fact, string $quote) {
    $this->resume->update(['content' => $content]);
    $request = importCall($this, 'request_profile_import', ['expected_version' => 2]);
    importProvider([
        'candidate' => ['experiences' => [], 'education' => [], 'competencies' => [$fact]],
        'evidence' => [['path' => 'competencies.0', 'block_id' => 'block-1', 'quote' => $quote]],
    ]);
    importWork();
    expect(importCall($this, 'get_ai_operation', ['id' => $request['data']['operation_id']])['data']['failure_code'])->toBe('invalid_output');
    $this->assertDatabaseCount('profile_import_proposals', 0);
    expect(app(ProfessionalProfileService::class)->get($this->owner))->toEqual($this->base);
})->with([
    'C++ full quote' => ['C++', 'C', 'C++'],
    'C++ cropped quote' => ['C++', 'C', 'C'],
    'C# full quote' => ['C#', 'C', 'C#'],
    'C# cropped quote' => ['C#', 'C', 'C'],
    'Node.js full quote' => ['Node.js', 'Node', 'Node.js'],
    'Node.js cropped quote' => ['Node.js', 'Node', 'Node'],
    '.NET full quote' => ['.NET', 'NET', '.NET'],
    '.NET cropped quote' => ['.NET', 'NET', 'NET'],
]);

it('accepts complete technical tokens with sentence punctuation', function (string $content, array $competencies) {
    $this->resume->update(['content' => $content]);
    $request = importCall($this, 'request_profile_import', ['expected_version' => 2]);
    $evidence = [];
    foreach ($competencies as $index => $competency) {
        $evidence[] = ['path' => "competencies.{$index}", 'block_id' => 'block-1', 'quote' => $content];
    }
    importProvider(['candidate' => ['experiences' => [], 'education' => [], 'competencies' => $competencies], 'evidence' => $evidence]);
    importWork();
    $operation = importCall($this, 'get_ai_operation', ['id' => $request['data']['operation_id']]);
    expect($operation['data']['status'])->toBe('completed');
    expect(importCall($this, 'get_profile_import', ['id' => $operation['data']['profile_import_proposal_id']])['data']['candidate']['competencies'])->toBe($competencies);
})->with([
    'C++' => ['C++', ['C++']],
    'C#' => ['C#', ['C#']],
    '.NET' => ['.NET', ['.NET']],
    'Node.js' => ['Node.js', ['Node.js']],
    'PHP' => ['PHP', ['PHP']],
    'C++ sentence' => ['C++.', ['C++']],
    'C# sentence' => ['C#.', ['C#']],
    '.NET sentence' => ['.NET.', ['.NET']],
    'Node.js sentence' => ['Node.js.', ['Node.js']],
    'PHP sentence' => ['PHP.', ['PHP']],
    'technical list' => ['PHP, C++, .NET e Node.js.', ['PHP', 'C++', '.NET', 'Node.js']],
]);

it('requests durable extraction and exposes evidence without changing the professional profile', function () {
    $request = importCall($this, 'request_profile_import', ['expected_version' => 2]);
    expect($request['ok'] ?? null)->toBeTrue();
    $operationId = $request['data']['operation_id'];
    expect(importCall($this, 'request_profile_import', ['expected_version' => 2])['data']['operation_id'])->toBe($operationId);
    expect(app(ProfessionalProfileService::class)->get($this->owner))->toEqual($this->base);
    Http::assertNothingSent();

    importProvider(importOutput());
    importWork();
    $operation = importCall($this, 'get_ai_operation', ['id' => $operationId]);
    expect($operation['data']['status'])->toBe('completed');
    $proposal = importCall($this, 'get_profile_import', ['id' => $operation['data']['profile_import_proposal_id']]);
    expect($proposal['data']['status'])->toBe('pending')
        ->and($proposal['data']['candidate'])->toEqual(importOutput()['candidate'])
        ->and($proposal['data']['evidence'])->toEqual(importOutput()['evidence'])
        ->and($proposal['data']['changes']['competencies'])->toBe(['before' => ['Old'], 'after' => ['PHP']])
        ->and($proposal['data']['source']['language'])->toBe('pt-BR')
        ->and($proposal['data']['source']['source_stale'])->toBeFalse()
        ->and($proposal['data']['allowed_actions'])->toBe(['confirm', 'reject'])
        ->and(app(ProfessionalProfileService::class)->get($this->owner))->toEqual($this->base);
    $stored = AiOperation::findOrFail($operationId);
    expect($stored->getRawOriginal('input_snapshot'))->not->toContain('Acme PHP');
    $storedProposal = ProfileImportProposal::findOrFail($operation['data']['profile_import_proposal_id']);
    expect($storedProposal->getRawOriginal('candidate'))->not->toContain('Acme')
        ->and($storedProposal->getRawOriginal('evidence'))->not->toContain('Acme')
        ->and($storedProposal->prompt_tokens)->toBeNull();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://opencode.ai/inference/go/openai/v1/chat/completions' && $request['model'] === 'deepseek-v4.1-flash');
});

it('confirms once with provenance and replaces sections without appending translated facts', function () {
    Resume::factory()->for($this->owner)->create(['language' => 'en', 'content' => "Acme PHP\nUniversidade X"]);
    $original = $this->resume->refresh()->getAttributes();
    $operation = importProposal($this, 'en');
    $id = $operation['profile_import_proposal_id'];
    $confirmed = importCall($this, 'confirm_profile_import', ['id' => $id, 'expected_version' => 2, 'confirm' => true]);
    expect($confirmed['ok'] ?? null)->toBeTrue()
        ->and($confirmed['data']['status'])->toBe('confirmed')
        ->and($confirmed['data']['result_version'])->toBe(4);
    $state = app(ProfessionalProfileService::class)->get($this->owner);
    expect($state['version'])->toBe(4)->and($state['confirmed_version'])->toBe(4)
        ->and($state['draft']['experiences'])->toBe(importOutput()['candidate']['experiences'])
        ->and($state['draft']['preferences'])->toBe(['Remoto'])
        ->and($state['draft']['restrictions'])->toBe(['Viagens'])
        ->and($state['draft']['compensation_expectation'])->toBe('R$ 10000')
        ->and($state['confirmed_snapshot'])->toEqual($state['draft'])
        ->and($this->resume->refresh()->getAttributes())->toBe($original);
    $replay = importCall($this, 'confirm_profile_import', ['id' => $id, 'expected_version' => 2, 'confirm' => true]);
    expect($replay['data']['result_version'])->toBe(4)
        ->and(app(ProfessionalProfileService::class)->get($this->owner))->toEqual($state)
        ->and(importCall($this, 'reject_profile_import', ['id' => $id])['error']['code'] ?? null)->toBe('conflict');
    $proposal = ProfileImportProposal::findOrFail($id);
    expect($proposal->decision_actor)->toBe(['source' => 'mcp', 'integration_id' => $this->integration->id, 'user_id' => $this->owner->id])
        ->and($proposal->source['language'])->toBe('en')
        ->and($this->owner->professionalProfile()->sole()->confirmed_actor['integration_id'])->toBe($this->integration->id);
});

it('rejects once without changing any professional data and denies the opposite decision', function () {
    $id = importProposal($this)['profile_import_proposal_id'];
    expect(importCall($this, 'reject_profile_import', ['id' => $id])['data']['status'] ?? null)->toBe('rejected')
        ->and(importCall($this, 'reject_profile_import', ['id' => $id])['data']['status'] ?? null)->toBe('rejected')
        ->and(importCall($this, 'confirm_profile_import', ['id' => $id, 'expected_version' => 2, 'confirm' => true])['error']['code'] ?? null)->toBe('conflict')
        ->and(app(ProfessionalProfileService::class)->get($this->owner))->toEqual($this->base);
});

it('requires a fresh proposal after a draft or preference edit without automatic rebase', function (bool $preferences) {
    $id = importProposal($this)['profile_import_proposal_id'];
    $service = app(ProfessionalProfileService::class);
    if ($preferences) {
        $service->savePreferences($this->owner, ['Híbrido'], [], 2);
    } else {
        $service->saveDraft($this->owner, array_replace($this->base['draft'], ['competencies' => ['New']]), 2);
    }
    $state = $service->get($this->owner);
    $proposal = importCall($this, 'get_profile_import', ['id' => $id]);
    expect($proposal['data']['stale'])->toBeTrue()
        ->and($proposal['data']['allowed_actions'])->toBe(['reject']);
    foreach ([2, 3] as $version) {
        expect(importCall($this, 'confirm_profile_import', ['id' => $id, 'expected_version' => $version, 'confirm' => true])['error']['code'] ?? null)->toBe('conflict');
    }
    expect($service->get($this->owner))->toEqual($state);
})->with([true, false]);

it('keeps source evidence bound to the requested snapshot when the resume changes', function () {
    $request = importCall($this, 'request_profile_import', ['expected_version' => 2]);
    $this->resume->update(['content' => 'Changed after request']);
    importProvider(importOutput());
    importWork();
    $operation = importCall($this, 'get_ai_operation', ['id' => $request['data']['operation_id']]);
    $proposal = importCall($this, 'get_profile_import', ['id' => $operation['data']['profile_import_proposal_id']]);
    expect($operation['data']['status'])->toBe('completed')
        ->and($proposal['data']['source']['source_stale'])->toBeTrue()
        ->and($proposal['data']['evidence'])->toEqual(importOutput()['evidence']);
});

it('fails closed on malformed or unsupported extraction without partial profile writes', function (string $output) {
    $request = importCall($this, 'request_profile_import', ['expected_version' => 2]);
    importProvider($output);
    importWork();
    $operation = importCall($this, 'get_ai_operation', ['id' => $request['data']['operation_id']]);
    expect($operation['data']['status'])->toBe('failed')
        ->and($operation['data']['failure_code'])->toBe('invalid_output')
        ->and($operation['data']['profile_import_proposal_id'])->toBeNull()
        ->and(app(ProfessionalProfileService::class)->get($this->owner))->toEqual($this->base);
    $this->assertDatabaseCount('profile_import_proposals', 0);
    Http::assertSentCount(1);
})->with([
    'invalid json' => 'not json',
    'array envelope' => '[]',
    'object list' => '{"candidate":{"experiences":{},"education":[],"competencies":[]},"evidence":[]}',
    'unknown fact' => fn () => json_encode(array_replace_recursive(importOutput(), ['candidate' => ['preferences' => ['Remote']]])),
    'invented quote' => fn () => json_encode(array_replace_recursive(importOutput(), ['evidence' => [0 => ['quote' => 'Acme invented PHP']]])),
    'wrong block' => fn () => json_encode(array_replace_recursive(importOutput(), ['evidence' => [0 => ['block_id' => 'block-2']]])),
    'invented value' => fn () => json_encode(array_replace_recursive(importOutput(), ['candidate' => ['experiences' => [0 => ['company' => 'Google']]]])),
    'missing evidence' => fn () => json_encode(array_replace(importOutput(), ['evidence' => []])),
    'wrong scalar type' => fn () => json_encode(array_replace_recursive(importOutput(), ['candidate' => ['experiences' => [0 => ['company' => 123]]]])),
    'oversized fact' => fn () => json_encode(array_replace_recursive(importOutput(), ['candidate' => ['experiences' => [0 => ['company' => str_repeat('x', 256)]]]])),
    'oversized list' => fn () => json_encode(array_replace_recursive(importOutput(), ['candidate' => ['competencies' => array_fill(0, 101, 'PHP')]])),
]);

it('revalidates account and integration before provider access', function (string $revocation) {
    $request = importCall($this, 'request_profile_import', ['expected_version' => 2]);
    match ($revocation) {
        'disabled' => $this->owner->forceFill(['disabled_at' => now()])->save(),
        'revoked' => $this->integration->update(['revoked_at' => now()]),
        'scope' => $this->integration->update(['scopes' => ['profile:read', 'operations:read']]),
    };
    importProvider(importOutput());
    importWork();
    expect(AiOperation::findOrFail($request['data']['operation_id'])->failure_code)->toBe('authorization_revoked');
    $this->assertDatabaseCount('profile_import_proposals', 0);
    Http::assertNothingSent();
    expect(app(ProfessionalProfileService::class)->get($this->owner))->toEqual($this->base);
})->with(['disabled', 'revoked', 'scope']);

it('revalidates authorization again before persisting provider output', function () {
    $request = importCall($this, 'request_profile_import', ['expected_version' => 2]);
    Http::fake(function () {
        $this->integration->update(['revoked_at' => now()]);

        return Http::response(['choices' => [['message' => ['content' => json_encode(importOutput())], 'finish_reason' => 'stop']]]);
    });
    importWork();
    expect(AiOperation::findOrFail($request['data']['operation_id'])->failure_code)->toBe('authorization_revoked');
    $this->assertDatabaseCount('profile_import_proposals', 0);
    expect(app(ProfessionalProfileService::class)->get($this->owner))->toEqual($this->base);
});

it('recovers lost dispatch and fences the obsolete queue attempt', function () {
    $request = importCall($this, 'request_profile_import', ['expected_version' => 2]);
    $operation = AiOperation::findOrFail($request['data']['operation_id']);
    DB::table('jobs')->delete();
    $operation->update(['queued_at' => now()->subMinutes(2)]);
    $this->artisan('profile-imports:recover')->assertSuccessful();
    importProvider(importOutput());
    importWork();
    expect(importCall($this, 'get_ai_operation', ['id' => $operation->id])['data']['status'])->toBe('completed');
    $this->assertDatabaseCount('profile_import_proposals', 1);
    $this->artisan('profile-imports:recover')->assertSuccessful();
    expect(app(ProfessionalProfileService::class)->get($this->owner))->toEqual($this->base);
});

it('rejects a request with no resume before creating professional data', function () {
    $other = User::factory()->create();
    $client = Helpers::client();
    Helpers::integration($other, $client, ['profile:write']);
    $this->token = Helpers::accessToken($other, $client);
    expect(importCall($this, 'request_profile_import', ['expected_version' => 0])['error']['code'] ?? null)->toBe('precondition');
    expect($other->professionalProfile()->exists())->toBeFalse();
    $this->assertDatabaseCount('ai_operations', 0);
});

it('bounds explicit retries while reusing the original encrypted snapshot and fences old runs', function () {
    $first = importCall($this, 'request_profile_import', ['expected_version' => 2]);
    $operation = AiOperation::findOrFail($first['data']['operation_id']);
    $originalSnapshot = $operation->input_snapshot;
    $originalRun = $operation->run_token;
    $originalAttempt = $operation->attempt_token;
    importProvider('invalid');
    importWork();
    $this->resume->update(['content' => 'Changed after failed extraction']);
    for ($attempt = 2; $attempt <= 3; $attempt++) {
        $request = importCall($this, 'request_profile_import', ['expected_version' => 2, 'retry' => true]);
        expect($request['data']['operation_id'] ?? null)->toBe($operation->id);
        expect($operation->refresh()->input_snapshot)->toBe($originalSnapshot);
        importWork();
    }
    expect($operation->refresh()->attempts)->toBe(3)
        ->and(importCall($this, 'request_profile_import', ['expected_version' => 2, 'retry' => true])['error']['code'] ?? null)->toBe('precondition');
    ProcessAiOperation::dispatch($operation->id, $originalRun, $originalAttempt);
    importWork();
    expect($operation->refresh()->attempts)->toBe(3);
    Http::assertSentCount(3);
    $this->assertDatabaseCount('profile_import_proposals', 0);
});

it('does not persist a response after recovery takes the attempt lease', function () {
    $request = importCall($this, 'request_profile_import', ['expected_version' => 2]);
    $operation = AiOperation::findOrFail($request['data']['operation_id']);
    $attempt = $operation->attempt_token;
    $recovered = false;
    Http::fake(function () use ($operation, &$recovered) {
        if (! $recovered) {
            $recovered = true;
            $operation->forceFill(['last_attempt_started_at' => now()->subSeconds(140)])->save();
            Artisan::call('profile-imports:recover');
        }

        return Http::response(['choices' => [['message' => ['content' => json_encode(importOutput())], 'finish_reason' => 'stop']]]);
    });
    importWork();
    expect($operation->refresh()->attempt_token)->not->toBe($attempt);
    $this->assertDatabaseCount('profile_import_proposals', 0);
    importWork();
    expect(importCall($this, 'get_ai_operation', ['id' => $operation->id])['data']['status'])->toBe('completed');
    $this->assertDatabaseCount('profile_import_proposals', 1);
    expect(app(ProfessionalProfileService::class)->get($this->owner))->toEqual($this->base);
});

it('keeps proposals and decisions isolated and rejects untrusted metadata', function () {
    $id = importProposal($this)['profile_import_proposal_id'];
    expect(importCall($this, 'confirm_profile_import', ['id' => $id, 'expected_version' => 2])['error']['code'] ?? null)->toBe('conflict')
        ->and(importCall($this, 'confirm_profile_import', ['id' => $id, 'expected_version' => 2, 'confirm' => true, 'actor_metadata' => ['source' => 'forged']])['error']['code'] ?? null)->toBe('validation')
        ->and(importCall($this, 'request_profile_import', ['expected_version' => 2, 'user_id' => 999])['error']['code'] ?? null)->toBe('validation');
    $other = User::factory()->create();
    $client = Helpers::client();
    Helpers::integration($other, $client, ['profile:read', 'profile:write', 'operations:read']);
    $this->token = Helpers::accessToken($other, $client);
    foreach (['get_profile_import' => ['id' => $id], 'reject_profile_import' => ['id' => $id], 'confirm_profile_import' => ['id' => $id, 'expected_version' => 2, 'confirm' => true]] as $tool => $arguments) {
        expect(importCall($this, $tool, $arguments)['error']['code'] ?? null)->toBe('not_found');
    }
    expect(app(ProfessionalProfileService::class)->get($this->owner))->toEqual($this->base);
});

it('requires profile scopes on every import operation', function () {
    $this->integration->update(['scopes' => ['resume:read', 'resume:write', 'operations:read']]);
    foreach (['request_profile_import' => ['expected_version' => 2], 'get_profile_import' => ['id' => 1], 'reject_profile_import' => ['id' => 1], 'confirm_profile_import' => ['id' => 1, 'expected_version' => 2, 'confirm' => true]] as $tool => $arguments) {
        expect(importCall($this, $tool, $arguments)['error']['code'] ?? null)->toBe('forbidden');
    }
    $this->assertDatabaseCount('ai_operations', 0);
});

it('keeps provider failures recoverable without redirects or fallback', function (int $status) {
    $default = config('ai.default');
    $request = importCall($this, 'request_profile_import', ['expected_version' => 2]);
    $options = [];
    Http::fake(function ($request, array $sentOptions) use ($status, &$options) {
        $options = $sentOptions;

        return Http::response(['error' => ['message' => 'failure']], $status);
    });
    importWork();
    $operation = importCall($this, 'get_ai_operation', ['id' => $request['data']['operation_id']]);
    expect($operation['data']['status'])->toBe('queued')
        ->and($operation['data']['failure_code'])->toBe('retrying')
        ->and($options['allow_redirects'])->toBeFalse()
        ->and(config('ai.default'))->toBe($default)
        ->and(app(ProfessionalProfileService::class)->get($this->owner))->toEqual($this->base);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://opencode.ai/inference/go/openai/v1/chat/completions');
    $this->assertDatabaseCount('profile_import_proposals', 0);
})->with([302, 500]);
