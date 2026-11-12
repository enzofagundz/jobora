<?php

use App\Enums\ApplicationStatus;
use App\Enums\ResumeLanguage;
use App\Mcp\Servers\JoboraServer;
use App\Mcp\Support\AgentIntegrationContext;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\Applications\ConsumeApplicationSubmissionTool;
use App\Mcp\Tools\Applications\GetApplicationApprovalTool;
use App\Mcp\Tools\Applications\RecordApplicationApprovalTool;
use App\Mcp\Tools\Applications\ReserveApplicationSubmissionTool;
use App\Mcp\Tools\Applications\RevokeApplicationApprovalTool;
use App\Models\ApplicationApproval;
use App\Models\ApplicationPreparation;
use App\Models\ApplicationSubmissionAttempt;
use App\Models\JobPosting;
use App\Models\ProfessionalProfile;
use App\Models\Resume;
use App\Models\ResumeAdaptation;
use App\Models\TelegramIdentityBinding;
use App\Models\User;
use App\Services\AgentIntegrationService;
use App\Services\AiInputSnapshotService;
use App\Services\ApplicationApprovalService;
use App\Services\ApplicationPreparationService;
use App\Services\ApplicationTrackingService;
use App\Services\DocumentArtifactService;
use App\Services\TelegramIdentityService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\FakePdfBuilder;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;
use Tests\Feature\ApplicationSubmissionConcurrencyTasks;

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

it('reserves without permission and consumes permission only once over authenticated HTTP MCP', function () {
    $this->integration->update(['scopes' => ['approvals:read', 'approvals:write', 'applications:submit', 'applications:read']]);
    approvalLink($this);
    $approval = approvalCall($this, 'record_application_approval', $this->approvalInput)['data'];
    $reserveInput = ['approval_id' => $approval['id'], 'expected_fingerprint' => $this->prepared['fingerprint'], 'idempotency_key' => 'send-1'];
    $reserved = approvalCall($this, 'reserve_application_submission', $reserveInput);
    expect($reserved['ok'])->toBeTrue();
    expect($reserved['data'])->toMatchArray(['state' => 'reserved', 'authorizes_submission' => false, 'revision' => 1]);
    $input = ['attempt_id' => $reserved['data']['attempt_id'], 'expected_revision' => 1, 'expected_fingerprint' => $this->prepared['fingerprint'], 'event_key' => 'consume-1'];
    expect(approvalCall($this, 'consume_application_submission', $input)['data'])->toMatchArray(['state' => 'sending', 'authorizes_submission' => true, 'revision' => 2]);
    expect(approvalCall($this, 'consume_application_submission', $input)['data'])->toMatchArray(['authorizes_submission' => false, 'already_consumed' => true]);
    expect(approvalCall($this, 'reserve_application_submission', $reserveInput)['data']['attempt_id'])->toBe($reserved['data']['attempt_id']);
    $this->assertDatabaseCount('application_submission_attempts', 1);
    $this->assertDatabaseCount('application_submission_events', 2);
    expect($this->posting->application->status->value)->toBe('planned');
});

it('does not consume when a frozen artifact disappears after reservation', function () {
    $this->integration->update(['scopes' => ['approvals:write', 'applications:submit']]);
    approvalLink($this);
    $approval = approvalCall($this, 'record_application_approval', $this->approvalInput)['data'];
    $reserved = approvalCall($this, 'reserve_application_submission', ['approval_id' => $approval['id'],
        'expected_fingerprint' => $this->prepared['fingerprint'], 'idempotency_key' => 'missing-artifact'])['data'];
    $artifact = ApplicationPreparation::query()->findOrFail($this->prepared['preparation_id'])->artifacts()->firstOrFail();
    Storage::disk('local')->delete($artifact->path);
    $result = approvalCall($this, 'consume_application_submission', ['attempt_id' => $reserved['attempt_id'], 'expected_revision' => 1,
        'expected_fingerprint' => $this->prepared['fingerprint'], 'event_key' => 'consume']);
    expect($result['ok'])->toBeFalse();
    expect($result['error']['code'])->toBe('precondition');
    $this->assertDatabaseHas('application_submission_attempts', ['id' => $reserved['attempt_id'], 'consumed_at' => null, 'revision' => 1]);
});

it('bounds approval history pages and validates pagination inputs', function () {
    approvalLink($this);
    for ($message = 2; $message <= 4; $message++) {
        expect(approvalCall($this, 'record_application_approval', [...$this->approvalInput, 'message_id' => (string) $message])['ok'])->toBeTrue();
    }
    $input = ['preparation_id' => $this->prepared['preparation_id'], 'limit' => 2];
    $first = approvalCall($this, 'get_application_approval', $input)['data'];
    expect($first)->toMatchArray(['total' => 3, 'limit' => 2, 'offset' => 0, 'next_offset' => 2]);
    expect($first['approvals'])->toHaveCount(2);
    $second = approvalCall($this, 'get_application_approval', [...$input, 'offset' => 2])['data'];
    expect($second['approvals'])->toHaveCount(1);
    expect($second['next_offset'])->toBeNull();
    expect(array_intersect(array_column($first['approvals'], 'id'), array_column($second['approvals'], 'id')))->toBe([]);
    foreach ([['limit' => 0], ['limit' => 101], ['offset' => -1]] as $invalid) {
        expect(approvalCall($this, 'get_application_approval', [...$input, ...$invalid])['error']['code'])->toBe('validation');
    }
});

