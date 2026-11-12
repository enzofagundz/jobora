<?php

use App\Agents\ResumeAdaptationAgent;
use App\Enums\ResumeLanguage;
use App\Filament\Resources\JobPostings\Pages\ApplicationPage;
use App\Jobs\ProcessAiOperation;
use App\Models\AiOperation;
use App\Models\ApplicationPreparation;
use App\Models\DocumentArtifact;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\ResumeAdaptation;
use App\Models\User;
use App\Services\AiInputSnapshotService;
use App\Services\AiOperationService;
use App\Services\ApplicationPreparationService;
use App\Services\DocumentArtifactService;
use App\Services\ProfessionalProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\FakePdfBuilder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    Pdf::swap(new class extends FakePdfBuilder
    {
        public function save(string $path): static
        {
            parent::save($path);
            file_put_contents($path, '%PDF-ui-test '.$this->getHtml());

            return $this;
        }
    });
    Storage::fake('local');
    config(['app.url' => 'https://localhost', 'queue.default' => 'database']);
    $this->owner = User::factory()->create(['can_use_ats_optimization' => true]);
    $this->actingAs($this->owner);
    $this->posting = JobPosting::factory()->for($this->owner, 'user')->create([
        'readiness' => 'ready', 'opportunity_version' => 1, 'status' => 'pending',
        'role' => 'Pessoa desenvolvedora', 'company' => 'Empresa Exemplo', 'description' => 'Vaga de desenvolvimento PHP.',
    ]);
    $this->method = $this->posting->applicationMethods()->create([
        'type' => 'email', 'email' => 'talentos@example.test', 'source' => 'user', 'dedup_key' => str_repeat('a', 64),
    ]);
    app(ProfessionalProfileService::class)->saveDraft($this->owner, [
        'experiences' => [], 'education' => [], 'competencies' => ['PHP'], 'preferences' => [], 'restrictions' => [], 'compensation_expectation' => null,
    ], 0);
    app(ProfessionalProfileService::class)->confirm($this->owner, 1, ['source' => 'filament']);
    $this->resume = Resume::factory()->for($this->owner)->create(['language' => 'pt-BR', 'content' => 'Pessoa desenvolvedora PHP.']);
    $snapshot = app(AiInputSnapshotService::class)->resumeAdaptation($this->owner, $this->posting, ResumeLanguage::PtBr);
    $this->adaptation = ResumeAdaptation::query()->create([
        'job_posting_id' => $this->posting->id, 'adapted_content' => 'Perfil adaptado para PHP.',
        'source_fingerprint' => $snapshot['fingerprint'], 'changes' => [], 'covered_keywords' => [], 'missing_requirements' => [], 'alerts' => [],
    ]);
});

it('keeps historical preparation page readable for ineligible V2 opportunities', function (string $readiness) {
    $this->posting->update(['readiness' => $readiness]);

    Livewire::test(ApplicationPage::class, ['record' => $this->posting->id])
        ->assertSuccessful()
        ->assertSee('Preparar material da candidatura');
})->with(['needs_review', 'archived']);

it('accepts a ready structured opportunity even when legacy status is pending', function () {
    Livewire::test(ApplicationPage::class, ['record' => $this->posting->id])
        ->assertSuccessful()
        ->assertSee('Preparar material da candidatura');
});

