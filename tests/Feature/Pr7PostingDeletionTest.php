<?php

use App\Models\Application;
use App\Models\ApplicationApproval;
use App\Models\ApplicationPreparation;
use App\Models\ApplicationSubmissionAttempt;
use App\Models\ApplicationSubmissionEvent;
use App\Models\TelegramIdentityBinding;
use App\Models\TelegramLinkChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->application = Application::factory()->create();
    $this->posting = $this->application->jobPosting;
    $this->owner = User::findOrFail($this->posting->user_id);
    $this->integration = Helpers::integration($this->owner, Helpers::client());
    $this->preparation = ApplicationPreparation::query()->create([
        'user_id' => $this->owner->id, 'application_id' => $this->application->id,
        'language' => 'pt-BR', 'schema_version' => '1', 'state' => 'ready', 'snapshot' => [],
        'pending' => [], 'source_versions' => [], 'source_hash' => str_repeat('a', 64), 'fingerprint' => str_repeat('b', 64),
    ]);
    $challenge = TelegramLinkChallenge::query()->create([
        'user_id' => $this->owner->id, 'integration_id' => $this->integration->id,
        'challenge_hash' => str_repeat('c', 64), 'expires_at' => now()->addMinutes(5),
    ]);
    $this->binding = TelegramIdentityBinding::query()->create([
        'user_id' => $this->owner->id, 'integration_id' => $this->integration->id,
        'challenge_id' => $challenge->id, 'telegram_identity' => [], 'confirmed_at' => now(),
    ]);
    $this->approval = ApplicationApproval::query()->create([
        'user_id' => $this->owner->id, 'integration_id' => $this->integration->id,
        'preparation_id' => $this->preparation->id, 'binding_id' => $this->binding->id,
        'fingerprint' => str_repeat('b', 64), 'message_key' => str_repeat('d', 64), 'request_hash' => str_repeat('e', 64),
        'telegram_identity' => [], 'recorded_actor' => [], 'approved_at' => now(), 'received_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);
});

it('deletes posting submission attempts in every state and their events', function (string $state) {
    $attempt = ApplicationSubmissionAttempt::query()->create([
        'user_id' => $this->owner->id, 'application_id' => $this->application->id,
        'integration_id' => $this->integration->id, 'approval_id' => $this->approval->id,
        'preparation_id' => $this->preparation->id, 'fingerprint' => $this->preparation->fingerprint,
        'idempotency_key' => 'regression', 'request_hash' => str_repeat('f', 64), 'state' => $state,
        'active_application_id' => in_array($state, ['reserved', 'sending', 'unknown'], true) ? $this->application->id : null,
        'consumed_approval_id' => $state === 'reserved' ? null : $this->approval->id,
        'reserved_at' => now(), 'reservation_expires_at' => now()->addMinutes(5),
    ]);
    $event = ApplicationSubmissionEvent::query()->create([
        'attempt_id' => $attempt->id, 'event_key' => 'regression', 'kind' => 'reserved',
        'request_hash' => str_repeat('f', 64), 'evidence' => [], 'actor' => [],
        'occurred_at' => now(), 'received_at' => now(),
    ]);
    $this->posting->delete();

    $this->assertDatabaseMissing('application_submission_attempts', ['id' => $attempt->id]);
    $this->assertDatabaseMissing('application_submission_events', ['id' => $event->id]);
    $this->assertDatabaseMissing('application_approvals', ['id' => $this->approval->id]);
    $this->assertDatabaseMissing('applications', ['id' => $this->application->id]);
})->with(['reserved', 'sending', 'unknown', 'failed', 'succeeded']);

it('deletes an approved posting and its preparation without deleting the identity binding', function () {
    $this->posting->delete();

    $this->assertDatabaseMissing('applications', ['id' => $this->application->id]);
    $this->assertDatabaseMissing('application_preparations', ['id' => $this->preparation->id]);
    $this->assertDatabaseMissing('application_approvals', ['id' => $this->approval->id]);
    $this->assertDatabaseHas('telegram_identity_bindings', ['id' => $this->binding->id]);
});
