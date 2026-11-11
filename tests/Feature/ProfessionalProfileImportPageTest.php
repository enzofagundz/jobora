<?php

use App\Enums\ResumeLanguage;
use App\Filament\Pages\ProfessionalProfilePage;
use App\Models\AiOperation;
use App\Models\ProfileImportProposal;
use App\Models\Resume;
use App\Models\User;
use App\Services\ProfessionalProfileService;
use App\Services\ProfileImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['queue.default' => 'database', 'ai.providers.opencode_go.key' => 'profile-import-ui-test-key']);
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->profiles = app(ProfessionalProfileService::class);
    $this->profiles->saveDraft($this->owner, [
        'experiences' => [], 'education' => [], 'competencies' => ['Existing'], 'preferences' => ['Remote'], 'restrictions' => [], 'compensation_expectation' => null,
    ], 0);
    $this->base = $this->profiles->confirm($this->owner, 1, ['source' => 'filament']);
    Resume::factory()->for($this->owner)->create(['language' => 'pt-BR', 'content' => "Acme PHP\nCollege Z"]);
    Resume::factory()->for($this->owner)->create(['language' => 'en', 'content' => "Acme PHP\nCollege Z"]);
});

function profileImportOutputForPage(): array
{
    return [
        'candidate' => [
            'experiences' => [['company' => 'Acme', 'role' => null, 'period' => null, 'description' => null]],
            'education' => [['institution' => 'College Z', 'qualification' => null, 'period' => null]],
            'competencies' => ['PHP'],
        ],
        'evidence' => [
            ['path' => 'experiences.0.company', 'block_id' => 'block-1', 'quote' => 'Acme PHP'],
            ['path' => 'competencies.0', 'block_id' => 'block-1', 'quote' => 'Acme PHP'],
            ['path' => 'education.0.institution', 'block_id' => 'block-2', 'quote' => 'College Z'],
        ],
    ];
}

function processProfileImportForPage(): void
{
    Http::fake(['*' => Http::response([
        'choices' => [['message' => ['content' => json_encode(profileImportOutputForPage())], 'finish_reason' => 'stop']],
        'model' => 'deepseek-v4.1-flash',
    ])]);
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 3]);
}

function pendingProfileImportForPage(): ProfileImportProposal
{
    $operation = AiOperation::query()->where('user_id', auth()->id())->where('operation_type', 'profile_import')->sole();
    processProfileImportForPage();

    return ProfileImportProposal::query()->where('user_id', auth()->id())->where('ai_operation_id', $operation->id)->sole();
}

it('requests extraction, refreshes proposal, and confirms reviewed facts', function () {
    $page = Livewire::test(ProfessionalProfilePage::class)
        ->set('importLanguage', 'pt-BR')
        ->call('requestProfileImport')
        ->assertSet('importOperation.status', 'queued');

    expect($this->profiles->get($this->owner))->toEqual($this->base);

    processProfileImportForPage();
    $page->call('refreshProfileImport')
        ->assertSet('importProposal.status', 'pending')
        ->assertSee('Acme PHP')
        ->assertSee('College Z');

    $page->set('data.competencies.0.value', 'Local only')
        ->call('requestImportConfirmation')
        ->assertSet('data.competencies.0.value', 'Local only')
        ->assertNotified('Salve o rascunho antes de confirmar');

    $page = Livewire::test(ProfessionalProfilePage::class)
        ->call('requestImportConfirmation')
        ->assertSet('showImportConfirmation', true)
        ->call('confirmProfileImport')
        ->assertSet('importProposal.status', 'confirmed');

    $proposalId = $page->get('importProposal.id');
    $replay = app(ProfileImportService::class)->confirm($this->owner, $proposalId, 2, ['source' => 'filament']);
    expect($this->profiles->get($this->owner)['confirmed_snapshot']['competencies'])->toBe(['PHP'])
        ->and($this->profiles->get($this->owner)['confirmed_version'])->toBe(4)
        ->and($replay['result_version'])->toBe(4);
});

