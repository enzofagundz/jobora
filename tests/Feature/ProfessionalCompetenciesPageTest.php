<?php

use App\Agents\StrategicCompetencyAgent;
use App\Enums\ProfessionalCompetencySource;
use App\Enums\ResumeLanguage;
use App\Enums\StrategicRole;
use App\Filament\Pages\ProfessionalCompetenciesPage;
use App\Filament\Widgets\ComparisonSummaryWidget;
use App\Models\JobPosting;
use App\Models\ProfessionalCompetency;
use App\Models\User;
use App\Services\ResumeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

function competencyPageResume(string $text): void
{
    app(ResumeService::class)->save(auth()->user(), $text, ResumeLanguage::PtBr);
}

function competencyPageMarket(array $names): void
{
    $posting = JobPosting::factory()->for(auth()->user(), 'user')->confirmed()->create();

    foreach ($names as $name) {
        $posting->items()->create(['category' => 'skill', 'name' => $name, 'source' => 'ai']);
    }
}

it('renderiza a página de competências estratégicas', function () {
    Livewire::test(ProfessionalCompetenciesPage::class)
        ->assertSuccessful()
        ->assertSee('Competências estratégicas');
});

it('classifica com IA pela ação e mostra notificação', function () {
    StrategicCompetencyAgent::fake([[
        'competencies' => [
            ['concept' => 'PHP', 'strategic_role' => 'core'],
            ['concept' => 'Laravel', 'strategic_role' => 'expansion'],
        ],
    ]]);

    competencyPageResume('Currículo com PHP e Laravel.');
    competencyPageMarket(['PHP', 'Laravel', 'Docker']);

    Livewire::test(ProfessionalCompetenciesPage::class)
        ->callAction('classifyWithAi')
        ->assertNotified()
        ->assertSee('Laravel');

    expect(ProfessionalCompetency::where('user_id', auth()->id())->count())->toBe(2)
        ->and(ProfessionalCompetency::where('user_id', auth()->id())->where('concept', 'PHP')->first())
        ->source->toBe(ProfessionalCompetencySource::Ai);
});

it('desabilita a ação de IA sem currículo e nunca chama o provider', function () {
    StrategicCompetencyAgent::fake([]);

    Livewire::test(ProfessionalCompetenciesPage::class)
        ->assertActionDisabled('classifyWithAi');

    StrategicCompetencyAgent::assertNeverPrompted();
});

it('cria competência manual pela ação', function () {
    Livewire::test(ProfessionalCompetenciesPage::class)
        ->callAction('addManual', [
            'concept' => 'postgres',
            'strategic_role' => 'core',
        ])
        ->assertNotified()
        ->assertHasNoActionErrors();

    expect(ProfessionalCompetency::where('user_id', auth()->id())->count())->toBe(1)
        ->and(ProfessionalCompetency::first()->concept)->toBe('PostgreSQL')
        ->and(ProfessionalCompetency::first()->source)->toBe(ProfessionalCompetencySource::Manual);
});

it('valida os campos da ação manual', function () {
    Livewire::test(ProfessionalCompetenciesPage::class)
        ->callAction('addManual', [
            'concept' => '',
            'strategic_role' => null,
        ])
        ->assertHasActionErrors([
            'concept' => 'required',
            'strategic_role' => 'required',
        ])
        ->assertNotNotified();

    expect(ProfessionalCompetency::count())->toBe(0);
});

it('edita o papel de uma linha e converte IA em manual', function () {
    $competency = ProfessionalCompetency::factory()->create([
        'user_id' => auth()->id(),
        'concept' => 'PHP',
        'strategic_role' => StrategicRole::Core,
        'source' => ProfessionalCompetencySource::Ai,
    ]);

    Livewire::test(ProfessionalCompetenciesPage::class)
        ->callTableAction('editRole', (string) $competency->id, [
            'strategic_role' => 'differentiation',
        ])
        ->assertNotified()
        ->assertHasNoTableActionErrors();

    $competency->refresh();

    expect($competency->strategic_role)->toBe(StrategicRole::Differentiation)
        ->and($competency->source)->toBe(ProfessionalCompetencySource::Manual);
});

it('remove uma linha pela ação', function () {
    $competency = ProfessionalCompetency::factory()->create([
        'user_id' => auth()->id(),
        'concept' => 'PHP',
        'strategic_role' => StrategicRole::Core,
    ]);

    Livewire::test(ProfessionalCompetenciesPage::class)
        ->callTableAction('remove', (string) $competency->id)
        ->assertNotified();

    expect(ProfessionalCompetency::find($competency->id))->toBeNull();
});

it('não expõe linha de outro usuário na página', function () {
    $other = User::factory()->create();
    ProfessionalCompetency::factory()->create([
        'user_id' => $other->id,
        'concept' => 'PHP',
        'strategic_role' => StrategicRole::Core,
    ]);

    Livewire::test(ProfessionalCompetenciesPage::class)
        ->assertSee('Nenhuma competência classificada')
        ->assertDontSee('PHP');
});

it('deriva a situação Adquirida/Lacuna do currículo', function () {
    ProfessionalCompetency::factory()->create([
        'user_id' => auth()->id(),
        'concept' => 'PHP',
        'strategic_role' => StrategicRole::Core,
        'source' => ProfessionalCompetencySource::Manual,
    ]);

    competencyPageResume('Currículo com PHP.');

    Livewire::test(ProfessionalCompetenciesPage::class)
        ->assertSee('Adquirida')
        ->assertDontSee('Lacuna');

    competencyPageResume('Currículo sem tecnologias.');

    Livewire::test(ProfessionalCompetenciesPage::class)
        ->assertSee('Lacuna')
        ->assertDontSee('Adquirida');
});

it('exibe o papel estratégico no widget de lacunas', function () {
    ProfessionalCompetency::factory()->create([
        'user_id' => auth()->id(),
        'concept' => 'Python',
        'strategic_role' => StrategicRole::Differentiation,
        'source' => ProfessionalCompetencySource::Manual,
    ]);

    competencyPageResume('Currículo com foco em infraestrutura e redes.');
    competencyPageMarket(['Python']);

    Livewire::test(ComparisonSummaryWidget::class)
        ->assertSee('Diferenciação');
});
