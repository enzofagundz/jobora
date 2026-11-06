<?php

use App\Enums\ResumeLanguage;
use App\Mcp\Servers\JoboraServer;
use App\Mcp\Support\AgentIntegrationContext;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\Applications\GetApplicationApprovalTool;
use App\Mcp\Tools\Applications\RecordApplicationApprovalTool;
use App\Mcp\Tools\Applications\RevokeApplicationApprovalTool;
use App\Models\ApplicationApproval;
use App\Models\JobPosting;
use App\Models\ProfessionalProfile;
use App\Models\Resume;
use App\Models\ResumeAdaptation;
use App\Models\TelegramIdentityBinding;
use App\Models\User;
use App\Services\AiInputSnapshotService;
use App\Services\ApplicationApprovalService;
use App\Services\ApplicationPreparationService;
use App\Services\DocumentArtifactService;
use App\Services\TelegramIdentityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\FakePdfBuilder;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Storage::fake('local');
    Pdf::swap(new class extends FakePdfBuilder
    {
        public function save(string $path): self
        {
            parent::save($path);
            file_put_contents($path, '%PDF-test '.$this->getHtml());

            return $this;
        }
    });
    $this->owner = User::factory()->create(['password' => 'correct-password']);
    $this->client = Helpers::client();
    $this->integration = Helpers::integration($this->owner, $this->client, ['approvals:read', 'approvals:write']);
    $this->token = Helpers::accessToken($this->owner, $this->client);
    ProfessionalProfile::query()->create(['user_id' => $this->owner->id, 'version' => 1, 'draft' => [], 'confirmed_version' => 1, 'confirmed_snapshot' => ['competencies' => ['PHP']]]);
    $this->posting = JobPosting::factory()->for($this->owner, 'user')->create(['readiness' => 'ready', 'opportunity_version' => 1]);
    $this->resume = Resume::factory()->for($this->owner)->create(['language' => 'pt-BR', 'content' => "ANA\nPHP"]);
    $fingerprint = app(AiInputSnapshotService::class)->resumeAdaptation($this->owner, $this->posting, ResumeLanguage::PtBr)['fingerprint'];
    ResumeAdaptation::query()->create(['job_posting_id' => $this->posting->id, 'adapted_content' => "ANA\nPHP", 'source_fingerprint' => $fingerprint]);
    $this->preparationInput = ['job_posting_id' => $this->posting->id, 'expected_opportunity_version' => 1, 'expected_profile_confirmed_version' => 1,
        'destination' => ['type' => 'email', 'email' => 'jobs@example.com']];
    $this->prepared = app(ApplicationPreparationService::class)->prepare($this->owner, $this->preparationInput);
    $this->approvalInput = ['preparation_id' => $this->prepared['preparation_id'], 'expected_fingerprint' => $this->prepared['fingerprint'],
        'telegram_user_id' => '123456789012345', 'chat_id' => '123456789012345', 'message_id' => '2',
        'approved_at' => now()->toISOString(), 'expires_at' => now()->addMinutes(30)->toISOString()];
});

function approvalCall(object $test, string $tool, array $input): array
{
    app('auth')->forgetGuards();

    return Helpers::decodeToolResult($test->withToken($test->token)->postJson('/mcp', Helpers::toolCall($tool, $input))->assertOk()->getContent());
}

function approvalPanel(object $test): void
{
    app()->forgetInstance(AgentIntegrationContext::class);
    app('auth')->forgetGuards();
    $test->actingAs($test->owner, 'web');
}

function approvalLink(object $test, bool $confirm = true): array
{
    approvalPanel($test);
    $challenge = app(TelegramIdentityService::class)->startChallenge($test->owner, $test->integration->id);
    $submitted = approvalCall($test, 'submit_telegram_link', ['challenge' => $challenge['challenge'],
        'telegram_user_id' => '123456789012345', 'chat_id' => '123456789012345', 'message_id' => '1', 'chat_type' => 'private']);
    expect($submitted['ok'])->toBeTrue();
    if ($confirm) {
        approvalPanel($test);
        $binding = app(TelegramIdentityService::class)->confirmChallenge($test->owner, $challenge['challenge_id'], 'correct-password');
        $test->approvalInput['approved_at'] = now()->toISOString();

        return $binding;
    }

    return $challenge;
}

