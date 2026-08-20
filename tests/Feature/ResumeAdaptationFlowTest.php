<?php

use App\Agents\ResumeAdaptationAgent;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\ResumeAdaptation;
use App\Services\ResumeAdaptationService;
use App\Services\ResumeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function adaptationPosting(): JobPosting
{
    $posting = JobPosting::factory()->confirmed()->create([
        'description' => 'Vaga para desenvolvedor Python com React. Requisitos: Docker e AWS.',
    ]);
    $posting->items()->createMany([
        ['category' => 'skill', 'name' => 'Python', 'source' => 'ai'],
        ['category' => 'skill', 'name' => 'React', 'source' => 'ai'],
    ]);

    return $posting;
}

function adaptationResume(): void
{
    app(ResumeService::class)->save('Currículo com Python e Laravel.');
}

function validAdaptationResponse(): array
{
    return [
        'adapted_resume' => "Currículo adaptado.\nDestaque para Python e Laravel.",
        'changes' => ['Resumo reescrito com a terminologia da vaga'],
        'covered_keywords' => ['Python'],
        'missing_requirements' => ['React'],
        'alerts' => [],
    ];
}

it('generates and persists the last adaptation per posting (UPSERT)', function () {
    ResumeAdaptationAgent::fake([validAdaptationResponse()]);

    $posting = adaptationPosting();
    adaptationResume();

    $first = app(ResumeAdaptationService::class)->adapt($posting);

    expect(ResumeAdaptation::count())->toBe(1)
        ->and($first->job_posting_id)->toBe($posting->id)
        ->and($first->adapted_content)->toContain('Python e Laravel')
        ->and($first->changes)->toBe(['Resumo reescrito com a terminologia da vaga'])
        ->and($first->covered_keywords)->toBe(['Python'])
        ->and($first->missing_requirements)->toBe(['React'])
        ->and($first->alerts)->toBe([]);

    // Regenerar substitui a linha — sem histórico.
    ResumeAdaptationAgent::fake([[
        'adapted_resume' => 'Nova versão da adaptação.',
        'changes' => [],
        'covered_keywords' => ['Python'],
        'missing_requirements' => ['React'],
        'alerts' => [],
    ]]);

    $second = app(ResumeAdaptationService::class)->adapt($posting->fresh());

    expect(ResumeAdaptation::count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->adapted_content)->toBe('Nova versão da adaptação.');
});

it('never alters the original resume', function () {
    ResumeAdaptationAgent::fake([validAdaptationResponse()]);

    $posting = adaptationPosting();
    adaptationResume();

    app(ResumeAdaptationService::class)->adapt($posting);

    expect(Resume::count())->toBe(1)
        ->and(app(ResumeService::class)->content())->toBe('Currículo com Python e Laravel.');
});

it('rejects an adapted resume with an invented concept', function () {
    ResumeAdaptationAgent::fake([[
        'adapted_resume' => 'Currículo com Kubernetes, que não está no original.',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]]);

    $posting = adaptationPosting();
    adaptationResume();

    app(ResumeAdaptationService::class)->adapt($posting);
})->throws(RuntimeException::class, 'Integridade');

it('rejects a covered keyword absent from the original resume', function () {
    ResumeAdaptationAgent::fake([[
        'adapted_resume' => 'Currículo adaptado com Python.',
        'changes' => [],
        'covered_keywords' => ['AWS'],
        'missing_requirements' => [],
        'alerts' => [],
    ]]);

    $posting = adaptationPosting();
    adaptationResume();

    app(ResumeAdaptationService::class)->adapt($posting);
})->throws(RuntimeException::class, 'covered_keywords');

it('rejects a missing requirement that is present in the original resume', function () {
    ResumeAdaptationAgent::fake([[
        'adapted_resume' => 'Currículo adaptado com Python.',
        'changes' => [],
        'covered_keywords' => ['Python'],
        'missing_requirements' => ['Python'],
        'alerts' => [],
    ]]);

    $posting = adaptationPosting();
    adaptationResume();

    app(ResumeAdaptationService::class)->adapt($posting);
})->throws(RuntimeException::class, 'missing_requirements');

it('throws when the posting is not confirmed', function () {
    ResumeAdaptationAgent::fake([validAdaptationResponse()]);

    $posting = JobPosting::factory()->create(['description' => str_repeat('Vaga Laravel. ', 20)]);
    adaptationResume();

    app(ResumeAdaptationService::class)->adapt($posting);
})->throws(LogicException::class, 'vaga confirmada');

it('throws when no resume is saved', function () {
    ResumeAdaptationAgent::fake([validAdaptationResponse()]);

    $posting = adaptationPosting();

    app(ResumeAdaptationService::class)->adapt($posting);
})->throws(LogicException::class, 'Cadastre o currículo');

it('fails cleanly and persists nothing on invalid JSON', function () {
    ResumeAdaptationAgent::fake(['isto não é um JSON válido']);

    $posting = adaptationPosting();
    adaptationResume();

    app(ResumeAdaptationService::class)->adapt($posting);
})->throws(RuntimeException::class, 'JSON válido');

it('fails cleanly and persists nothing on structurally invalid output', function () {
    ResumeAdaptationAgent::fake([['adapted_resume' => 123]]);

    $posting = adaptationPosting();
    adaptationResume();

    app(ResumeAdaptationService::class)->adapt($posting);
})->throws(RuntimeException::class, 'adapted_resume');

it('persists nothing when the agent throws', function () {
    ResumeAdaptationAgent::fake(fn () => throw new RuntimeException('Provider indisponível'));

    $posting = adaptationPosting();
    adaptationResume();

    app(ResumeAdaptationService::class)->adapt($posting);
})->throws(RuntimeException::class, 'Provider indisponível');