it('batches approval validation queries within each history read only', function () {
    approvalLink($this);
    $ids = [];
    for ($message = 2; $message <= 7; $message++) {
        $result = approvalCall($this, 'record_application_approval', [...$this->approvalInput, 'message_id' => (string) $message]);
        expect($result['ok'])->toBeTrue();
        $ids[] = $result['data']['id'];
    }
    ApplicationApproval::query()->findOrFail($ids[5])->update(['revoked_at' => now()]);
    approvalPanel($this);
    $service = app(ApplicationApprovalService::class);
    $counts = [];
    foreach ([1, 6] as $limit) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $page = $service->forPreparation($this->owner, $this->prepared['preparation_id'], $limit);
            $counts[] = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        expect($page['approvals'])->toHaveCount($limit);
    }
    expect($counts[1])->toBe($counts[0]);
    expect(array_column($page['approvals'], 'status'))->toContain('active', 'revoked');

    $this->resume->update(['content' => 'Changed resume']);
    $this->integration->update(['revoked_at' => now()]);
    $freshPage = $service->forPreparation($this->owner, $this->prepared['preparation_id'], 6);
    foreach ($freshPage['approvals'] as $approval) {
        expect($approval['reasons'])->toContain('preparation_stale', 'integration_inactive');
    }
    expect($service->invalidReasons($this->owner, ApplicationApproval::query()->findOrFail($ids[0])))
        ->toContain('preparation_stale', 'integration_inactive');
});

it('replays approval timestamps expressed with equivalent timezone offsets', function () {
    approvalLink($this);
    $first = approvalCall($this, 'record_application_approval', $this->approvalInput);
    expect($first['ok'])->toBeTrue();
    foreach (['approved_at', 'expires_at'] as $field) {
        $this->approvalInput[$field] = CarbonImmutable::parse($this->approvalInput[$field])
            ->setTimezone('America/Sao_Paulo')->format('Y-m-d\TH:i:s.uP');
    }
    expect(approvalCall($this, 'record_application_approval', $this->approvalInput)['data'])->toBe($first['data']);
    $this->assertDatabaseCount('application_approvals', 1);
});

function submissionReserved(object $test): array
{
    $test->integration->update(['scopes' => ['approvals:write', 'applications:submit', 'applications:read', 'applications:write']]);
    approvalLink($test);
    $approval = approvalCall($test, 'record_application_approval', $test->approvalInput)['data'];

    return approvalCall($test, 'reserve_application_submission', ['approval_id' => $approval['id'],
        'expected_fingerprint' => $test->prepared['fingerprint'], 'idempotency_key' => 'send-1'])['data'];
}

function submissionConsumed(object $test): array
{
    $reserved = submissionReserved($test);

    return approvalCall($test, 'consume_application_submission', ['attempt_id' => $reserved['attempt_id'], 'expected_revision' => 1,
        'expected_fingerprint' => $test->prepared['fingerprint'], 'event_key' => 'consume-1'])['data'];
}

it('reads overdue sending as unknown without writing or authorizing and keeps its active slot', function () {
    $attempt = submissionConsumed($this);
    $this->travel(11)->minutes();
    $result = approvalCall($this, 'get_application_submission', ['attempt_id' => $attempt['attempt_id'], 'limit' => 1]);
    expect($result['ok'])->toBeTrue();
    expect($result['data'])->toMatchArray(['state' => 'unknown', 'authorizes_submission' => false, 'revision' => 2, 'blocked' => true]);
    expect($result['data']['blockers'])->toBe(['reconciliation_required']);
    expect($result['data']['events'])->toHaveCount(1);
    expect($result['data']['next_offset'])->toBe(1);
    $this->assertDatabaseHas('application_submission_attempts', ['id' => $attempt['attempt_id'], 'state' => 'sending', 'revision' => 2,
        'active_application_id' => $attempt['application_id']]);
    expect(approvalCall($this, 'reserve_application_submission', ['approval_id' => $attempt['approval_id'],
        'expected_fingerprint' => $this->prepared['fingerprint'], 'idempotency_key' => 'second'])['error']['code'])->toBe('conflict');
    $application = approvalCall($this, 'get_application', ['job_posting_id' => $this->posting->id]);
    expect($application['data']['submission']['attempt_id'])->toBe($attempt['attempt_id']);
});

it('records historical success after approval revocation without changing tracking and replays identical evidence', function () {
    $attempt = submissionConsumed($this);
    ApplicationApproval::query()->findOrFail($attempt['approval_id'])->update(['revoked_at' => now()]);
    $input = ['attempt_id' => $attempt['attempt_id'], 'event_key' => 'receipt-1', 'outcome' => 'succeeded',
        'occurred_at' => now()->toISOString(), 'evidence' => ['kind' => 'submission_receipt', 'reference' => 'provider-receipt-123']];
    $result = approvalCall($this, 'report_application_submission', $input);
    expect($result['ok'])->toBeTrue();
    expect($result['data'])->toMatchArray(['state' => 'succeeded', 'authorizes_submission' => false, 'revision' => 3]);
    expect(approvalCall($this, 'report_application_submission', $input)['data'])->toBe($result['data']);
    $sameInstant = CarbonImmutable::parse($input['occurred_at'])->setTimezone('America/Sao_Paulo')->format('Y-m-d\TH:i:s.uP');
    expect(approvalCall($this, 'report_application_submission', [...$input, 'occurred_at' => $sameInstant])['data'])->toBe($result['data']);
    expect(approvalCall($this, 'report_application_submission', [...$input, 'evidence' => ['kind' => 'submission_receipt', 'reference' => 'different']])['error']['code'])->toBe('conflict');
    $this->assertDatabaseCount('application_submission_events', 3);
    expect($this->posting->application->status->value)->toBe('planned');
    expect(DB::table('application_submission_events')->where('event_key', 'receipt-1')->value('evidence'))->not->toContain('provider-receipt-123');
    $query = approvalCall($this, 'get_application_submission', ['attempt_id' => $attempt['attempt_id']]);
    expect($query['data']['events'][2]['evidence'])->toBe($input['evidence']);
    expect(json_encode($query))->not->toContain('request_hash', 'actor', 'documents/');
});