it('rejects stale proposal and keeps edits made while import is pending', function () {
    $page = Livewire::test(ProfessionalProfilePage::class)
        ->call('requestProfileImport');
    $proposal = pendingProfileImportForPage();
    $page->call('refreshProfileImport')
        ->set('data.competencies.0.value', 'Unsaved skill');

    $this->profiles->savePreferences($this->owner, ['Hybrid'], [], 2);
    $page->call('refreshProfileImport')
        ->assertSet('importProposal.stale', true)
        ->assertSet('data.competencies.0.value', 'Unsaved skill')
        ->call('confirmProfileImport')
        ->assertNotified('Esta proposta ficou desatualizada');

    $page->call('rejectProfileImport')
        ->assertSet('importProposal.status', 'rejected');
    expect(app(ProfileImportService::class)->get($this->owner, (string) $proposal->id)['status'])->toBe('rejected');
});

it('does not request extraction from unsaved profile edits', function () {
    Livewire::test(ProfessionalProfilePage::class)
        ->set('data.competencies.0.value', 'Uncommitted')
        ->call('requestProfileImport')
        ->assertNotified('Salve o rascunho antes de importar')
        ->assertSet('data.competencies.0.value', 'Uncommitted')
        ->assertSet('importOperation', null);

    expect(AiOperation::query()->where('user_id', $this->owner->id)->where('operation_type', 'profile_import')->exists())->toBeFalse();
});

it('does not expose another user operation or proposal when client submits foreign ids', function () {
    Livewire::test(ProfessionalProfilePage::class)->call('requestProfileImport');
    $proposal = pendingProfileImportForPage();
    $other = User::factory()->create();
    $this->actingAs($other);

    Livewire::test(ProfessionalProfilePage::class)
        ->set('importOperationId', $proposal->ai_operation_id)
        ->set('importProposalId', (string) $proposal->id)
        ->call('refreshProfileImport')
        ->assertSet('importOperation', null)
        ->assertSet('importProposal', null)
        ->assertDontSee('Acme PHP')
        ->call('confirmProfileImport')
        ->assertSet('importProposal', null);
});

it('recovers the latest owned import after page reload and supports English source selection', function () {
    Livewire::test(ProfessionalProfilePage::class)
        ->set('importLanguage', ResumeLanguage::En->value)
        ->call('requestProfileImport');
    processProfileImportForPage();

    Livewire::test(ProfessionalProfilePage::class)
        ->assertSet('importOperation.status', 'completed')
        ->assertSet('importProposal.source.language', 'en');
});

it('offers retry for a failed extraction and reuses owned operation', function () {
    $page = Livewire::test(ProfessionalProfilePage::class)->call('requestProfileImport');
    Http::fake(['*' => Http::response([
        'choices' => [['message' => ['content' => 'not valid json'], 'finish_reason' => 'stop']],
    ])]);
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 3]);

    $page->call('refreshProfileImport')
        ->assertSet('importOperation.status', 'failed')
        ->assertSet('importOperation.retry_allowed', true);

    Http::fake(['*' => Http::response([
        'choices' => [['message' => ['content' => json_encode(profileImportOutputForPage())], 'finish_reason' => 'stop']],
        'model' => 'deepseek-v4.1-flash',
    ])]);
    $operationId = $page->get('importOperation.id');
    $page->call('retryProfileImport')
        ->assertSet('importOperation.status', 'queued')
        ->assertSet('importOperation.id', $operationId);
});

it('hides retry when profile version changed after failed extraction', function () {
    $page = Livewire::test(ProfessionalProfilePage::class)->call('requestProfileImport');
    Http::fake(['*' => Http::response([
        'choices' => [['message' => ['content' => 'not valid json'], 'finish_reason' => 'stop']],
    ])]);
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 3]);

    $this->profiles->saveDraft($this->owner, [
        'experiences' => [], 'education' => [], 'competencies' => ['Changed'], 'preferences' => ['Remote'], 'restrictions' => [], 'compensation_expectation' => null,
    ], 2);

    $page->call('refreshProfileImport')
        ->assertSet('importOperation.retry_allowed', false)
        ->assertDontSee('Tentar novamente');
});
