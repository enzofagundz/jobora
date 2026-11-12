<?php

use App\Enums\ResumeLanguage;
use App\Filament\Resources\JobPostings\Pages\ApplicationPage;
use App\Mcp\Support\AgentIntegrationContext;
use App\Models\ApplicationSubmissionAttempt;
use App\Models\JobPosting;
use App\Models\ProfessionalProfile;
use App\Models\Resume;
use App\Models\ResumeAdaptation;
use App\Models\User;
use App\Services\AiInputSnapshotService;
use App\Services\ApplicationPreparationService;
use App\Services\ApplicationSubmissionService;
use App\Services\TelegramIdentityService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\FakePdfBuilder;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake();
    Storage::fake('local');
    Pdf::swap(new class extends FakePdfBuilder
    {
        public function save(string $path): static
        {
            parent::save($path);
            file_put_contents($path, '%PDF-submission-ui '.$this->getHtml());

            return $this;
        }
    });
    $this->owner = User::factory()->create(['password' => 'correct-password']);
    $this->client = Helpers::client('Submission UI');
    $this->integration = Helpers::integration($this->owner, $this->client, ['approvals:read', 'approvals:write', 'applications:submit', 'applications:read']);
    $this->token = Helpers::accessToken($this->owner, $this->client);
    ProfessionalProfile::query()->create(['user_id' => $this->owner->id, 'version' => 1, 'draft' => [], 'confirmed_version' => 1, 'confirmed_snapshot' => ['competencies' => ['PHP']]]);
    $this->posting = JobPosting::factory()->for($this->owner, 'user')->create(['readiness' => 'ready', 'opportunity_version' => 1]);
    Resume::factory()->for($this->owner)->create(['language' => 'pt-BR', 'content' => 'ANA PHP']);
    $fingerprint = app(AiInputSnapshotService::class)->resumeAdaptation($this->owner, $this->posting, ResumeLanguage::PtBr)['fingerprint'];
    ResumeAdaptation::query()->create(['job_posting_id' => $this->posting->id, 'adapted_content' => 'ANA PHP', 'source_fingerprint' => $fingerprint]);
    $this->prepared = app(ApplicationPreparationService::class)->prepare($this->owner, [
        'job_posting_id' => $this->posting->id, 'expected_opportunity_version' => 1,
        'expected_profile_confirmed_version' => 1, 'language' => 'pt-BR',
        'destination' => ['type' => 'email', 'email' => 'jobs@example.test'],
    ]);
    $this->actingAs($this->owner, 'web');
});

function submissionPagePanel(object $test, User $user): void
{
    app()->forgetInstance(AgentIntegrationContext::class);
    app('auth')->forgetGuards();
    $test->actingAs($user, 'web');
}

function submissionPageMcp(object $test, string $tool, array $input): array
{
    app('auth')->forgetGuards();

    return Helpers::decodeToolResult($test->withToken($test->token)->postJson('/mcp', Helpers::toolCall($tool, $input))->assertOk()->getContent());
}

function submissionPageUnknown(object $test): array
{
    submissionPagePanel($test, $test->owner);
    $challenge = app(TelegramIdentityService::class)->startChallenge($test->owner, $test->integration->id);
    expect(submissionPageMcp($test, 'submit_telegram_link', [
        'challenge' => $challenge['challenge'], 'telegram_user_id' => '123456789012345',
        'chat_id' => '123456789012345', 'message_id' => '1', 'chat_type' => 'private',
    ])['ok'])->toBeTrue();
    submissionPagePanel($test, $test->owner);
    app(TelegramIdentityService::class)->confirmChallenge($test->owner, $challenge['challenge_id'], 'correct-password');
    $approval = submissionPageMcp($test, 'record_application_approval', [
        'preparation_id' => $test->prepared['preparation_id'], 'expected_fingerprint' => $test->prepared['fingerprint'],
        'telegram_user_id' => '123456789012345', 'chat_id' => '123456789012345', 'message_id' => '2',
        'approved_at' => now()->toISOString(), 'expires_at' => now()->addMinutes(30)->toISOString(),
    ])['data'];
    $reserved = submissionPageMcp($test, 'reserve_application_submission', [
        'approval_id' => $approval['id'], 'expected_fingerprint' => $test->prepared['fingerprint'], 'idempotency_key' => 'ui-submit-1',
    ])['data'];
    submissionPageMcp($test, 'consume_application_submission', [
        'attempt_id' => $reserved['attempt_id'], 'expected_revision' => 1, 'expected_fingerprint' => $test->prepared['fingerprint'], 'event_key' => 'ui-consume',
    ]);

    return submissionPageMcp($test, 'report_application_submission', [
        'attempt_id' => $reserved['attempt_id'], 'event_key' => 'ui-unknown', 'outcome' => 'unknown',
        'occurred_at' => now()->toISOString(), 'evidence' => ['kind' => 'ambiguous', 'details' => 'Sem confirmação do destino.'],
    ])['data'];
}

it('shows unknown attempt and lets owner reconcile after integration revocation', function () {
    $attempt = submissionPageUnknown($this);
    $this->integration->update(['revoked_at' => now()]);
    submissionPagePanel($this, $this->owner);

    Livewire::test(ApplicationPage::class, ['record' => $this->posting->id])
        ->assertSee('Resultado incerto')
        ->assertSee('Reconciliar tentativa')
        ->assertSet('submissionSummary.attempt_id', $attempt['attempt_id'])
        ->set('reconciliationData.outcome', 'succeeded')
        ->set('reconciliationData.evidence_kind', 'submission_receipt')
        ->set('reconciliationData.reference', 'receipt-42')
        ->call('reconcileSubmission')
        ->assertSet('submissionSummary.state', 'succeeded')
        ->assertSee('Envio confirmado');
});