it('recovers validated submission evidence through paginated HTTP MCP after losing the callback response', function () {
    $attempt = submissionConsumed($this);
    app('auth')->forgetGuards();
    $this->withToken($this->token)->postJson('/mcp', Helpers::toolCall('report_application_submission', [
        'attempt_id' => $attempt['attempt_id'], 'event_key' => 'lost-receipt', 'outcome' => 'succeeded', 'occurred_at' => now()->toISOString(),
        'evidence' => ['kind' => 'submission_receipt', 'reference' => 'receipt-recovered', 'details' => 'A plataforma confirmou o recebimento.'],
    ]))->assertOk();
    $recovered = approvalCall($this, 'get_application_submission', ['attempt_id' => $attempt['attempt_id'], 'offset' => 2, 'limit' => 1]);
    expect($recovered['data']['state'])->toBe('succeeded');
    expect($recovered['data']['authorizes_submission'])->toBeFalse();
    expect($recovered['data']['events'])->toHaveCount(1);
    expect($recovered['data']['events'][0])->toMatchArray(['event_key' => 'lost-receipt', 'outcome' => 'succeeded',
        'evidence' => ['kind' => 'submission_receipt', 'reference' => 'receipt-recovered', 'details' => 'A plataforma confirmou o recebimento.']]);
    expect(json_encode($recovered))->not->toContain('request_hash', 'actor', 'documents/', $this->token);
});

it('reconciles unknown with revision and proof of no submission plus executor termination before releasing retry', function () {
    $attempt = submissionConsumed($this);
    $this->travel(11)->minutes();
    $input = ['attempt_id' => $attempt['attempt_id'], 'expected_revision' => 2, 'event_key' => 'reconcile-1', 'outcome' => 'failed',
        'evidence' => ['kind' => 'not_submitted', 'reference' => 'provider-absence-123'], 'executor_stopped' => false];
    $denied = approvalCall($this, 'reconcile_application_submission', $input);
    expect($denied['error']['code'])->toBe('precondition');
    $this->assertDatabaseHas('application_submission_attempts', ['id' => $attempt['attempt_id'], 'revision' => 2, 'active_application_id' => $attempt['application_id']]);
    expect(approvalCall($this, 'reconcile_application_submission', [...$input, 'expected_revision' => 1, 'executor_stopped' => true])['error']['code'])->toBe('conflict');
    $result = approvalCall($this, 'reconcile_application_submission', [...$input, 'executor_stopped' => true]);
    expect($result['ok'])->toBeTrue();
    expect($result['data'])->toMatchArray(['state' => 'failed', 'revision' => 3, 'authorizes_submission' => false]);
    $this->assertDatabaseHas('application_submission_attempts', ['id' => $attempt['attempt_id'], 'active_application_id' => null, 'consumed_approval_id' => $attempt['approval_id']]);
    expect(approvalCall($this, 'report_application_submission', ['attempt_id' => $attempt['attempt_id'], 'event_key' => 'late', 'outcome' => 'succeeded',
        'occurred_at' => $attempt['consumed_at'], 'evidence' => ['kind' => 'submission_receipt', 'reference' => 'late-receipt']])['error']['code'])->toBe('conflict');
    expect(approvalCall($this, 'reserve_application_submission', ['approval_id' => $attempt['approval_id'],
        'expected_fingerprint' => $this->prepared['fingerprint'], 'idempotency_key' => 'retry'])['error']['code'])->toBe('precondition');
});

it('records inconclusive reconciliation observations idempotently without releasing the active attempt', function () {
    $attempt = submissionConsumed($this);
    $this->travel(11)->minutes();
    $input = ['attempt_id' => $attempt['attempt_id'], 'expected_revision' => 2, 'event_key' => 'inconclusive-1', 'outcome' => 'unknown',
        'evidence' => ['kind' => 'ambiguous', 'details' => 'A plataforma ainda não permite confirmar o resultado.']];
    $observed = approvalCall($this, 'reconcile_application_submission', $input);
    expect($observed['ok'])->toBeTrue();
    expect($observed['data'])->toMatchArray(['state' => 'unknown', 'revision' => 3, 'resolved_at' => null, 'blocked' => true, 'authorizes_submission' => false]);
    expect(approvalCall($this, 'reconcile_application_submission', $input)['data'])->toBe($observed['data']);
    expect(approvalCall($this, 'reconcile_application_submission', [...$input, 'event_key' => 'stale-observation'])['error']['code'])->toBe('conflict');
    expect(approvalCall($this, 'reconcile_application_submission', [...$input, 'evidence' => ['kind' => 'ambiguous', 'details' => 'Outro conteúdo.']])['error']['code'])->toBe('conflict');
    $this->assertDatabaseCount('application_submission_events', 3);
    $this->assertDatabaseHas('application_submission_attempts', ['id' => $attempt['attempt_id'], 'state' => 'unknown', 'revision' => 3,
        'active_application_id' => $attempt['application_id'], 'resolved_at' => null, 'consumed_approval_id' => $attempt['approval_id']]);
    $queried = approvalCall($this, 'get_application_submission', ['attempt_id' => $attempt['attempt_id'], 'offset' => 2, 'limit' => 1]);
    expect($queried['data']['events'][0])->toMatchArray(['kind' => 'reconciled', 'outcome' => 'unknown', 'evidence' => $input['evidence']]);
    expect(approvalCall($this, 'reconcile_application_submission', [...$input, 'expected_revision' => 3, 'event_key' => 'inconclusive-2'])['data']['revision'])->toBe(4);
    $this->assertDatabaseCount('application_submission_events', 4);
});

