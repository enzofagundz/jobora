<?php

use App\Enums\ResumeLanguage;
use App\Filament\Resources\JobPostings\Pages\ApplicationPage;
use App\Mcp\Support\AgentIntegrationContext;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\ResumeAdaptation;
use App\Models\User;
use App\Services\AiInputSnapshotService;
use App\Services\ApplicationApprovalService;
use App\Services\ApplicationPreparationService;
use App\Services\ProfessionalProfileService;
use App\Services\TelegramIdentityService;
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
    config(['queue.default' => 'database']);
    Pdf::swap(new class extends FakePdfBuilder
    {
        public function save(string $path): static
        {
            parent::save($path);
            file_put_contents($path, '%PDF-approval-ui '.$this->getHtml());

            return $this;
        }
    });
    $this->owner = User::factory()->create(['password' => 'correct-password']);
    $this->client = Helpers::client('Hermes approval UI');
    $this->integration = Helpers::integration($this->owner, $this->client, ['approvals:read', 'approvals:write']);
    $this->token = Helpers::accessToken($this->owner, $this->client);
    $this->actingAs($this->owner, 'web');

    app(ProfessionalProfileService::class)->saveDraft($this->owner, [
        'experiences' => [], 'education' => [], 'competencies' => ['PHP'], 'preferences' => [], 'restrictions' => [], 'compensation_expectation' => null,
    ], 0);
    app(ProfessionalProfileService::class)->confirm($this->owner, 1, ['source' => 'filament']);
    $this->posting = JobPosting::factory()->for($this->owner, 'user')->create([
        'readiness' => 'ready', 'opportunity_version' => 1, 'role' => 'Desenvolvedora PHP', 'company' => 'Exemplo',
    ]);
    $this->resume = Resume::factory()->for($this->owner)->create(['language' => 'pt-BR', 'content' => 'Experiência com PHP.']);
    $fingerprint = app(AiInputSnapshotService::class)->resumeAdaptation($this->owner, $this->posting, ResumeLanguage::PtBr)['fingerprint'];
    ResumeAdaptation::query()->create([
        'job_posting_id' => $this->posting->id, 'adapted_content' => 'Currículo adaptado com PHP.', 'source_fingerprint' => $fingerprint,
        'changes' => [], 'covered_keywords' => ['PHP'], 'missing_requirements' => [], 'alerts' => [],
    ]);
    $this->prepared = app(ApplicationPreparationService::class)->prepare($this->owner, [
        'job_posting_id' => $this->posting->id, 'expected_opportunity_version' => 1,
        'expected_profile_confirmed_version' => 2, 'language' => 'pt-BR',
        'destination' => ['type' => 'email', 'email' => 'vagas@example.test'],
    ]);
});

function applicationApprovalUiPanel(object $test): void
{
    app()->forgetInstance(AgentIntegrationContext::class);
    app('auth')->forgetGuards();
    $test->actingAs($test->owner, 'web');
}

function applicationApprovalUiRemote(object $test, string $tool, array $input): array
{
    app('auth')->forgetGuards();

    return Helpers::decodeToolResult($test->withToken($test->token)->postJson('/mcp', Helpers::toolCall($tool, $input))->assertOk()->getContent());
}

function applicationApprovalUiRecord(object $test): array
{
    applicationApprovalUiPanel($test);
    $challenge = app(TelegramIdentityService::class)->startChallenge($test->owner, $test->integration->id);
    $submitted = applicationApprovalUiRemote($test, 'submit_telegram_link', [
        'challenge' => $challenge['challenge'], 'telegram_user_id' => '123456789012345',
        'chat_id' => '123456789012345', 'message_id' => '1', 'chat_type' => 'private',
    ]);
    expect($submitted['ok'] ?? false)->toBeTrue();
    applicationApprovalUiPanel($test);
    app(TelegramIdentityService::class)->confirmChallenge($test->owner, $challenge['challenge_id'], 'correct-password');

    $recorded = applicationApprovalUiRemote($test, 'record_application_approval', [
        'preparation_id' => $test->prepared['preparation_id'],
        'expected_fingerprint' => $test->prepared['fingerprint'],
        'telegram_user_id' => '123456789012345', 'chat_id' => '123456789012345', 'message_id' => '2',
        'approved_at' => now()->addSecond()->toISOString(), 'expires_at' => now()->addMinutes(30)->toISOString(),
    ]);
    applicationApprovalUiPanel($test);
    expect($recorded['ok'] ?? false)->toBeTrue();

    return $recorded['data'];
}

