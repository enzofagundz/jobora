<?php

use App\Models\JobPosting;
use App\Models\Resume;
use App\Services\MarketComparisonService;
use App\Services\ResumeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function confirmedWithItems(array $items): JobPosting
{
    $posting = JobPosting::factory()->confirmed()->create();

    foreach ($items as $name) {
        $posting->items()->create(['category' => 'language', 'name' => $name, 'source' => 'ai']);
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