it('never promotes a revoked token or another integration into owner session reconciliation', function () {
    $attempt = submissionConsumed($this);
    $this->travel(11)->minutes();
    app(AgentIntegrationService::class)->revoke($this->integration);
    $input = ['attempt_id' => $attempt['attempt_id'], 'expected_revision' => 2, 'event_key' => 'forged-owner', 'outcome' => 'failed',
        'evidence' => ['kind' => 'not_submitted', 'reference' => 'claimed-absence'], 'executor_stopped' => true,
        'owner_session' => true, 'actor' => ['source' => 'filament', 'user_id' => $this->owner->id]];
    app('auth')->forgetGuards();
    expect($this->withToken($this->token)->postJson('/mcp', Helpers::toolCall('reconcile_application_submission', $input))->status())->toBeIn([401, 403]);
    $otherClient = Helpers::client('Other executor after revocation');
    Helpers::integration($this->owner, $otherClient, ['applications:submit']);
    $this->token = Helpers::accessToken($this->owner, $otherClient);
    expect(approvalCall($this, 'reconcile_application_submission', $input)['error']['code'])->toBe('not_found');
    $this->assertDatabaseHas('application_submission_attempts', ['id' => $attempt['attempt_id'], 'state' => 'sending', 'revision' => 2,
        'active_application_id' => $attempt['application_id'], 'resolved_at' => null]);
    $this->assertDatabaseCount('application_submission_events', 2);
});

it('deduplicates keyed tracking callbacks without reapplying status and preserves unkeyed V1 updates', function () {
    $this->integration->update(['scopes' => ['applications:write', 'applications:read']]);
    $application = $this->posting->application;
    $input = ['application_id' => $application->id, 'status' => 'submitted', 'title' => 'Enviada', 'event_key' => 'tracking-1'];
    $first = approvalCall($this, 'record_application_update', $input);
    expect($first['ok'])->toBeTrue();
    $this->travel(2)->minutes();
    expect(approvalCall($this, 'record_application_update', ['application_id' => $application->id, 'status' => 'rejected'])['ok'])->toBeTrue();
    $replay = approvalCall($this, 'record_application_update', $input);
    expect($replay['data']['event']['id'])->toBe($first['data']['event']['id']);
    expect($replay['data']['application']['status'])->toBe('rejected');
    expect(approvalCall($this, 'record_application_update', [...$input, 'status' => 'planned'])['error']['code'])->toBe('conflict');
    foreach (range(1, 2) as $ignored) {
        expect(approvalCall($this, 'record_application_update', ['application_id' => $application->id, 'status' => 'planned'])['ok'])->toBeTrue();
    }
    expect($application->events()->count())->toBe(4);
    expect($application->fresh()->status->value)->toBe('planned');
});

it('requires approval recorded after failure resolution even when an older attestation has a future approved time', function () {
    $attempt = submissionConsumed($this);
    $old = approvalCall($this, 'record_application_approval', [...$this->approvalInput, 'message_id' => '3',
        'approved_at' => now()->addMinutes(4)->toISOString()])['data'];
    expect(approvalCall($this, 'report_application_submission', ['attempt_id' => $attempt['attempt_id'], 'event_key' => 'failed-1', 'outcome' => 'failed',
        'occurred_at' => now()->toISOString(), 'error_category' => 'rejected', 'evidence' => ['kind' => 'not_submitted', 'reference' => 'rejected-before-send']])['ok'])->toBeTrue();
    expect(approvalCall($this, 'reserve_application_submission', ['approval_id' => $old['id'],
        'expected_fingerprint' => $this->prepared['fingerprint'], 'idempotency_key' => 'old-approval'])['error']['code'])->toBe('precondition');
    $this->travel(5)->minutes();
    $new = approvalCall($this, 'record_application_approval', [...$this->approvalInput, 'message_id' => '4', 'approved_at' => now()->toISOString()])['data'];
    expect(approvalCall($this, 'reserve_application_submission', ['approval_id' => $new['id'],
        'expected_fingerprint' => $this->prepared['fingerprint'], 'idempotency_key' => 'fresh-approval'])['data']['state'])->toBe('reserved');
});

it('revalidates frozen dependencies before consuming and leaves permission unused', function (string $change) {
    $attempt = submissionReserved($this);
    approvalPanel($this);
    match ($change) {
        'approval' => app(ApplicationApprovalService::class)->revoke($this->owner, $attempt['approval_id']),
        'binding' => TelegramIdentityBinding::query()->where('user_id', $this->owner->id)->firstOrFail()->update(['revoked_at' => now(), 'active_user_id' => null]),
        'approval_scope' => $this->integration->update(['scopes' => ['applications:submit', 'applications:read']]),
        'profile' => $this->owner->professionalProfile()->firstOrFail()->update(['confirmed_snapshot' => ['competencies' => ['Rust']]]),
        'resume' => $this->resume->update(['content' => 'Changed']),
        'posting' => $this->posting->update(['readiness' => 'pending_review']),
        'artifact' => app(DocumentArtifactService::class)->revoke($this->owner, $this->prepared['artifacts'][0]['artifact_id']),
        'preparation' => app(ApplicationPreparationService::class)->prepare($this->owner, $this->preparationInput),
        'expiry' => $this->travel(31)->minutes(),
    };
    $result = approvalCall($this, 'consume_application_submission', ['attempt_id' => $attempt['attempt_id'], 'expected_revision' => 1,
        'expected_fingerprint' => $attempt['fingerprint'], 'event_key' => 'consume-after-change']);
    expect($result['error']['code'])->toBe('precondition');
    $this->assertDatabaseHas('application_submission_attempts', ['id' => $attempt['attempt_id'], 'revision' => 1, 'consumed_at' => null, 'consumed_approval_id' => null]);
    $this->assertDatabaseCount('application_submission_events', 1);
})->with(['approval', 'binding', 'approval_scope', 'profile', 'resume', 'posting', 'artifact', 'preparation', 'expiry']);