it('prepares and reloads an immutable snapshot through real services', function () {
    $page = Livewire::test(ApplicationPage::class, ['record' => $this->posting->id])
        ->set('destinationApplicationMethodId', $this->method->id)
        ->set('preparationCoverLetterRequested', true)
        ->call('prepareApplicationMaterials')
        ->assertSet('preparationResult.state', 'awaiting_approval')
        ->assertSee('Material preparado')
        ->assertSee('Baixar currículo adaptado')
        ->assertSee('Baixar carta de apresentação');

    $result = $page->get('preparationResult');
    $snapshot = app(ApplicationPreparationService::class)->get($this->owner, (string) $result['preparation_id']);
    expect($snapshot['snapshot']['destination']['email'])->toBe('talentos@example.test')
        ->and($snapshot['snapshot']['profile']['competencies'])->toBe(['PHP'])
        ->and($snapshot['snapshot']['cover_letter'])->toContain('Candidatura:')
        ->and($snapshot['source_versions'])->toMatchArray(['profile_confirmed_version' => 2, 'opportunity_version' => 1])
        ->and($snapshot['fingerprint'])->toBeString()
        ->and($snapshot['artifacts'][0])->not->toHaveKey('path');
    $this->assertDatabaseCount('application_preparations', 1);
    expect(ApplicationPreparation::query()->sole()->snapshot['adaptation']['adapted_content'])->toBe('Perfil adaptado para PHP.');

    $reloadedPage = Livewire::test(ApplicationPage::class, ['record' => $this->posting->id])
        ->assertSet('preparationResult.preparation_id', $result['preparation_id'])
        ->assertSee('Material congelado');
    $this->resume->update(['content' => 'Currículo de origem atualizado.']);
    $reloadedPage->call('refreshAiOperation')
        ->assertSet('preparationResult.stale', true)
        ->assertSee('Fontes mudaram depois da preparação')
        ->assertSee('Perfil adaptado para PHP.');
});

it('keeps snapshot visible when artifact is revoked and download endpoint remains gone', function () {
    $page = Livewire::test(ApplicationPage::class, ['record' => $this->posting->id])
        ->set('destinationApplicationMethodId', $this->method->id)
        ->call('prepareApplicationMaterials')
        ->assertSet('preparationResult.state', 'awaiting_approval');
    $result = $page->get('preparationResult');
    $artifact = $result['artifacts'][0];
    $delivery = app(DocumentArtifactService::class)->deliver(
        $this->owner,
        DocumentArtifact::query()->findOrFail($artifact['artifact_id']),
        session: true,
    );

    app(DocumentArtifactService::class)->revoke($this->owner, $artifact['artifact_id']);

    Livewire::test(ApplicationPage::class, ['record' => $this->posting->id])
        ->assertSuccessful()
        ->assertSee('Material congelado')
        ->assertSee('Artefato indisponível')
        ->assertDontSee($delivery['download_url']);

    $this->get($delivery['download_url'])->assertGone();
});

it('blocks direct generation actions while opportunity is no longer eligible', function () {
    $this->posting->update(['readiness' => 'needs_review']);
    Queue::fake([ProcessAiOperation::class]);
    $page = Livewire::test(ApplicationPage::class, ['record' => $this->posting->id]);

    $page->call('adapt', ResumeLanguage::PtBr)
        ->assertNotified('Vaga indisponível para novas gerações')
        ->call('toggleAtsOptimization')
        ->assertNotified('Vaga indisponível para novas gerações')
        ->call('simulateAtsScore')
        ->assertNotified('Vaga indisponível para novas gerações')
        ->set('destinationApplicationMethodId', $this->method->id)
        ->call('prepareApplicationMaterials')
        ->assertNotified('Vaga indisponível para preparação');

    $this->assertDatabaseCount('ai_operations', 0);
    $this->assertDatabaseCount('application_preparations', 0);
});

it('renders missing resume as a pending preparation without claiming completion', function () {
    Resume::query()->where('user_id', $this->owner->id)->where('language', 'en')->delete();

    Livewire::test(ApplicationPage::class, ['record' => $this->posting->id])
        ->set('resumeLanguage', 'en')
        ->set('destinationApplicationMethodId', $this->method->id)
        ->call('prepareApplicationMaterials')
        ->assertSet('preparationResult.state', 'pending_information')
        ->assertSee('Currículo no idioma escolhido ainda não está disponível')
        ->assertSee('Pendências');

    $this->assertDatabaseCount('application_preparations', 0);
});

it('queues translation only when user opts in for a missing English resume', function () {
    Queue::fake([ProcessAiOperation::class]);

    Livewire::test(ApplicationPage::class, ['record' => $this->posting->id])
        ->set('resumeLanguage', 'en')
        ->set('preparationTranslateMissing', true)
        ->set('destinationApplicationMethodId', $this->method->id)
        ->call('prepareApplicationMaterials')
        ->assertSet('preparationResult.state', 'preparing')
        ->assertSet('preparationOperations.0.type', 'Tradução do currículo')
        ->assertSet('preparationOperations.0.status', 'queued');

    expect(AiOperation::query()->where('user_id', $this->owner->id)->where('operation_type', 'resume_translation')->count())->toBe(1);
});

