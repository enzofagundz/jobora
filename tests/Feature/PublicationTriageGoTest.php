<?php

use App\Agents\JobTriageAgent;
use App\Enums\AiOperationStatus;
use App\Jobs\ProcessAiOperation;
use App\Mcp\Servers\JoboraServer;
use App\Mcp\Tools\Captures\RequestPublicationTriageTool;
use App\Mcp\Tools\System\GetAiOperationTool;
use App\Models\AiOperation;
use App\Models\Publication;
use App\Models\PublicationClefResult;
use App\Models\PublicationTriageResult;
use App\Services\AiOperationService;
use App\Services\PublicationTriageInvalidOutput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\TestHandler;
use Tests\Feature\Mcp\McpTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    config([
        'ai.providers.opencode_go.key' => 'test-only-go-key',
        'ai.providers.opencode_zen.key' => 'test-only-legacy-key',
    ]);
});

function goTriageOperation(): AiOperation
{
    $user = McpTestHelpers::user();
    $content = 'FastAPI developer wanted.';
    $publication = Publication::factory()->for($user)->create([
        'content' => $content,
        'content_hash' => hash('sha256', $content),
        'content_version' => 1,
    ]);
    PublicationClefResult::query()->create([
        'publication_id' => $publication->id,
        'content_version' => 1,
        'attempt' => 1,
        'status' => 'eligible',
    ]);
    JoboraServer::tool(RequestPublicationTriageTool::class, [
        'publication_id' => $publication->id,
        'content_version' => 1,
    ])->assertOk();

    return AiOperation::query()->sole();
}

function goTriageResponse(string $text = '{"opportunities":[]}', bool $usage = true): array
{
    return [
        'choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']],
        'model' => 'deepseek-v4.1-flash',
        ...($usage ? ['usage' => ['prompt_tokens' => 6, 'completion_tokens' => 2]] : []),
    ];
}

it('uses only the explicit Go SDK endpoint and model through public triage without changing global defaults', function () {
    $default = config('ai.default');
    $legacyUrl = config('ai.providers.opencode_zen.url');
    $legacyModel = config('ai.providers.opencode_zen.models.text.default');
    $operation = goTriageOperation();
    expect($operation->input_snapshot)->toMatchArray(['provider' => 'opencode_go', 'model' => 'deepseek-v4.1-flash']);
    $options = [];
    Http::fake(function (Request $request, array $sentOptions) use (&$options) {
        $options = $sentOptions;

        return Http::response(goTriageResponse());
    });
    (new ProcessAiOperation($operation->id, $operation->run_token, $operation->attempt_token))->handle(app(AiOperationService::class));
    JoboraServer::tool(GetAiOperationTool::class, ['id' => $operation->id])->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('data.status', 'completed')->etc());
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://opencode.ai/inference/go/openai/v1/chat/completions'
        && $request['model'] === 'deepseek-v4.1-flash');
    Http::assertSentCount(1);
    expect($options['allow_redirects'])->toBeFalse()
        ->and(config('ai.default'))->toBe($default)
        ->and(config('ai.providers.opencode_zen.url'))->toBe($legacyUrl)
        ->and(config('ai.providers.opencode_zen.models.text.default'))->toBe($legacyModel)
        ->and(json_encode($operation->input_snapshot, JSON_THROW_ON_ERROR))->not->toContain('test-only-go-key', 'test-only-legacy-key')
        ->and(PublicationTriageResult::query()->sole()->prompt_tokens)->toBe(6);
});

it('does not fail over to Zen or the global provider when Go rejects the request', function (int $status) {
    $operation = goTriageOperation();
    Http::fake(['*' => Http::response(['error' => ['message' => 'provider failure']], $status)]);
    expect(fn () => (new ProcessAiOperation($operation->id, $operation->run_token, $operation->attempt_token))->handle(app(AiOperationService::class)))
        ->toThrow(Exception::class);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://opencode.ai/inference/go/openai/v1/chat/completions');
    expect($operation->user->jobPostings()->count())->toBe(0)
        ->and(PublicationTriageResult::query()->count())->toBe(0);
})->with([302, 402, 429, 500]);

it('retains nullable unknown usage and terminal invalid_output for malformed model output', function (string $text) {
    $operation = goTriageOperation();
    Http::fake(['*' => Http::response(goTriageResponse($text, usage: false))]);
    (new ProcessAiOperation($operation->id, $operation->run_token, $operation->attempt_token))->handle(app(AiOperationService::class));
    JoboraServer::tool(GetAiOperationTool::class, ['id' => $operation->id])->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('data.status', 'failed')->where('data.failure_code', 'invalid_output')->etc());
    $result = PublicationTriageResult::query()->sole();
    expect($result->error_code)->toBe('invalid_output')
        ->and($result->prompt_tokens)->toBeNull()
        ->and($result->completion_tokens)->toBeNull()
        ->and($operation->user->jobPostings()->count())->toBe(0);
})->with(['malformed', '{"opportunities":[{"role":42}]}', '{"opportunities":{}}']);