it('requires a pending Telegram identity to be confirmed with the real account password', function () {
    $challenge = approvalLink($this, false);
    expect(approvalCall($this, 'record_application_approval', $this->approvalInput)['error']['code'])->toBe('precondition');
    approvalPanel($this);
    expect(app(TelegramIdentityService::class)->currentBinding($this->owner))->toBeNull();
    expect(fn () => app(TelegramIdentityService::class)->confirmChallenge($this->owner, $challenge['challenge_id'], 'wrong'))->toThrow(ValidationException::class);
    expect(app(TelegramIdentityService::class)->pendingChallenges($this->owner))->toHaveCount(1);
    $binding = app(TelegramIdentityService::class)->confirmChallenge($this->owner, $challenge['challenge_id'], 'correct-password');
    expect($binding['status'])->toBe('active')->and($binding['telegram_user_id'])->toBe('123456789012345')
        ->and(json_encode($binding))->not->toContain('challenge_hash', $challenge['challenge']);
});

it('rejects old approval dates from before the human binding confirmation', function () {
    approvalLink($this);
    expect(approvalCall($this, 'record_application_approval', [...$this->approvalInput, 'approved_at' => now()->subHour()->toISOString()])['error']['code'])->toBe('precondition');
    $this->assertDatabaseCount('application_approvals', 0);
});

it('consumes challenge codes once without exposing them in pending queries', function () {
    $challenge = approvalLink($this, false);
    $input = ['challenge' => $challenge['challenge'], 'telegram_user_id' => '123456789012345', 'chat_id' => '123456789012345', 'message_id' => '1', 'chat_type' => 'private'];
    expect(approvalCall($this, 'submit_telegram_link', $input)['error']['code'])->toBe('conflict');
    approvalPanel($this);
    $pending = app(TelegramIdentityService::class)->pendingChallenges($this->owner);
    expect($pending)->toHaveCount(1)->and(json_encode($pending))->not->toContain($challenge['challenge'], 'challenge_hash');
    $this->assertDatabaseCount('telegram_identity_bindings', 0);
});

it('expires challenges at presentation and final human confirmation', function (bool $submitFirst) {
    approvalPanel($this);
    $challenge = app(TelegramIdentityService::class)->startChallenge($this->owner, $this->integration->id);
    $input = ['challenge' => $challenge['challenge'], 'telegram_user_id' => '123', 'chat_id' => '123', 'message_id' => '1', 'chat_type' => 'private'];
    if ($submitFirst) {
        expect(approvalCall($this, 'submit_telegram_link', $input)['ok'])->toBeTrue();
    }
    $this->travel(11)->minutes();
    if ($submitFirst) {
        approvalPanel($this);
        expect(fn () => app(TelegramIdentityService::class)->confirmChallenge($this->owner, $challenge['challenge_id'], 'correct-password'))->toThrow(ToolException::class);
    } else {
        expect(approvalCall($this, 'submit_telegram_link', $input)['error']['code'])->toBe('precondition');
    }
    $this->assertDatabaseCount('telegram_identity_bindings', 0);
})->with([false, true]);

it('requires canonical decimal string identifiers and a private chat', function (array $change) {
    approvalPanel($this);
    $challenge = app(TelegramIdentityService::class)->startChallenge($this->owner, $this->integration->id);
    $input = ['challenge' => $challenge['challenge'], 'telegram_user_id' => '123', 'chat_id' => '123', 'message_id' => '1', 'chat_type' => 'private'];
    expect(approvalCall($this, 'submit_telegram_link', [...$input, ...$change])['error']['code'])->toBe('validation');
    $this->assertDatabaseCount('telegram_identity_bindings', 0);
})->with([
    [['telegram_user_id' => 123]], [['telegram_user_id' => '0123']], [['telegram_user_id' => '@name']],
    [['chat_id' => '-123']], [['chat_type' => 'group']], [['message_id' => '1.0']], [['user_id' => 2]],
]);