it('keeps unsupported answers as visible pending information', function () {
    Livewire::test(ApplicationPage::class, ['record' => $this->posting->id])
        ->set('destinationApplicationMethodId', $this->method->id)
        ->set('preparationData.questions', [[
            'question' => 'Qual habilidade você usou?',
            'answer' => 'Liderei uma equipe por cinco anos.',
            'support' => [['source' => 'profile', 'path' => 'competencies.0', 'quote' => 'PHP']],
        ]])
        ->call('prepareApplicationMaterials')
        ->assertSet('preparationResult.state', 'pending_information')
        ->assertNotified('Faltam informações')
        ->assertSee('Resposta sem trecho literal correspondente')
        ->assertSee('Qual habilidade você usou?');
});

it('explains when the selected destination requires a cover letter', function () {
    Livewire::test(ApplicationPage::class, ['record' => $this->posting->id])
        ->call('preparationPendingLabel', ['code' => 'cover_letter_required'])
        ->assertReturned('Este destino exige carta de apresentação. Marque “Incluir carta de apresentação” e prepare novamente.');
});

it('tracks required adaptation operation then prepares after refresh', function () {
    $this->adaptation->delete();
    Queue::fake([ProcessAiOperation::class]);
    $page = Livewire::test(ApplicationPage::class, ['record' => $this->posting->id])
        ->set('destinationApplicationMethodId', $this->method->id)
        ->call('prepareApplicationMaterials')
        ->assertSet('preparationResult.state', 'preparing')
        ->assertSet('preparationOperations.0.status', 'queued');

    $operation = AiOperation::query()->where('user_id', $this->owner->id)->where('job_posting_id', $this->posting->id)->sole();
    ResumeAdaptationAgent::fake([[
        'adapted_resume' => 'Adaptação pronta para a vaga.', 'changes' => [], 'covered_keywords' => [], 'missing_requirements' => [], 'alerts' => [],
    ]]);
    (new ProcessAiOperation($operation->id, $operation->run_token))->handle(app(AiOperationService::class));

    $page->call('refreshAiOperation')
        ->assertSet('preparationOperations.0.status', 'completed')
        ->call('prepareApplicationMaterials')
        ->assertSet('preparationResult.state', 'awaiting_approval')
        ->assertSee('Adaptação pronta para a vaga.');
});

it('requires confirmed profile and rejects destination ids from another opportunity', function () {
    $this->owner->professionalProfile()->first()->update(['confirmed_snapshot' => null]);

    Livewire::test(ApplicationPage::class, ['record' => $this->posting->id])
        ->set('destinationApplicationMethodId', $this->method->id)
        ->call('prepareApplicationMaterials')
        ->assertNotified('Não foi possível preparar o material');
    $this->assertDatabaseCount('application_preparations', 0);

    $this->owner->professionalProfile()->first()->update(['confirmed_snapshot' => ['competencies' => ['PHP']]]);
    $otherPosting = JobPosting::factory()->create();
    $foreignMethod = $otherPosting->applicationMethods()->create([
        'type' => 'email', 'email' => 'foreign@example.test', 'source' => 'user', 'dedup_key' => str_repeat('b', 64),
    ]);

    Livewire::test(ApplicationPage::class, ['record' => $this->posting->id])
        ->set('destinationApplicationMethodId', $foreignMethod->id)
        ->call('prepareApplicationMaterials')
        ->assertNotified('Não foi possível preparar o material');
    $this->assertDatabaseCount('application_preparations', 0);
});

it('notifies owners with no profile or an unconfirmed draft before preparation', function (bool $missing) {
    if ($missing) {
        $this->owner->professionalProfile()->delete();
    } else {
        $this->owner->professionalProfile()->update(['confirmed_version' => 0, 'confirmed_snapshot' => null]);
    }

    Livewire::test(ApplicationPage::class, ['record' => $this->posting->id])
        ->set('destinationApplicationMethodId', $this->method->id)
        ->call('prepareApplicationMaterials')
        ->assertNotified('Não foi possível preparar o material')
        ->assertHasNoErrors();

    $this->assertDatabaseCount('application_preparations', 0);
    $this->assertDatabaseCount('ai_operations', 0);
})->with([true, false]);
