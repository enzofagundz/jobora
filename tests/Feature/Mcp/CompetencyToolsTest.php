<?php

use App\Enums\ProfessionalCompetencySource;
use App\Enums\ResumeLanguage;
use App\Enums\StrategicRole;
use App\Jobs\ProcessAiOperation;
use App\Mcp\Resources\ProfessionalCompetenciesResource;
use App\Mcp\Servers\JoboraServer;
use App\Mcp\Tools\Competencies\ClassifyProfessionalCompetenciesTool;
use App\Mcp\Tools\Competencies\ListProfessionalCompetenciesTool;
use App\Mcp\Tools\Competencies\RemoveProfessionalCompetencyTool;
use App\Mcp\Tools\Competencies\SetProfessionalCompetencyTool;
use App\Models\JobPosting;
use App\Models\ProfessionalCompetency;
use App\Models\User;
use App\Services\ResumeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Mcp\McpTestHelpers;

uses(RefreshDatabase::class);

it('lista competências vazias quando não há', function () {
    McpTestHelpers::user();

    JoboraServer::tool(ListProfessionalCompetenciesTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.items', [])
            ->etc());
});

it('lista competências com papel estratégico e presença no currículo', function () {
    $user = McpTestHelpers::user();
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);

    ProfessionalCompetency::factory()->for($user, 'user')->create([
        'concept' => 'PHP',
        'strategic_role' => StrategicRole::Core,
        'source' => ProfessionalCompetencySource::Manual,
    ]);
    ProfessionalCompetency::factory()->for($user, 'user')->create([
        'concept' => 'Kubernetes',
        'strategic_role' => StrategicRole::Expansion,
        'source' => ProfessionalCompetencySource::Ai,
    ]);

    JoboraServer::tool(ListProfessionalCompetenciesTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.items.0.concept', 'Kubernetes')
            ->where('data.items.0.present', false)
            ->where('data.items.0.is_gap', true)
            ->where('data.items.1.concept', 'PHP')
            ->where('data.items.1.present', true)
            ->where('data.items.1.strategic_role', 'core')
            ->where('data.items.1.source', 'manual')
            ->etc());
});

it('define uma competência manual e ela aparece na lista', function () {
    $user = McpTestHelpers::user();
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);

    JoboraServer::tool(SetProfessionalCompetencyTool::class, [
        'concept' => 'php',
        'strategic_role' => 'core',
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.competency.concept', 'PHP')
            ->where('data.competency.strategic_role', 'core')
            ->where('data.competency.source', 'manual')
            ->etc());

    JoboraServer::tool(ListProfessionalCompetenciesTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.items.0.concept', 'PHP')
            ->where('data.items.0.present', true)
            ->etc());
});

it('recusa papel estratégico inválido', function () {
    McpTestHelpers::user();

    JoboraServer::tool(SetProfessionalCompetencyTool::class, [
        'concept' => 'PHP',
        'strategic_role' => 'invalido',
    ])->assertHasErrors(['validation']);
});

it('exige confirmação para remover uma competência', function () {
    $user = McpTestHelpers::user();
    $competency = ProfessionalCompetency::factory()->for($user, 'user')->create(['concept' => 'PHP']);

    JoboraServer::tool(RemoveProfessionalCompetencyTool::class, ['id' => $competency->id])
        ->assertHasErrors(['conflict']);
});

it('remove a competência com confirmação', function () {
    $user = McpTestHelpers::user();
    $competency = ProfessionalCompetency::factory()->for($user, 'user')->create(['concept' => 'PHP']);

    JoboraServer::tool(RemoveProfessionalCompetencyTool::class, ['id' => $competency->id, 'confirm' => true])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.deleted', true)
            ->etc());

    JoboraServer::tool(ListProfessionalCompetenciesTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.items', [])
            ->etc());
});

it('não remove competência de outro usuário', function () {
    McpTestHelpers::user();
    $competency = ProfessionalCompetency::factory()->for(User::factory(), 'user')->create();

    JoboraServer::tool(RemoveProfessionalCompetencyTool::class, ['id' => $competency->id, 'confirm' => true])
        ->assertHasErrors(['not_found']);
});

it('enfileira a classificação estratégica das competências', function () {
    $user = McpTestHelpers::user();
    Queue::fake();
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);

    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::item($posting, 'PHP');

    JoboraServer::tool(ClassifyProfessionalCompetenciesTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.status', 'queued')
            ->etc());

    Queue::assertPushed(ProcessAiOperation::class);
});

it('recusa classificar competências sem currículo cadastrado', function () {
    McpTestHelpers::user();
    Queue::fake();

    JoboraServer::tool(ClassifyProfessionalCompetenciesTool::class, [])
        ->assertHasErrors(['precondition']);
});

it('expõe as competências estratégicas como resource', function () {
    $user = McpTestHelpers::user();
    app(ResumeService::class)->save($user, 'Currículo com PHP.', ResumeLanguage::PtBr);
    ProfessionalCompetency::factory()->for($user, 'user')->create([
        'concept' => 'PHP',
        'strategic_role' => StrategicRole::Core,
    ]);

    JoboraServer::resource(ProfessionalCompetenciesResource::class)
        ->assertOk()
        ->assertSee(['PHP', 'core', 'present']);
});
