<?php

use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Mcp\Support\AgentIntegrationContext;
use App\Mcp\Tools\System\GetAiOperationTool;
use App\Models\AiOperation;
use App\Models\JobPosting;
use App\Models\ProfessionalProfile;
use App\Models\User;
use App\Services\MatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;

uses(RefreshDatabase::class);

it('reports matching retry permission for the current caller without changing the requester', function () {
    $user = User::factory()->create();
    config(['jobora.mcp.user' => (string) $user->id]);
    ProfessionalProfile::query()->create(['user_id' => $user->id, 'draft' => [], 'confirmed_version' => 1, 'confirmed_snapshot' => ['competencies' => ['PHP']]]);
    $posting = JobPosting::factory()->for($user)->create(['readiness' => 'ready']);
    $snapshot = app(MatchingService::class)->snapshot($user, $posting->id, 'matching-v1');
    $original = Helpers::integration($user, Helpers::client('Original'), ['matching:write']);
    $original->update(['revoked_at' => now()]);
    $actor = ['source' => 'mcp', 'integration_id' => $original->id];
    $operation = AiOperation::query()->create([
        'user_id' => $user->id, 'operation_key' => 'retry-caller', 'operation_type' => AiOperationType::Matching,
        'target_type' => 'opportunity', 'target_id' => $posting->id, 'status' => AiOperationStatus::Failed,
        'attempts' => 1, 'run_token' => (string) Str::uuid(), 'expires_at' => now()->addMinutes(5),
        'input_fingerprint' => MatchingService::hash($snapshot['frozen']),
        'input_snapshot' => ['requester_actor' => $actor], 'queued_at' => now(),
    ]);
    $caller = Helpers::integration($user, Helpers::client('Caller'), ['operations:read', 'matching:write']);
    app()->instance(AgentIntegrationContext::class, new AgentIntegrationContext($user, $caller));
    $read = fn () => app(GetAiOperationTool::class)->handle(new Request(['id' => $operation->id]))->getStructuredContent();
    expect($read()['data']['retry_allowed'])->toBeTrue();
    $caller->update(['scopes' => ['operations:read']]);
    expect($read()['data']['retry_allowed'])->toBeFalse();
    app()->forgetInstance(AgentIntegrationContext::class);
    expect($read()['data']['retry_allowed'])->toBeTrue()
        ->and($operation->fresh()->input_snapshot['requester_actor'])->toBe($actor);
});