it('rejects another integration from presenting a challenge and prevents cross-owner panel actions', function () {
    approvalPanel($this);
    $challenge = app(TelegramIdentityService::class)->startChallenge($this->owner, $this->integration->id);
    $client = Helpers::client('Other integration');
    Helpers::integration($this->owner, $client, ['approvals:write']);
    $this->token = Helpers::accessToken($this->owner, $client);
    expect(approvalCall($this, 'submit_telegram_link', ['challenge' => $challenge['challenge'], 'telegram_user_id' => '123', 'chat_id' => '123', 'message_id' => '1', 'chat_type' => 'private'])['error']['code'])->toBe('not_found');
    app()->forgetInstance(AgentIntegrationContext::class);
    $other = User::factory()->create(['password' => 'other-password']);
    $this->actingAs($other, 'web');
    expect(fn () => app(TelegramIdentityService::class)->confirmChallenge($other, $challenge['challenge_id'], 'other-password'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(TelegramIdentityService::class)->startChallenge($this->owner, $this->integration->id))->toThrow(AuthorizationException::class);
});

it('requires explicit revocation before rebinding and never reactivates historical approvals', function () {
    $binding = approvalLink($this);
    $approval = approvalCall($this, 'record_application_approval', $this->approvalInput)['data'];
    $second = approvalLink($this, false);
    approvalPanel($this);
    expect(fn () => app(TelegramIdentityService::class)->confirmChallenge($this->owner, $second['challenge_id'], 'correct-password'))->toThrow(ToolException::class);
    app(TelegramIdentityService::class)->revokeBinding($this->owner, $binding['id']);
    app(TelegramIdentityService::class)->confirmChallenge($this->owner, $second['challenge_id'], 'correct-password');
    $get = approvalCall($this, 'get_application_approval', ['preparation_id' => $this->prepared['preparation_id']])['data']['approvals'][0];
    expect($get['id'])->toBe($approval['id'])->and($get['status'])->toBe('invalid')->and($get['reasons'])->toContain('binding_inactive');
    $this->assertDatabaseCount('telegram_identity_bindings', 2);
});

it('enforces active occupancy with database uniqueness as well as the public confirmation guard', function () {
    approvalLink($this);
    approvalPanel($this);
    $challenge = app(TelegramIdentityService::class)->startChallenge($this->owner, $this->integration->id);
    $binding = TelegramIdentityBinding::query()->firstOrFail();
    expect(fn () => DB::transaction(fn () => $binding->replicate()->fill(['challenge_id' => $challenge['challenge_id']])->save()))
        ->toThrow(UniqueConstraintViolationException::class);
    expect(app(TelegramIdentityService::class)->currentBinding($this->owner)['id'])->toBe($binding->id);
});

it('rejects non-current documents fingerprints unsupported answers and pending required letters', function (string $change) {
    approvalLink($this);
    if ($change === 'fingerprint') {
        $this->approvalInput['expected_fingerprint'] = str_repeat('a', 64);
    } elseif ($change === 'resume') {
        $this->resume->update(['content' => 'Changed resume']);
    } elseif ($change === 'profile') {
        $this->owner->professionalProfile()->first()->update(['confirmed_version' => 2]);
    } else {
        approvalPanel($this);
        $input = match ($change) {
            'letter' => [...$this->preparationInput, 'cover_letter_required' => true],
            'answer' => [...$this->preparationInput, 'questions' => [['id' => 'visa', 'question' => 'Permission?', 'answer' => 'Yes']]],
            default => [...$this->preparationInput, 'destination' => ['type' => 'email', 'email' => 'changed@example.com']],
        };
        $new = app(ApplicationPreparationService::class)->prepare($this->owner, $input);
        if (in_array($change, ['letter', 'answer'], true)) {
            $this->approvalInput['preparation_id'] = $new['preparation_id'];
            $this->approvalInput['expected_fingerprint'] = $new['fingerprint'];
        }
    }
    expect(approvalCall($this, 'record_application_approval', $this->approvalInput)['error']['code'])->toBe($change === 'fingerprint' ? 'conflict' : 'precondition');
    $this->assertDatabaseCount('application_approvals', 0);
})->with(['fingerprint', 'resume', 'profile', 'letter', 'answer', 'destination']);

it('invalidates existing consent immediately when its dependencies change', function (string $change) {
    $binding = approvalLink($this);
    approvalCall($this, 'record_application_approval', $this->approvalInput);
    approvalPanel($this);
    match ($change) {
        'binding' => app(TelegramIdentityService::class)->revokeBinding($this->owner, $binding['id']),
        'integration' => $this->integration->update(['revoked_at' => now()]),
        'capability' => $this->integration->update(['scopes' => ['approvals:read']]),
        'profile' => $this->owner->professionalProfile()->first()->update(['confirmed_snapshot' => ['competencies' => ['Rust']]]),
        'resume' => $this->resume->update(['content' => 'Changed']),
        'artifact' => app(DocumentArtifactService::class)->revoke($this->owner, $this->prepared['artifacts'][0]['artifact_id']),
        'preparation' => app(ApplicationPreparationService::class)->prepare($this->owner, $this->preparationInput),
    };
    $get = app(ApplicationApprovalService::class)->forPreparation($this->owner, $this->prepared['preparation_id']);
    expect($get['approvals'][0]['status'])->toBe('invalid')->and($get['approvals'][0]['reasons'])->not->toBeEmpty();
})->with(['binding', 'integration', 'capability', 'profile', 'resume', 'artifact', 'preparation']);

it('checks expiry TTL and future date limits', function (string $change) {
    approvalLink($this);
    $change = match ($change) {
        'future' => ['approved_at' => now()->addMinutes(6)->toISOString()],
        'expired' => ['expires_at' => now()->subMinute()->toISOString()],
        'ttl' => ['expires_at' => now()->addHours(25)->toISOString()],
    };
    expect(approvalCall($this, 'record_application_approval', [...$this->approvalInput, ...$change])['error']['code'])->toBe('precondition');
    $this->assertDatabaseCount('application_approvals', 0);
})->with(['future', 'expired', 'ttl']);

it('revokes consent without renewing it on identical message replay', function () {
    approvalLink($this);
    $first = approvalCall($this, 'record_application_approval', $this->approvalInput)['data'];
    expect(approvalCall($this, 'revoke_application_approval', ['id' => $first['id']])['data']['status'])->toBe('revoked');
    $replay = approvalCall($this, 'record_application_approval', $this->approvalInput)['data'];
    expect($replay['id'])->toBe($first['id'])->and($replay['status'])->toBe('revoked')->and($replay['expires_at'])->toBe($first['expires_at']);
    $this->assertDatabaseCount('application_approvals', 1);
});

it('reports expired consent without accepting a new expiry for its message', function () {
    approvalLink($this);
    $first = approvalCall($this, 'record_application_approval', $this->approvalInput)['data'];
    $this->travel(31)->minutes();
    $get = approvalCall($this, 'get_application_approval', ['preparation_id' => $this->prepared['preparation_id']]);
    expect($get['data']['approvals'][0]['status'])->toBe('expired');
    expect(approvalCall($this, 'record_application_approval', [...$this->approvalInput, 'expires_at' => now()->addMinutes(10)->toISOString()])['error']['code'])->toBe('conflict');
});

it('does not repurpose the challenge message as application consent', function () {
    approvalLink($this);
    expect(approvalCall($this, 'record_application_approval', [...$this->approvalInput, 'message_id' => '1'])['error']['code'])->toBe('precondition');
});

it('denies missing capabilities revoked integrations and inactive accounts', function (string $change) {
    approvalLink($this);
    match ($change) {
        'capability' => $this->integration->update(['scopes' => ['applications:write']]),
        'revoked' => $this->integration->update(['revoked_at' => now()]),
        'account' => $this->owner->forceFill(['disabled_at' => now()])->save(),
    };
    app('auth')->forgetGuards();
    $response = $this->withToken($this->token)->postJson('/mcp', Helpers::toolCall('record_application_approval', $this->approvalInput));
    if ($change === 'capability') {
        expect(Helpers::decodeToolResult($response->assertOk()->getContent())['error']['code'])->toBe('forbidden');
    } else {
        expect($response->status())->toBe($change === 'account' ? 401 : 403);
    }
    $this->assertDatabaseCount('application_approvals', 0);
})->with(['capability', 'revoked', 'account']);

it('keeps approval and revocation lookup private to its owner', function () {
    approvalLink($this);
    $approval = approvalCall($this, 'record_application_approval', $this->approvalInput)['data'];
    $other = User::factory()->create();
    $client = Helpers::client('Other owner');
    Helpers::integration($other, $client, ['approvals:read', 'approvals:write']);
    $this->token = Helpers::accessToken($other, $client);
    expect(approvalCall($this, 'get_application_approval', ['preparation_id' => $this->prepared['preparation_id']])['error']['code'])->toBe('not_found')
        ->and(approvalCall($this, 'revoke_application_approval', ['id' => $approval['id']])['error']['code'])->toBe('not_found');
});

it('revalidates integration authorization when the panel confirms a pending identity', function () {
    $challenge = approvalLink($this, false);
    $this->integration->update(['revoked_at' => now()]);
    approvalPanel($this);
    expect(fn () => app(TelegramIdentityService::class)->confirmChallenge($this->owner, $challenge['challenge_id'], 'correct-password'))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('telegram_identity_bindings', 0);
});

it('limits approval expiry to the earliest artifact without extending the requested date', function () {
    config(['application-documents.artifact_ttl_hours' => 1]);
    $this->prepared = app(ApplicationPreparationService::class)->prepare($this->owner, $this->preparationInput);
    $this->approvalInput['preparation_id'] = $this->prepared['preparation_id'];
    $this->approvalInput['expected_fingerprint'] = $this->prepared['fingerprint'];
    approvalLink($this);
    expect(approvalCall($this, 'record_application_approval', [...$this->approvalInput, 'expires_at' => now()->addHours(2)->toISOString()])['error']['code'])->toBe('precondition');
});

it('preserves historical messages when a different preparation tries to reuse them', function () {
    approvalLink($this);
    approvalCall($this, 'record_application_approval', $this->approvalInput);
    approvalPanel($this);
    $new = app(ApplicationPreparationService::class)->prepare($this->owner, [...$this->preparationInput, 'destination' => ['type' => 'email', 'email' => 'other@example.com']]);
    expect(approvalCall($this, 'record_application_approval', [...$this->approvalInput, 'preparation_id' => $new['preparation_id'], 'expected_fingerprint' => $new['fingerprint']])['error']['code'])->toBe('conflict');
    $this->assertDatabaseCount('application_approvals', 1);
});

it('records consent only and consults it using approvals read without other capabilities', function () {
    approvalLink($this);
    $result = approvalCall($this, 'record_application_approval', $this->approvalInput);
    expect($result['ok'])->toBeTrue()->and($result['data']['status'])->toBe('active')
        ->and($result['data']['trust_statement'])->toContain('Hermes')
        ->and($result['data']['authorizes_submission'])->toBeFalse();
    $reader = Helpers::client('Read only');
    Helpers::integration($this->owner, $reader, ['approvals:read']);
    $this->token = Helpers::accessToken($this->owner, $reader);
    $get = approvalCall($this, 'get_application_approval', ['preparation_id' => $this->prepared['preparation_id']]);
    expect($get['data']['approvals'][0]['status'])->toBe('active')
        ->and(json_encode($get))->not->toContain('snapshot', 'adapted_content', 'documents/');
    expect($this->posting->application()->first()->status->value)->toBe('planned');
    $this->assertDatabaseCount('application_events', 0);
    $this->assertDatabaseCount('ai_operations', 0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('replays identical approval messages without renewal and conflicts on changed data', function () {
    approvalLink($this);
    $first = approvalCall($this, 'record_application_approval', $this->approvalInput)['data'];
    $this->travel(1)->minutes();
    $replay = approvalCall($this, 'record_application_approval', $this->approvalInput)['data'];
    expect($replay['id'])->toBe($first['id'])->and($replay['expires_at'])->toBe($first['expires_at']);
    foreach ([['expected_fingerprint' => str_repeat('a', 64)], ['expires_at' => now()->addMinutes(20)->toISOString()], ['telegram_user_id' => '99']] as $change) {
        expect(approvalCall($this, 'record_application_approval', [...$this->approvalInput, ...$change])['error']['code'])->toBe('conflict');
    }
    $this->assertDatabaseCount('application_approvals', 1);
});

it('never treats confirm true or stdio as Telegram proof', function () {
    expect(approvalCall($this, 'record_application_approval', ['confirm' => true])['error']['code'])->toBe('validation');
    app()->forgetInstance(AgentIntegrationContext::class);
    config(['jobora.mcp.user' => (string) $this->owner->id]);
    JoboraServer::actingAs($this->owner)->tool(RecordApplicationApprovalTool::class, $this->approvalInput)->assertHasErrors(['forbidden']);
    $this->assertDatabaseCount('application_approvals', 0);
});

it('requires the bound integration and exact human identity on every new approval', function (string $change) {
    approvalLink($this);
    if ($change === 'integration') {
        $client = Helpers::client('Other writer');
        Helpers::integration($this->owner, $client, ['approvals:write']);
        $this->token = Helpers::accessToken($this->owner, $client);
    } else {
        $this->approvalInput[$change] = '987654321';
    }
    expect(approvalCall($this, 'record_application_approval', $this->approvalInput)['error']['code'])->toBe('precondition');
    $this->assertDatabaseCount('application_approvals', 0);
})->with(['integration', 'telegram_user_id', 'chat_id']);

it('preserves unique Telegram identity occupancy across two account owners', function () {
    approvalLink($this);
    $other = User::factory()->create(['password' => 'other-password']);
    $client = Helpers::client('Other user');
    $integration = Helpers::integration($other, $client, ['approvals:write']);
    app()->forgetInstance(AgentIntegrationContext::class);
    $this->actingAs($other, 'web');
    $challenge = app(TelegramIdentityService::class)->startChallenge($other, $integration->id);
    $this->token = Helpers::accessToken($other, $client);
    expect(approvalCall($this, 'submit_telegram_link', ['challenge' => $challenge['challenge'], 'telegram_user_id' => '123456789012345', 'chat_id' => '123456789012345', 'message_id' => '3', 'chat_type' => 'private'])['ok'])->toBeTrue();
    app()->forgetInstance(AgentIntegrationContext::class);
    $this->actingAs($other, 'web');
    expect(fn () => app(TelegramIdentityService::class)->confirmChallenge($other, $challenge['challenge_id'], 'other-password'))->toThrow(ToolException::class);
    $this->assertDatabaseCount('telegram_identity_bindings', 1);
});

it('allows idempotent panel confirmation but never revives a revoked binding', function () {
    $challenge = approvalLink($this, false);
    approvalPanel($this);
    $first = app(TelegramIdentityService::class)->confirmChallenge($this->owner, $challenge['challenge_id'], 'correct-password');
    expect(app(TelegramIdentityService::class)->confirmChallenge($this->owner, $challenge['challenge_id'], 'correct-password')['id'])->toBe($first['id']);
    app(TelegramIdentityService::class)->revokeBinding($this->owner, $first['id']);
    expect(fn () => app(TelegramIdentityService::class)->confirmChallenge($this->owner, $challenge['challenge_id'], 'correct-password'))->toThrow(ToolException::class);
});

it('keeps approval history immutable and permits owner stdio reads and revocation without Telegram attestation', function () {
    approvalLink($this);
    $recorded = approvalCall($this, 'record_application_approval', $this->approvalInput)['data'];
    expect(fn () => ApplicationApproval::query()->findOrFail($recorded['id'])->update(['fingerprint' => str_repeat('b', 64)]))->toThrow(LogicException::class);
    app()->forgetInstance(AgentIntegrationContext::class);
    config(['jobora.mcp.user' => (string) $this->owner->id]);
    JoboraServer::actingAs($this->owner)->tool(GetApplicationApprovalTool::class, ['preparation_id' => $this->prepared['preparation_id']])->assertOk();
    JoboraServer::actingAs($this->owner)->tool(RevokeApplicationApprovalTool::class, ['id' => $recorded['id']])->assertOk();
    $this->assertDatabaseCount('application_approvals', 1);
});

it('invalidates consent when persisted destination instructions change without a new preparation', function () {
    $method = $this->posting->applicationMethods()->create(['type' => 'email', 'email' => 'jobs@example.com', 'source' => 'user', 'dedup_key' => 'approval-destination']);
    $this->prepared = app(ApplicationPreparationService::class)->prepare($this->owner, [...$this->preparationInput, 'destination' => ['application_method_id' => $method->id]]);
    $this->approvalInput['preparation_id'] = $this->prepared['preparation_id'];
    $this->approvalInput['expected_fingerprint'] = $this->prepared['fingerprint'];
    approvalLink($this);
    approvalCall($this, 'record_application_approval', $this->approvalInput);
    $method->update(['instructions' => 'Send to a different destination']);
    expect(approvalCall($this, 'get_application_approval', ['preparation_id' => $this->prepared['preparation_id']])['data']['approvals'][0]['reasons'])->toContain('preparation_stale');
});