it('rejects missing approval wrong fingerprints stale revisions and conflicting reservation keys', function () {
    $attempt = submissionReserved($this);
    expect(approvalCall($this, 'reserve_application_submission', ['expected_fingerprint' => $attempt['fingerprint'], 'idempotency_key' => 'missing'])['error']['code'])->toBe('validation');
    expect(approvalCall($this, 'reserve_application_submission', ['approval_id' => (string) Str::uuid(),
        'expected_fingerprint' => $attempt['fingerprint'], 'idempotency_key' => 'missing'])['error']['code'])->toBe('not_found');
    expect(approvalCall($this, 'reserve_application_submission', ['approval_id' => $attempt['approval_id'],
        'expected_fingerprint' => str_repeat('f', 64), 'idempotency_key' => 'send-1'])['error']['code'])->toBe('conflict');
    foreach ([['expected_revision' => 2], ['expected_fingerprint' => str_repeat('f', 64)]] as $change) {
        expect(approvalCall($this, 'consume_application_submission', [...['attempt_id' => $attempt['attempt_id'], 'expected_revision' => 1,
            'expected_fingerprint' => $attempt['fingerprint'], 'event_key' => 'consume'], ...$change])['error']['code'])->toBe('conflict');
    }
    $this->assertDatabaseHas('application_submission_attempts', ['id' => $attempt['attempt_id'], 'consumed_at' => null]);
});

it('keeps mutation ownership bound to the approval integration while owner reads remain available', function (bool $otherOwner) {
    $attempt = submissionReserved($this);
    $user = $otherOwner ? User::factory()->create() : $this->owner;
    $client = Helpers::client('Other executor');
    Helpers::integration($user, $client, ['approvals:write', 'applications:submit', 'applications:read']);
    $this->token = Helpers::accessToken($user, $client);
    expect(approvalCall($this, 'reserve_application_submission', ['approval_id' => $attempt['approval_id'],
        'expected_fingerprint' => $attempt['fingerprint'], 'idempotency_key' => 'other'])['error']['code'])->toBe('not_found');
    expect(approvalCall($this, 'consume_application_submission', ['attempt_id' => $attempt['attempt_id'], 'expected_revision' => 1,
        'expected_fingerprint' => $attempt['fingerprint'], 'event_key' => 'other'])['error']['code'])->toBe('not_found');
    $get = approvalCall($this, 'get_application_submission', ['attempt_id' => $attempt['attempt_id']]);
    expect($otherOwner ? $get['error']['code'] : $get['data']['state'])->toBe($otherOwner ? 'not_found' : 'reserved');
    $this->assertDatabaseHas('application_submission_attempts', ['id' => $attempt['attempt_id'], 'consumed_at' => null]);
})->with([true, false]);

it('does not turn stdio or forged actor metadata into remote submission permission', function () {
    $attempt = submissionReserved($this);
    approvalPanel($this);
    config(['jobora.mcp.user' => (string) $this->owner->id]);
    JoboraServer::actingAs($this->owner)->tool(ReserveApplicationSubmissionTool::class, [
        'approval_id' => $attempt['approval_id'], 'expected_fingerprint' => $attempt['fingerprint'], 'idempotency_key' => 'stdio',
        'actor' => ['source' => 'mcp', 'integration_id' => $this->integration->id],
    ])->assertHasErrors(['forbidden']);
    JoboraServer::actingAs($this->owner)->tool(ConsumeApplicationSubmissionTool::class, [
        'attempt_id' => $attempt['attempt_id'], 'expected_revision' => 1, 'expected_fingerprint' => $attempt['fingerprint'], 'event_key' => 'stdio',
    ])->assertHasErrors(['forbidden']);
    $this->assertDatabaseHas('application_submission_attempts', ['id' => $attempt['attempt_id'], 'consumed_at' => null]);
});

it('does not grant submit retroactively and denies revoked authorization before consuming', function (string $change) {
    $attempt = submissionReserved($this);
    match ($change) {
        'scope' => $this->integration->update(['scopes' => ['applications:write', 'approvals:write']]),
        'integration' => app(AgentIntegrationService::class)->revoke($this->integration),
        'account' => $this->owner->forceFill(['disabled_at' => now()])->save(),
        'token' => DB::table('oauth_access_tokens')->where('user_id', $this->owner->id)->update(['revoked' => true]),
    };
    app('auth')->forgetGuards();
    $response = $this->withToken($this->token)->postJson('/mcp', Helpers::toolCall('consume_application_submission', [
        'attempt_id' => $attempt['attempt_id'], 'expected_revision' => 1, 'expected_fingerprint' => $attempt['fingerprint'], 'event_key' => 'denied',
    ]));
    if ($change === 'scope') {
        expect(Helpers::decodeToolResult($response->assertOk()->getContent())['error']['code'])->toBe('forbidden');
    } else {
        expect($response->status())->toBeIn([401, 403]);
    }
    $this->assertDatabaseHas('application_submission_attempts', ['id' => $attempt['attempt_id'], 'consumed_at' => null]);
})->with(['scope', 'integration', 'account', 'token']);

