<?php

use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Models\AiOperation;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\CaptureTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;
use Tests\Feature\CaptureIngestion\CaptureEnvelopeFixture;

uses(RefreshDatabase::class);

it('does not let one account read another account data through known ids', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $aliceClient = Helpers::client('Alice');
    $bobClient = Helpers::client('Bob');
    Helpers::integration($alice, $aliceClient);
    Helpers::integration($bob, $bobClient);

    $bobOperation = AiOperation::create([
        'user_id' => $bob->id,
        'operation_key' => 'resume_adaptation:bob:'.Str::random(6),
        'operation_type' => AiOperationType::ResumeAdaptation,
        'target_type' => 'job_posting',
        'target_id' => 9,
        'run_token' => (string) Str::uuid(),
        'status' => AiOperationStatus::Queued,
        'queued_at' => now(),
        'expires_at' => now()->addMinutes(5),
        'input_fingerprint' => str_repeat('b', 64),
    ]);

    $aliceToken = Helpers::accessToken($alice, $aliceClient);

    $response = $this->withToken($aliceToken)
        ->postJson('/mcp', Helpers::toolCall('get_ai_operation', ['id' => $bobOperation->id]))
        ->assertOk();

    $body = Helpers::decodeToolResult($response->getContent());

    expect($body['ok'] ?? null)->toBeFalse()
        ->and($body['error']['code'] ?? null)->toBe('not_found');
});

it('does not let one account mutate another account resource with a known id', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $aliceClient = Helpers::client('Alice');
    Helpers::integration($alice, $aliceClient);

    $bobPosting = JobPosting::factory()->for($bob, 'user')->create();

    $aliceToken = Helpers::accessToken($alice, $aliceClient);

    $response = $this->withToken($aliceToken)
        ->postJson('/mcp', Helpers::toolCall('delete_job_posting', [
            'id' => $bobPosting->id,
            'confirm' => true,
        ]))
        ->assertOk();

    $body = Helpers::decodeToolResult($response->getContent());

    expect($body['ok'] ?? null)->toBeFalse()
        ->and(JobPosting::query()->whereKey($bobPosting->id)->exists())->toBeTrue();
});

it('keeps the capture credential from authenticating the MCP endpoint', function () {
    $user = User::factory()->create();
    $captureToken = app(CaptureTokenService::class)->issueToken($user);

    $this->withToken($captureToken)
        ->postJson('/mcp', Helpers::toolCall('get_ai_operation', ['id' => 1]))
        ->assertStatus(401);
});

it('keeps the OAuth credential from authenticating the capture API', function () {
    $user = User::factory()->create();
    $client = Helpers::client();
    Helpers::integration($user, $client);
    $token = Helpers::accessToken($user, $client);

    $this->withToken($token)
        ->postJson('/api/page-captures', CaptureEnvelopeFixture::make('oauth-is-not-capture-token'))
        ->assertStatus(401);
});