it('denies another owner and ignores tampered attempt identifiers', function () {
    $attempt = submissionPageUnknown($this);
    $other = User::factory()->create();
    submissionPagePanel($this, $other);

    expect(fn () => Livewire::test(ApplicationPage::class, ['record' => $this->posting->id]))
        ->toThrow(ModelNotFoundException::class);

    submissionPagePanel($this, $this->owner);
    Livewire::test(ApplicationPage::class, ['record' => $this->posting->id])
        ->set('submissionSummary.attempt_id', '00000000-0000-4000-8000-000000000001')
        ->set('reconciliationData.outcome', 'succeeded')
        ->set('reconciliationData.evidence_kind', 'submission_receipt')
        ->set('reconciliationData.reference', 'forged')
        ->call('reconcileSubmission')
        ->assertSet('submissionError', 'Esta tentativa não pode ser reconciliada. Atualize o estado e tente novamente.');

    expect($attempt['state'])->toBe('unknown');
});

it('rejects stale revision and failed outcome without stopped executor or proof', function () {
    submissionPageUnknown($this);
    submissionPagePanel($this, $this->owner);
    Livewire::test(ApplicationPage::class, ['record' => $this->posting->id])
        ->set('reconciliationData.outcome', 'failed')
        ->assertSee('Confirmei que execução anterior foi encerrada.')
        ->set('reconciliationData.evidence_kind', 'not_submitted')
        ->set('reconciliationData.reference', 'checked')
        ->call('reconcileSubmission')
        ->assertSet('submissionError', 'O estado mudou ou as evidências não permitem resolver a tentativa. Atualize o estado.')
        ->assertSet('submissionSummary.state', 'unknown');
});

it('rejects reconciliation when public revision is stale', function () {
    $attempt = submissionPageUnknown($this);
    submissionPagePanel($this, $this->owner);
    Livewire::test(ApplicationPage::class, ['record' => $this->posting->id])
        ->set('submissionSummary.revision', $attempt['revision'] - 1)
        ->set('reconciliationData.outcome', 'succeeded')
        ->set('reconciliationData.evidence_kind', 'submission_receipt')
        ->set('reconciliationData.reference', 'receipt')
        ->call('reconcileSubmission')
        ->assertSet('submissionError', 'O estado mudou ou as evidências não permitem resolver a tentativa. Atualize o estado.')
        ->assertSet('submissionSummary.state', 'unknown');
});

it('keeps unknown blocked and reuses reconciliation event key on retry', function () {
    $attempt = submissionPageUnknown($this);
    submissionPagePanel($this, $this->owner);
    $page = Livewire::test(ApplicationPage::class, ['record' => $this->posting->id])
        ->set('reconciliationData.outcome', 'unknown')
        ->set('reconciliationData.evidence_kind', 'ambiguous')
        ->set('reconciliationData.details', 'Ainda sem resposta.')
        ->call('reconcileSubmission')
        ->assertSet('submissionSummary.state', 'unknown')
        ->assertSet('submissionError', null);
    $eventKey = $page->get('reconciliationEventKey');
    $page->call('refreshSubmissionStatus')
        ->assertSet('reconciliationEventKey', $eventKey)
        ->call('reconcileSubmission')
        ->assertSet('submissionSummary.state', 'unknown')
        ->assertSet('submissionError', null)
        ->assertSet('reconciliationEventKey', $eventKey);

    $page->set('reconciliationData.outcome', 'succeeded')
        ->set('reconciliationData.evidence_kind', 'submission_receipt')
        ->set('reconciliationData.reference', 'receipt-43')
        ->call('reconcileSubmission')
        ->assertSet('submissionSummary.state', 'succeeded')
        ->assertSet('submissionError', null)
        ->assertNotSet('reconciliationEventKey', $eventKey);

    expect($attempt['blocked'])->toBeTrue()
        ->and(ApplicationSubmissionAttempt::query()->findOrFail($attempt['attempt_id'])->events()->where('event_key', $eventKey)->count())->toBe(1)
        ->and(ApplicationSubmissionAttempt::query()->findOrFail($attempt['attempt_id'])->state->value)->toBe('succeeded');
});

it('recovers reconciliation after concurrent revision changes without editing the form', function () {
    $attempt = submissionPageUnknown($this);
    submissionPagePanel($this, $this->owner);
    $page = Livewire::test(ApplicationPage::class, ['record' => $this->posting->id])
        ->set('reconciliationData.outcome', 'succeeded')
        ->set('reconciliationData.evidence_kind', 'submission_receipt')
        ->set('reconciliationData.reference', 'receipt-after-conflict');

    app(ApplicationSubmissionService::class)->reconcileForOwner($this->owner, [
        'attempt_id' => $attempt['attempt_id'], 'expected_revision' => $attempt['revision'],
        'event_key' => 'another-panel', 'outcome' => 'unknown',
        'evidence' => ['kind' => 'ambiguous', 'details' => 'Ainda aguardando.'],
    ]);

    $page->call('reconcileSubmission')
        ->assertSet('reconciliationRequest', null)
        ->assertSet('submissionError', 'O estado mudou ou as evidências não permitem resolver a tentativa. Atualize o estado.');
    $page->call('refreshSubmissionStatus')
        ->assertSet('submissionSummary.revision', $attempt['revision'] + 1)
        ->call('reconcileSubmission')
        ->assertSet('submissionError', null)
        ->assertSet('submissionSummary.state', 'succeeded');
});