it('preserves unknown usage on successful Go output and leaves other SDK flows on their original provider', function () {
    $operation = goTriageOperation();
    Http::fake(['*' => Http::response(goTriageResponse(usage: false))]);
    (new ProcessAiOperation($operation->id, $operation->run_token, $operation->attempt_token))->handle(app(AiOperationService::class));
    $result = PublicationTriageResult::query()->sole();
    expect($result->prompt_tokens)->toBeNull()->and($result->completion_tokens)->toBeNull();

    $provider = (string) config('ai.default');
    $model = (string) config("ai.providers.{$provider}.models.text.default");
    $url = rtrim((string) config("ai.providers.{$provider}.url"), '/').'/chat/completions';
    app(JobTriageAgent::class)->prompt('Return an empty list.');
    Http::assertSent(fn (Request $request): bool => $request->url() === $url && $request['model'] === $model);
    Http::assertSentCount(2);
    expect(app(AiOperationService::class)->enqueuePublicationTriage($operation->user, $operation->target_id, 1)->id)->toBe($operation->id);
});

it('preserves old SDK snapshots and their retry model despite the new triage configuration', function () {
    $operation = goTriageOperation();
    $snapshot = $operation->input_snapshot;
    $snapshot['provider'] = 'opencode_zen';
    $snapshot['model'] = 'deepseek-v4.1-flash-free';
    unset($snapshot['transport'], $snapshot['transport_version']);
    $operation->update(['status' => AiOperationStatus::Failed, 'input_snapshot' => $snapshot]);
    $retry = app(AiOperationService::class)->enqueuePublicationTriage($operation->user, $operation->target_id, 1, retry: true);
    expect($retry->input_snapshot)->toBe($snapshot);
    Http::fake(['*' => Http::response(goTriageResponse())]);
    (new ProcessAiOperation($retry->id, $retry->run_token, $retry->attempt_token))->handle(app(AiOperationService::class));
    Http::assertSent(fn (Request $request): bool => $request['model'] === 'deepseek-v4.1-flash-free'
        && $request->url() !== 'https://opencode.ai/inference/go/openai/v1/chat/completions');
});

it('records only safe validation diagnostics after public triage fails without partial opportunities', function (string $text, string $code, ?string $field) {
    $operation = goTriageOperation();
    $handler = new TestHandler;
    Log::channel('ai')->getLogger()->pushHandler($handler);
    Http::fake(['*' => Http::response(goTriageResponse($text))]);
    (new ProcessAiOperation($operation->id, $operation->run_token, $operation->attempt_token))->handle(app(AiOperationService::class));
    JoboraServer::tool(GetAiOperationTool::class, ['id' => $operation->id])->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('data.status', 'failed')->where('data.failure_code', 'invalid_output')->etc());
    $result = PublicationTriageResult::query()->sole();
    expect($result->output)->toBeNull()
        ->and($result->prompt_tokens)->toBe(6)
        ->and($result->completion_tokens)->toBe(2)
        ->and($operation->user->jobPostings()->count())->toBe(0);
    $records = $handler->getRecords();
    expect($records)->toHaveCount(1)
        ->and($records[0]->message)->toBe('Publication triage output validation failed.')
        ->and($records[0]->context)->toBe([
            'operation_id' => $operation->id,
            'publication_id' => $operation->target_id,
            'content_version' => 1,
            'provider' => 'opencode_go',
            'model' => 'deepseek-v4.1-flash',
            'validation_code' => $code,
            ...($field === null ? [] : ['field' => $field]),
        ])
        ->and(json_encode($records, JSON_THROW_ON_ERROR))->not->toContain('CANARY', 'test-only-go-key', $text);
})->with([
    'invalid enum' => ['{"opportunities":[{}, {"application_methods":[{"type":"CANARY-credential-postdata"}]}]}', 'invalid_application_method_type', 'type'],
    'invalid envelope' => ['{"CANARY-secret-postdata":[]}', 'invalid_envelope', null],
    'invalid JSON' => ['CANARY-secret-postdata', 'invalid_json', null],
    'invalid field' => ['{"opportunities":[{"role":{"CANARY":"secret"}}]}', 'invalid_field', 'role'],
]);

it('does not log validation diagnostics for a worker that lost its attempt lease', function () {
    $operation = goTriageOperation();
    $handler = new TestHandler;
    Log::channel('ai')->getLogger()->pushHandler($handler);
    Http::fake(function () use ($operation) {
        $operation->update(['attempt_token' => 'new-owner-attempt']);

        return Http::response(goTriageResponse('CANARY-secret-postdata'));
    });
    expect(fn () => (new ProcessAiOperation($operation->id, $operation->run_token, $operation->getOriginal('attempt_token')))
        ->handle(app(AiOperationService::class)))->toThrow(LogicException::class);
    expect($handler->getRecords())->toBe([])
        ->and(PublicationTriageResult::query()->count())->toBe(0)
        ->and($operation->user->jobPostings()->count())->toBe(0);
});

it('classifies unknown previous exceptions without copying messages or arbitrary field names', function (Throwable $previous) {
    $exception = new PublicationTriageInvalidOutput(previous: $previous);
    expect($exception->diagnostic())->toBe(['validation_code' => 'unknown']);
})->with([
    'arbitrary exception' => [new RuntimeException('CANARY-key-secret-postdata')],
    'untrusted field name' => [new InvalidArgumentException('Structured output field CANARY-key-secret is invalid.')],
    'known text from unrelated exception' => [new RuntimeException('Structured output application method type is invalid.')],
]);