it('expires only unconsumed reservations and permits a new reservation without renewing the old one', function () {
    $attempt = submissionReserved($this);
    $this->travel(6)->minutes();
    $input = ['approval_id' => $attempt['approval_id'], 'expected_fingerprint' => $attempt['fingerprint']];
    $old = approvalCall($this, 'reserve_application_submission', [...$input, 'idempotency_key' => 'send-1'])['data'];
    expect($old)->toMatchArray(['state' => 'expired', 'authorizes_submission' => false, 'revision' => 1]);
    expect($old['reservation_expires_at'])->toBe($attempt['reservation_expires_at']);
    $new = approvalCall($this, 'reserve_application_submission', [...$input, 'idempotency_key' => 'new-reservation'])['data'];
    expect($new['attempt_id'])->not->toBe($attempt['attempt_id']);
    expect($new['state'])->toBe('reserved');
    expect(approvalCall($this, 'consume_application_submission', ['attempt_id' => $attempt['attempt_id'], 'expected_revision' => 1,
        'expected_fingerprint' => $attempt['fingerprint'], 'event_key' => 'late-consume'])['error']['code'])->toBe('conflict');
    $this->assertDatabaseHas('application_submission_attempts', ['id' => $attempt['attempt_id'], 'state' => 'expired', 'consumed_at' => null]);
    $this->assertDatabaseCount('application_submission_events', 3);
});

it('never treats ambiguous errors as proven failure and only reconciles explicit unknown', function (string $category) {
    $attempt = submissionConsumed($this);
    $input = ['attempt_id' => $attempt['attempt_id'], 'event_key' => 'ambiguous-1', 'outcome' => 'failed', 'occurred_at' => now()->toISOString(),
        'error_category' => $category, 'evidence' => ['kind' => 'not_submitted', 'reference' => 'unreliable']];
    expect(approvalCall($this, 'report_application_submission', $input)['error']['code'])->toBe('precondition');
    $unknown = approvalCall($this, 'report_application_submission', [...$input, 'outcome' => 'unknown', 'evidence' => ['kind' => 'ambiguous']])['data'];
    expect($unknown)->toMatchArray(['state' => 'unknown', 'revision' => 3, 'blocked' => true]);
    expect(approvalCall($this, 'report_application_submission', [...$input, 'event_key' => 'late-success', 'outcome' => 'succeeded',
        'evidence' => ['kind' => 'submission_receipt', 'reference' => 'late']])['error']['code'])->toBe('conflict');
    $reconciled = approvalCall($this, 'reconcile_application_submission', ['attempt_id' => $attempt['attempt_id'], 'expected_revision' => 3,
        'event_key' => 'reconciled-success', 'outcome' => 'succeeded', 'evidence' => ['kind' => 'submission_receipt', 'reference' => 'verified']])['data'];
    expect($reconciled)->toMatchArray(['state' => 'succeeded', 'revision' => 4]);
})->with(['timeout', 'connection']);

it('keeps unknown blocking even after a new preparation and approval and isolates late callbacks from a later attempt', function () {
    $first = submissionConsumed($this);
    $this->travel(1)->minutes();
    expect(approvalCall($this, 'report_application_submission', ['attempt_id' => $first['attempt_id'], 'event_key' => 'unknown', 'outcome' => 'unknown',
        'occurred_at' => now()->toISOString(), 'evidence' => ['kind' => 'ambiguous']])['ok'])->toBeTrue();
    approvalPanel($this);
    $newPreparation = app(ApplicationPreparationService::class)->prepare($this->owner, [...$this->preparationInput,
        'destination' => ['type' => 'email', 'email' => 'new@example.com']]);
    $approvalInput = [...$this->approvalInput, 'preparation_id' => $newPreparation['preparation_id'], 'expected_fingerprint' => $newPreparation['fingerprint'],
        'message_id' => '3', 'approved_at' => now()->toISOString()];
    $beforeResolution = approvalCall($this, 'record_application_approval', $approvalInput)['data'];
    expect(approvalCall($this, 'reserve_application_submission', ['approval_id' => $beforeResolution['id'],
        'expected_fingerprint' => $newPreparation['fingerprint'], 'idempotency_key' => 'blocked'])['error']['code'])->toBe('conflict');
    $reconcileInput = ['attempt_id' => $first['attempt_id'], 'expected_revision' => 3, 'event_key' => 'reconcile', 'outcome' => 'failed',
        'executor_stopped' => true, 'evidence' => ['kind' => 'not_submitted', 'reference' => 'absence-and-termination']];
    expect(approvalCall($this, 'reconcile_application_submission', $reconcileInput)['ok'])->toBeTrue();
    expect(approvalCall($this, 'reserve_application_submission', ['approval_id' => $beforeResolution['id'],
        'expected_fingerprint' => $newPreparation['fingerprint'], 'idempotency_key' => 'still-old'])['error']['code'])->toBe('precondition');
    $this->travel(1)->minutes();
    $fresh = approvalCall($this, 'record_application_approval', [...$approvalInput, 'message_id' => '4', 'approved_at' => now()->toISOString()])['data'];
    $second = approvalCall($this, 'reserve_application_submission', ['approval_id' => $fresh['id'],
        'expected_fingerprint' => $newPreparation['fingerprint'], 'idempotency_key' => 'new-attempt'])['data'];
    expect(approvalCall($this, 'report_application_submission', ['attempt_id' => $first['attempt_id'], 'event_key' => 'late', 'outcome' => 'succeeded',
        'occurred_at' => $first['consumed_at'], 'evidence' => ['kind' => 'submission_receipt', 'reference' => 'late-receipt']])['error']['code'])->toBe('conflict');
    expect(approvalCall($this, 'reconcile_application_submission', $reconcileInput)['data']['state'])->toBe('failed');
    $this->assertDatabaseHas('application_submission_attempts', ['id' => $second['attempt_id'], 'state' => 'reserved', 'revision' => 1,
        'active_application_id' => $second['application_id'], 'consumed_at' => null]);
    expect(approvalCall($this, 'get_application', ['job_posting_id' => $this->posting->id])['data']['submission']['attempt_id'])->toBe($second['attempt_id']);
});