it('shows current approval against frozen material and only offers explicit revocation', function () {
    $approval = applicationApprovalUiRecord($this);
    $page = Livewire::test(ApplicationPage::class, ['record' => $this->posting->id])
        ->assertSee('Consentimento registrado pelo Hermes')
        ->assertSee('Aprovação registrada para este material')
        ->assertSee($this->prepared['fingerprint'])
        ->assertSee('ID Telegram')
        ->assertSee('Mensagem de consentimento')
        ->assertSee('Aprovação registrada não é candidatura enviada.')
        ->assertSee('Revogar aprovação registrada');
    $this->assertDatabaseHas('applications', ['id' => $this->posting->application()->value('id'), 'status' => 'planned']);

    $page->call('revokeApplicationApproval', $approval['id'])
        ->assertNotified('Aprovação revogada')
        ->assertSee('Aprovação revogada')
        ->assertDontSee('Aprovação registrada para este material');
    expect(app(ApplicationApprovalService::class)->forPreparation($this->owner, $this->prepared['preparation_id'])['approvals'][0]['status'])->toBe('revoked');
});

it('shows approval as invalid after frozen source changes and rejects manipulated ids', function () {
    $approval = applicationApprovalUiRecord($this);
    $page = Livewire::test(ApplicationPage::class, ['record' => $this->posting->id]);
    $this->resume->update(['content' => 'Currículo de origem foi alterado.']);

    $page->call('refreshAiOperation')
        ->assertSet('preparationResult.stale', true)
        ->assertSet('approvalData.approvals.0.status', 'invalid')
        ->assertSee('Aprovação inválida ou material alterado')
        ->assertSee('preparação mudou ou foi substituída')
        ->assertDontSee('Aprovação registrada para este material');

    $page->call('revokeApplicationApproval', '00000000-0000-4000-8000-000000000000')
        ->assertNotified('Aprovação não encontrada para este material');
    expect(app(ApplicationApprovalService::class)->forPreparation($this->owner, $this->prepared['preparation_id'])['approvals'][0]['status'])->toBe('invalid');
});

it('does not present prior approval as current after a new preparation replaces it', function () {
    applicationApprovalUiRecord($this);
    $replacement = app(ApplicationPreparationService::class)->prepare($this->owner, [
        'job_posting_id' => $this->posting->id, 'expected_opportunity_version' => 1,
        'expected_profile_confirmed_version' => 2, 'language' => 'pt-BR',
        'destination' => ['type' => 'email', 'email' => 'vagas@example.test'],
    ]);

    expect($replacement['preparation_id'])->not->toBe($this->prepared['preparation_id'])
        ->and(app(ApplicationApprovalService::class)->forPreparation($this->owner, $this->prepared['preparation_id'])['approvals'][0]['status'])->toBe('invalid');
    Livewire::test(ApplicationPage::class, ['record' => $this->posting->id])
        ->assertSet('preparationResult.preparation_id', $replacement['preparation_id'])
        ->assertDontSee('Aprovação registrada para este material')
        ->assertDontSee('Consentimento registrado pelo Hermes');
});

it('keeps owner approval history readable but stale after opportunity becomes ineligible', function (string $readiness) {
    applicationApprovalUiRecord($this);
    $this->posting->update(['readiness' => $readiness]);

    Livewire::test(ApplicationPage::class, ['record' => $this->posting->id])
        ->assertSuccessful()
        ->assertSee('Esta vaga não está pronta para novas gerações ou preparação')
        ->assertSee('Material congelado')
        ->assertSee('Aprovação inválida ou material alterado')
        ->assertDontSee('Aprovação registrada para este material');

    $this->assertDatabaseCount('application_preparations', 1);
})->with(['needs_review', 'archived']);
