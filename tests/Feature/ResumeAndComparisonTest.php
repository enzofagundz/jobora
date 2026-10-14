<?php

use App\Enums\ProfessionalCompetencySource;
use App\Enums\ResumeLanguage;
use App\Enums\StrategicRole;
use App\Models\JobPosting;
use App\Models\ProfessionalCompetency;
use App\Models\Resume;
use App\Models\User;
use App\Services\MarketComparisonService;
use App\Services\ResumeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function confirmedWithItems(array $items, ?User $owner = null): JobPosting
{
    $user = $owner ?? User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();

    foreach ($items as $name) {
        $posting->items()->create(['category' => 'skill', 'name' => $name, 'source' => 'ai']);
    }

    return $posting;
}

it('salva o currículo em linha única e substitui a versão anterior', function () {
    $user = User::factory()->create();
    $service = app(ResumeService::class);

    $service->save($user, 'Experiência com Python e Django.', ResumeLanguage::PtBr);
    $service->save($user, 'Experiência com React e TypeScript.', ResumeLanguage::PtBr);

    expect(Resume::query()->where('user_id', $user->id)->where('language', ResumeLanguage::PtBr->value)->count())->toBe(1)
        ->and($service->content($user, ResumeLanguage::PtBr))->toBe('Experiência com React e TypeScript.');
});

it('reflete a atualização do currículo na comparação', function () {
    $user = User::factory()->create();
    confirmedWithItems(['Python'], $user);
    confirmedWithItems(['React'], $user);

    $service = app(ResumeService::class);
    $service->save($user, 'Currículo sem nenhuma tecnologia citada.', ResumeLanguage::PtBr);

    expect(collect(app(MarketComparisonService::class)->table($user))->firstWhere('name', 'Python')['present'])->toBeFalse();

    $service->save($user, 'Currículo com Python.', ResumeLanguage::PtBr);
    $rows = app(MarketComparisonService::class)->table($user);

    expect(collect($rows)->firstWhere('name', 'Python')['present'])->toBeTrue()
        ->and(collect($rows)->firstWhere('name', 'React')['present'])->toBeFalse();
});

it('cenário do PRD: Python presente no currículo, React ausente, frequências corretas', function () {
    $user = User::factory()->create();
    confirmedWithItems(['Python', 'React'], $user);
    confirmedWithItems(['Python'], $user);

    app(ResumeService::class)->save($user, 'Currículo: desenvolvedor Python com foco em automação.', ResumeLanguage::PtBr);

    $table = collect(app(MarketComparisonService::class)->table($user));

    $python = $table->firstWhere('name', 'Python');
    $react = $table->firstWhere('name', 'React');

    expect($python['total'])->toBe(2)
        ->and($python['percent'])->toBe(100.0)
        ->and($python['present'])->toBeTrue()
        ->and($python['is_gap'])->toBeFalse()
        ->and($react['total'])->toBe(1)
        ->and($react['percent'])->toBe(50.0)
        ->and($react['present'])->toBeFalse()
        ->and($react['is_gap'])->toBeTrue();
});

it('lacunas listam apenas itens frequentes e ausentes do currículo', function () {
    $user = User::factory()->create();
    confirmedWithItems(['Python', 'React'], $user);
    confirmedWithItems(['React'], $user);

    app(ResumeService::class)->save($user, 'Currículo com Python.', ResumeLanguage::PtBr);

    $gaps = collect(app(MarketComparisonService::class)->gaps($user));

    expect($gaps->pluck('name')->all())->toBe(['React'])
        ->and($gaps->first()['total'])->toBe(2);
});

it('retorna tabela vazia sem vagas confirmadas', function () {
    $user = User::factory()->create();
    app(ResumeService::class)->save($user, 'Currículo qualquer.', ResumeLanguage::PtBr);

    expect(app(MarketComparisonService::class)->table($user))->toBe([]);
});

it('itens com nome cru refletem o currículo salvo', function () {
    $user = User::factory()->create();
    confirmedWithItems(['github'], $user);
    confirmedWithItems(['clean code'], $user);

    app(ResumeService::class)->save($user, 'Currículo com GitHub e Clean Code.', ResumeLanguage::PtBr);

    $table = collect(app(MarketComparisonService::class)->table($user));

    expect($table->firstWhere('name', 'github')['present'])->toBeTrue()
        ->and($table->firstWhere('name', 'github')['is_gap'])->toBeFalse()
        ->and($table->firstWhere('name', 'clean code')['present'])->toBeTrue()
        ->and($table->firstWhere('name', 'clean code')['is_gap'])->toBeFalse();
});

it('competência removida volta a ser lacuna', function () {
    $user = User::factory()->create();
    confirmedWithItems(['clean code'], $user);

    app(ResumeService::class)->save($user, 'Aplico Clean Code.', ResumeLanguage::PtBr);

    expect(collect(app(MarketComparisonService::class)->gaps($user))->pluck('name')->all())->not->toContain('clean code');

    app(ResumeService::class)->save($user, 'Currículo sem menções.', ResumeLanguage::PtBr);

    expect(collect(app(MarketComparisonService::class)->gaps($user))->pluck('name')->all())->toContain('clean code');
});

it('linha de mercado carrega papel e origem quando classificada e permanece lacuna quando ausente', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    confirmedWithItems(['Python'], $user);
    app(ResumeService::class)->save($user, 'Currículo sem tecnologias citadas.', ResumeLanguage::PtBr);

    ProfessionalCompetency::factory()->create([
        'user_id' => $user->id,
        'concept' => 'Python',
        'strategic_role' => StrategicRole::Expansion,
        'source' => ProfessionalCompetencySource::Manual,
    ]);

    $row = collect(app(MarketComparisonService::class)->table($user))->firstWhere('name', 'Python');
    $gap = collect(app(MarketComparisonService::class)->gaps($user, 10))->firstWhere('name', 'Python');

    expect($row['strategic_role'])->toBe('expansion')
        ->and($row['strategic_source'])->toBe('manual')
        ->and($row['present'])->toBeFalse()
        ->and($row['is_gap'])->toBeTrue()
        ->and($gap['strategic_role'])->toBe('expansion');
});

it('linha de mercado sem classificação não recebe papel', function () {
    $user = User::factory()->create();
    confirmedWithItems(['Python'], $user);
    app(ResumeService::class)->save($user, 'Currículo com Python.', ResumeLanguage::PtBr);

    $row = collect(app(MarketComparisonService::class)->table($user))->firstWhere('name', 'Python');

    expect($row['strategic_role'])->toBeNull()
        ->and($row['strategic_source'])->toBeNull()
        ->and($row['present'])->toBeTrue()
        ->and($row['is_gap'])->toBeFalse();
});

it('papel da linha resolve aliases do item de mercado legado', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    confirmedWithItems(['postgres'], $user);
    app(ResumeService::class)->save($user, 'Currículo com PostgreSQL.', ResumeLanguage::PtBr);

    ProfessionalCompetency::factory()->create([
        'user_id' => $user->id,
        'concept' => 'PostgreSQL',
        'strategic_role' => StrategicRole::Core,
        'source' => ProfessionalCompetencySource::Manual,
    ]);

    $row = collect(app(MarketComparisonService::class)->table($user))->firstWhere('name', 'postgres');

    expect($row['strategic_role'])->toBe('core')
        ->and($row['strategic_source'])->toBe('manual');
});