it('rechecks ATS permission on consume even when the approved material remains unchanged', function () {
    $this->owner->update(['can_use_ats_optimization' => true]);
    $this->posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'user']);
    $source = app(AiInputSnapshotService::class)->resumeAdaptation($this->owner, $this->posting, ResumeLanguage::PtBr);
    $snapshot = app(AiInputSnapshotService::class)->atsOptimization($this->owner, $this->posting, true, ResumeLanguage::PtBr);
    $this->posting->resumeAdaptations()->firstOrFail()->update(['source_fingerprint' => $source['fingerprint'],
        'is_ats_optimized' => true, 'ats_prompt' => $snapshot['payload']['ats_prompt'], 'ats_prompt_fingerprint' => $snapshot['fingerprint']]);
    $this->prepared = app(ApplicationPreparationService::class)->prepare($this->owner, [...$this->preparationInput, 'ats_consent' => true]);
    $this->approvalInput = [...$this->approvalInput, 'preparation_id' => $this->prepared['preparation_id'], 'expected_fingerprint' => $this->prepared['fingerprint']];
    $attempt = submissionReserved($this);
    $this->owner->update(['can_use_ats_optimization' => false]);
    expect(approvalCall($this, 'consume_application_submission', ['attempt_id' => $attempt['attempt_id'], 'expected_revision' => 1,
        'expected_fingerprint' => $attempt['fingerprint'], 'event_key' => 'ats-consume'])['error']['code'])->toBe('precondition');
    $this->assertDatabaseHas('application_submission_attempts', ['id' => $attempt['attempt_id'], 'consumed_at' => null]);
});

it('never sets the result deadline before the permitted execution window', function () {
    config(['application-submissions.execution_seconds' => 60, 'application-submissions.result_seconds' => 1]);
    $attempt = submissionConsumed($this);
    expect(CarbonImmutable::parse($attempt['result_due_at'])->gte(CarbonImmutable::parse($attempt['execute_before'])))->toBeTrue();
});

it('preserves existing grants when OAuth registers an already known integration', function () {
    $existing = app(AgentIntegrationService::class)->register($this->owner, (string) $this->client->id, 'Existing Hermes');
    expect($existing->scopes)->toBe(['approvals:read', 'approvals:write']);
    expect(approvalCall($this, 'reserve_application_submission', ['approval_id' => (string) Str::uuid(),
        'expected_fingerprint' => $this->prepared['fingerprint'], 'idempotency_key' => 'not-granted'])['error']['code'])->toBe('forbidden');
    $this->assertDatabaseCount('application_submission_attempts', 0);
});

it('preserves attempt identity consumed approval and append-only submission evidence', function () {
    $result = submissionConsumed($this);
    $attempt = ApplicationSubmissionAttempt::query()->findOrFail($result['attempt_id']);
    expect(fn () => $attempt->update(['fingerprint' => str_repeat('a', 64)]))->toThrow(LogicException::class);
    expect(fn () => $attempt->refresh()->update(['consumed_approval_id' => null]))->toThrow(LogicException::class);
    $event = $attempt->refresh()->events()->firstOrFail();
    expect(fn () => $event->update(['evidence' => ['changed' => true]]))->toThrow(LogicException::class);
    expect(fn () => $event->refresh()->delete())->toThrow(LogicException::class);
    $this->assertDatabaseHas('application_submission_attempts', ['id' => $attempt->id, 'fingerprint' => $result['fingerprint'], 'consumed_approval_id' => $result['approval_id']]);
    $this->assertDatabaseCount('application_submission_events', 2);
});

it('preserves tracking history through the additive nullable idempotency migration', function () {
    $event = app(ApplicationTrackingService::class)->recordUpdate($this->owner, $this->posting->application,
        ApplicationStatus::Interview, 'Entrevista', 'Histórico V1', 'recruiter@example.com', now()->subDay());
    $before = DB::table('application_events')->where('id', $event->id)->first();
    $migration = require database_path('migrations/2026_11_07_103006_add_idempotency_to_application_events_table.php');
    $migration->down();
    $migration->up();
    expect(DB::table('application_events')->where('id', $event->id)->first())->toEqual($before);
    expect($this->posting->application->fresh()->status->value)->toBe('interview');
});

function submissionRequirePostgres(object $test): void
{
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $test->markTestSkipped('Corrida real de envio exige PostgreSQL isolado; execução coordenada em #38.');
    }
    expect((string) DB::connection()->getDatabaseName())->toContain('testing');
}

