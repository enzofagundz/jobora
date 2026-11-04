<?php

use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Mcp\Servers\JoboraServer;
use App\Mcp\Tools\System\GetAiOperationTool;
use App\Models\AiOperation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Token;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;

uses(RefreshDatabase::class);

function remoteOperation(User $user): AiOperation
{
    return AiOperation::create([
        'user_id' => $user->id,
        'operation_key' => 'resume_adaptation:job_posting:'.Str::random(8),
        'operation_type' => AiOperationType::ResumeAdaptation,
        'target_type' => 'job_posting',
        'target_id' => 1,
        'run_token' => (string) Str::uuid(),
        'status' => AiOperationStatus::Queued,
        'queued_at' => now(),
        'expires_at' => now()->addMinutes(5),
        'input_fingerprint' => str_repeat('a', 64),
    ]);
}

it('rejects remote MCP calls without a bearer token', function () {
    $this->postJson('/mcp', Helpers::toolCall('get_ai_operation', ['id' => 1]))
        ->assertStatus(401);
});

it('authenticates the request user instead of the configured JOBORA_MCP_USER fallback', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    config(['jobora.mcp.user' => $intruder->email]);

    $operation = remoteOperation($owner);
    $client = Helpers::client();
    Helpers::integration($owner, $client);
    $token = Helpers::accessToken($owner, $client);

    $response = $this->withToken($token)
        ->postJson('/mcp', Helpers::toolCall('get_ai_operation', ['id' => $operation->id]))
        ->assertOk();

    $body = Helpers::decodeToolResult($response->getContent());

    expect($body['ok'] ?? null)->toBeTrue()
        ->and($body['data']['id'] ?? null)->toBe($operation->id);
});

it('rejects an expired signed access token', function () {
    $user = User::factory()->create();
    $client = Helpers::client();
    Helpers::integration($user, $client);

    $token = Helpers::accessToken($user, $client, expiresAt: new DateTimeImmutable('-2 minutes'));

    $this->withToken($token)
        ->postJson('/mcp', Helpers::toolCall('get_ai_operation', ['id' => 1]))
        ->assertStatus(401);
});

it('rejects a revoked access token', function () {
    $user = User::factory()->create();
    $client = Helpers::client();
    Helpers::integration($user, $client);

    $token = Helpers::accessToken($user, $client, revoked: true);

    $this->withToken($token)
        ->postJson('/mcp', Helpers::toolCall('get_ai_operation', ['id' => 1]))
        ->assertStatus(401);
});

it('rejects a token without the mcp:use scope', function () {
    $user = User::factory()->create();
    $client = Helpers::client();
    Helpers::integration($user, $client);

    $token = Helpers::accessToken($user, $client, scopes: ['market:read']);

    $this->withToken($token)
        ->postJson('/mcp', Helpers::toolCall('get_ai_operation', ['id' => 1]))
        ->assertStatus(403);
});

it('rejects a token for a disabled account', function () {
    $user = User::factory()->create(['disabled_at' => now()]);
    $client = Helpers::client();
    Helpers::integration($user, $client);

    $token = Helpers::accessToken($user, $client);

    $this->withToken($token)
        ->postJson('/mcp', Helpers::toolCall('get_ai_operation', ['id' => 1]))
        ->assertStatus(401);
});

it('creates the integration when a personal access token is issued', function () {
    $user = User::factory()->create();

    app(ClientRepository::class)->createPersonalAccessGrantClient('tests');

    $result = $user->createToken('Hermes', ['mcp:use']);

    expect($result->token)->toBeInstanceOf(Token::class)
        ->and($user->agentIntegrations()->where('oauth_client_id', $result->token->client_id)->exists())->toBeTrue();
});

it('keeps the stdio resolver working when no remote integration context exists', function () {
    $user = User::factory()->create();
    config(['jobora.mcp.user' => $user->email]);

    JoboraServer::tool(GetAiOperationTool::class, ['id' => 1])
        ->assertHasErrors(['not_found']);
});
