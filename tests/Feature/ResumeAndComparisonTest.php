<?php

use App\Enums\ProfessionalCompetencySource;
use App\Enums\StrategicRole;
use App\Models\JobPosting;
use App\Models\ProfessionalCompetency;
use App\Models\Resume;
use App\Models\User;
use App\Services\MarketComparisonService;
use App\Services\ResumeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function confirmedWithItems(array $items): JobPosting
{
    $posting = JobPosting::factory()->confirmed()->create();

    foreach ($items as $name) {
        $posting->items()->create(['category' => 'skill', 'name' => $name, 'source' => 'ai']);
    }

    return $posting;
}

it('salva o currículo em linha única e substitui a versão anterior', function () {
    $service = app(ResumeService::class);

    $service->save('Experiência com Python e Django.');
    $service->save('Experiência com React e TypeScript.');

    expect(Resume::query()->count())->toBe(1)
        ->and($service->content())->toBe('Experiência com React e TypeScript.');
});

it('reflete a atualização do currículo na comparação', function () {
    confirmedWithItems(['Python']);
    confirmedWithItems(['React']);

    $service = app(ResumeService::class);
    $service->save('Currículo sem nenhuma tecnologia citada.');

    expect(collect(app(MarketComparisonService::class)->table())->firstWhere('name', 'Python')['present'])->toBeFalse();

    $service->save('Currículo com Python.');
    $rows = app(MarketComparisonService::class)->table();

    expect(collect($rows)->firstWhere('name', 'Python')['present'])->toBeTrue()
        ->and(collect($rows)->firstWhere('name', 'React')['present'])->toBeFalse();
});

it('cenário do PRD: Python presente no currículo, React ausente, frequências corretas', function () {
    confirmedWithItems(['Python', 'React']);
    confirmedWithItems(['Python']);

    app(ResumeService::class)->save('Currículo: desenvolvedor Python com foco em automação.');

    $table = collect(app(MarketComparisonService::class)->table());

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
    confirmedWithItems(['Python', 'React']);
    confirmedWithItems(['React']);

    app(ResumeService::class)->save('Currículo com Python.');

    $gaps = collect(app(MarketComparisonService::class)->gaps());

    expect($gaps->pluck('name')->all())->toBe(['React'])
        ->and($gaps->first()['total'])->toBe(2);
});

it('retorna tabela vazia sem vagas confirmadas', function () {
    app(ResumeService::class)->save('Currículo qualquer.');

    expect(app(MarketComparisonService::class)->table())->toBe([]);
});

it('itens com nome cru refletem o currículo salvo', function () {
    confirmedWithItems(['github']);
    confirmedWithItems(['clean code']);

    app(ResumeService::class)->save('Currículo com GitHub e Clean Code.');

    $table = collect(app(MarketComparisonService::class)->table());

    expect($table->firstWhere('name', 'github')['present'])->toBeTrue()
        ->and($table->firstWhere('name', 'github')['is_gap'])->toBeFalse()
        ->and($table->firstWhere('name', 'clean code')['present'])->toBeTrue()
        ->and($table->firstWhere('name', 'clean code')['is_gap'])->toBeFalse();
});

it('competência removida volta a ser lacuna', function () {
    confirmedWithItems(['clean code']);

    app(ResumeService::class)->save('Aplico Clean Code.');

    expect(collect(app(MarketComparisonService::class)->gaps())->pluck('name')->all())->not->toContain('clean code');

    app(ResumeService::class)->save('Currículo sem menções.');

    expect(collect(app(MarketComparisonService::class)->gaps())->pluck('name')->all())->toContain('clean code');
});

it('linha de mercado carrega papel e origem quando classificada e permanece lacuna quando ausente', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    confirmedWithItems(['Python']);
    app(ResumeService::class)->save('Currículo sem tecnologias citadas.');

    ProfessionalCompetency::factory()->create([
        'user_id' => $user->id,
        'concept' => 'Python',
        'strategic_role' => StrategicRole::Expansion,
        'source' => ProfessionalCompetencySource::Manual,
    ]);

    $row = collect(app(MarketComparisonService::class)->table())->firstWhere('name', 'Python');
    $gap = collect(app(MarketComparisonService::class)->gaps(10))->firstWhere('name', 'Python');

    expect($row['strategic_role'])->toBe('expansion')
        ->and($row['strategic_source'])->toBe('manual')
        ->and($row['present'])->toBeFalse()
        ->and($row['is_gap'])->toBeTrue()
        ->and($gap['strategic_role'])->toBe('expansion');
});

it('linha de mercado sem classificação não recebe papel', function () {
    confirmedWithItems(['Python']);
    app(ResumeService::class)->save('Currículo com Python.');

    $row = collect(app(MarketComparisonService::class)->table())->firstWhere('name', 'Python');

    expect($row['strategic_role'])->toBeNull()
        ->and($row['strategic_source'])->toBeNull()
        ->and($row['present'])->toBeTrue()
        ->and($row['is_gap'])->toBeFalse();
});

it('papel da linha resolve aliases do item de mercado legado', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    confirmedWithItems(['postgres']);
    app(ResumeService::class)->save('Currículo com PostgreSQL.');

    ProfessionalCompetency::factory()->create([
        'user_id' => $user->id,
        'concept' => 'PostgreSQL',
        'strategic_role' => StrategicRole::Core,
        'source' => ProfessionalCompetencySource::Manual,
    ]);

    $row = collect(app(MarketComparisonService::class)->table())->firstWhere('name', 'postgres');

    expect($row['strategic_role'])->toBe('core')
        ->and($row['strategic_source'])->toBe('manual');
});