/**
 * @param  array{kind: 'revoke', user_id: int, approval_id: string}|array{kind: 'prepare', user_id: int, input: array{job_posting_id: int, expected_opportunity_version: int, expected_profile_confirmed_version: int, destination: array{type: string, email: string}}}|array{kind: 'http', token: string, tool: string, input: array{approval_id?: string, expected_fingerprint?: string, idempotency_key?: string, attempt_id?: string, expected_revision?: int, event_key?: string, outcome?: 'succeeded'|'failed'|'unknown', evidence?: array{kind: 'submission_receipt'|'not_submitted'|'ambiguous', reference?: string, details?: string}, executor_stopped?: bool, occurred_at?: string}}  $left
 * @param  array{kind: 'revoke', user_id: int, approval_id: string}|array{kind: 'prepare', user_id: int, input: array{job_posting_id: int, expected_opportunity_version: int, expected_profile_confirmed_version: int, destination: array{type: string, email: string}}}|array{kind: 'http', token: string, tool: string, input: array{approval_id?: string, expected_fingerprint?: string, idempotency_key?: string, attempt_id?: string, expected_revision?: int, event_key?: string, outcome?: 'succeeded'|'failed'|'unknown', evidence?: array{kind: 'submission_receipt'|'not_submitted'|'ambiguous', reference?: string, details?: string}, executor_stopped?: bool, occurred_at?: string}}  $right
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function submissionRace(object $test, array $left, array $right): array
{
    $directory = storage_path('framework/testing/submission-race-'.Str::uuid());
    File::makeDirectory($directory, recursive: true);
    $settings = ['database' => config('database.connections.pgsql'), 'artifact_root' => Storage::disk('local')->path(''), 'barrier' => $directory];
    DB::commit();
    try {
        return Concurrency::driver('process')->run([
            fn () => ApplicationSubmissionConcurrencyTasks::run($settings, $left, 'left'),
            fn () => ApplicationSubmissionConcurrencyTasks::run($settings, $right, 'right'),
        ]);
    } finally {
        File::deleteDirectory($directory);
        RefreshDatabaseState::$migrated = false;
    }
}

it('serializes two reservation processes on PostgreSQL into one active attempt', function () {
    submissionRequirePostgres($this);
    $attempt = submissionReserved($this);
    ApplicationSubmissionAttempt::query()->findOrFail($attempt['attempt_id'])->update(['state' => 'cancelled', 'active_application_id' => null, 'resolved_at' => now()]);
    $input = ['approval_id' => $attempt['approval_id'], 'expected_fingerprint' => $attempt['fingerprint']];
    $results = submissionRace($this,
        ['kind' => 'http', 'token' => $this->token, 'tool' => 'reserve_application_submission', 'input' => [...$input, 'idempotency_key' => 'race-a']],
        ['kind' => 'http', 'token' => $this->token, 'tool' => 'reserve_application_submission', 'input' => [...$input, 'idempotency_key' => 'race-b']]);
    expect(collect($results)->where('ok', true))->toHaveCount(1);
    expect(collect($results)->where('ok', false)->first()['error']['code'])->toBe('conflict');
    expect(ApplicationSubmissionAttempt::query()->whereNotNull('active_application_id')->count())->toBe(1);
});

it('authorizes only one of two synchronized consume processes on PostgreSQL', function () {
    submissionRequirePostgres($this);
    $attempt = submissionReserved($this);
    $input = ['attempt_id' => $attempt['attempt_id'], 'expected_revision' => 1, 'expected_fingerprint' => $attempt['fingerprint'], 'event_key' => 'same-consume'];
    $task = ['kind' => 'http', 'token' => $this->token, 'tool' => 'consume_application_submission', 'input' => $input];
    $results = submissionRace($this, $task, $task);
    expect(collect($results)->pluck('data.authorizes_submission')->filter())->toHaveCount(1);
    expect(collect($results)->where('ok', true))->toHaveCount(2);
    $this->assertDatabaseCount('application_submission_events', 2);
    $this->assertDatabaseHas('application_submission_attempts', ['id' => $attempt['attempt_id'], 'revision' => 2, 'consumed_approval_id' => $attempt['approval_id']]);
});

it('orders consume against approval revocation or new preparation on PostgreSQL', function (string $mutation) {
    submissionRequirePostgres($this);
    $attempt = submissionReserved($this);
    $results = submissionRace($this,
        ['kind' => 'http', 'token' => $this->token, 'tool' => 'consume_application_submission', 'input' => [
            'attempt_id' => $attempt['attempt_id'], 'expected_revision' => 1, 'expected_fingerprint' => $attempt['fingerprint'], 'event_key' => 'race-consume']],
        ['kind' => $mutation, 'user_id' => $this->owner->id, 'approval_id' => $attempt['approval_id'], 'input' => $this->preparationInput]);
    $stored = ApplicationSubmissionAttempt::query()->findOrFail($attempt['attempt_id']);
    if ($results[0]['ok']) {
        expect($results[0]['data']['authorizes_submission'])->toBeTrue();
        expect($stored->consumed_at)->not->toBeNull();
        $mutationAt = $mutation === 'revoke' ? ApplicationApproval::query()->findOrFail($attempt['approval_id'])->revoked_at
            : ApplicationPreparation::query()->findOrFail($results[1]['preparation_id'])->created_at;
        expect($stored->consumed_at->timestamp)->toBeLessThanOrEqual($mutationAt->timestamp);
    } else {
        expect($results[0]['error']['code'])->toBe('precondition');
        expect($stored->consumed_at)->toBeNull();
    }
})->with(['revoke', 'prepare']);

it('resolves an unknown revision only once across two PostgreSQL processes', function () {
    submissionRequirePostgres($this);
    $attempt = submissionConsumed($this);
    expect(approvalCall($this, 'report_application_submission', ['attempt_id' => $attempt['attempt_id'], 'event_key' => 'uncertain', 'outcome' => 'unknown',
        'occurred_at' => now()->toISOString(), 'evidence' => ['kind' => 'ambiguous']])['ok'])->toBeTrue();
    $input = ['attempt_id' => $attempt['attempt_id'], 'expected_revision' => 3, 'outcome' => 'succeeded', 'evidence' => ['kind' => 'submission_receipt', 'reference' => 'verified']];
    $results = submissionRace($this,
        ['kind' => 'http', 'token' => $this->token, 'tool' => 'reconcile_application_submission', 'input' => [...$input, 'event_key' => 'reconcile-left']],
        ['kind' => 'http', 'token' => $this->token, 'tool' => 'reconcile_application_submission', 'input' => [...$input, 'event_key' => 'reconcile-right']]);
    expect(collect($results)->where('ok', true))->toHaveCount(1);
    expect(collect($results)->where('ok', false)->first()['error']['code'])->toBe('conflict');
    $this->assertDatabaseHas('application_submission_attempts', ['id' => $attempt['attempt_id'], 'state' => 'succeeded', 'revision' => 4]);
});

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
