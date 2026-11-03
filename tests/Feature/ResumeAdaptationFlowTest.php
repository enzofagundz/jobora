<?php

use App\Agents\ResumeAdaptationAgent;
use App\Enums\ResumeLanguage;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\ResumeAdaptation;
use App\Models\User;
use App\Services\ResumeAdaptationService;
use App\Services\ResumeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function adaptationPosting(?User $user = null): JobPosting
{
    $user ??= User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Vaga para desenvolvedor Python com React. Requisitos: Docker e AWS.',
    ]);
    $posting->items()->createMany([
        ['category' => 'skill', 'name' => 'Python', 'source' => 'ai'],
        ['category' => 'skill', 'name' => 'React', 'source' => 'ai'],
    ]);

    return $posting;
}

function adaptationResume(User $user): void
{
    app(ResumeService::class)->save($user, 'Currículo com Python e Laravel.', ResumeLanguage::PtBr);
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

    $user = User::factory()->create();
    $posting = adaptationPosting($user);
    adaptationResume($user);

    $first = app(ResumeAdaptationService::class)->adapt($user, $posting);

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

    $second = app(ResumeAdaptationService::class)->adapt($user, $posting->fresh());

    expect(ResumeAdaptation::count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->adapted_content)->toBe('Nova versão da adaptação.');
});

it('invalidates a previous ATS score when an adaptation is regenerated', function () {
    ResumeAdaptationAgent::fake([
        validAdaptationResponse(),
        [
            'adapted_resume' => 'Nova versão da adaptação.',
            'changes' => [],
            'covered_keywords' => ['Python'],
            'missing_requirements' => ['React'],
            'alerts' => [],
        ],
    ]);

    $user = User::factory()->create();
    $posting = adaptationPosting($user);
    adaptationResume($user);

    $adaptation = app(ResumeAdaptationService::class)->adapt($user, $posting);
    $adaptation->update([
        'ats_score' => 8,
        'ats_rationale' => 'Nota anterior.',
        'ats_source_fingerprint' => str_repeat('a', 64),
    ]);

    app(ResumeAdaptationService::class)->adapt($user, $posting->fresh());

    expect($adaptation->refresh()->ats_score)->toBeNull()
        ->and($adaptation->ats_rationale)->toBeNull()
        ->and($adaptation->ats_source_fingerprint)->toBeNull();
});

it('never alters the original resume', function () {
    ResumeAdaptationAgent::fake([validAdaptationResponse()]);

    $user = User::factory()->create();
    $posting = adaptationPosting($user);
    adaptationResume($user);

    app(ResumeAdaptationService::class)->adapt($user, $posting);

    expect(Resume::query()->where('language', ResumeLanguage::PtBr->value)->count())->toBe(1)
        ->and(app(ResumeService::class)->content($user, ResumeLanguage::PtBr))->toBe('Currículo com Python e Laravel.');
});

it('rejects an adapted resume with an invented concept', function () {
    ResumeAdaptationAgent::fake([[
        'adapted_resume' => 'Currículo com Kubernetes, que não está no original.',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]]);

    $user = User::factory()->create();
    $posting = adaptationPosting($user);
    adaptationResume($user);

    app(ResumeAdaptationService::class)->adapt($user, $posting);
})->throws(RuntimeException::class, 'Integridade');

it('rejects a covered keyword absent from the original resume', function () {
    ResumeAdaptationAgent::fake([[
        'adapted_resume' => 'Currículo adaptado com Python.',
        'changes' => [],
        'covered_keywords' => ['AWS'],
        'missing_requirements' => [],
        'alerts' => [],
    ]]);

    $user = User::factory()->create();
    $posting = adaptationPosting($user);
    adaptationResume($user);

    app(ResumeAdaptationService::class)->adapt($user, $posting);
})->throws(RuntimeException::class, 'covered_keywords');

it('accepts a covered keyword whose canonical name contains a dot', function () {
    ResumeAdaptationAgent::fake([[
        'adapted_resume' => 'Currículo adaptado com Vue.js.',
        'changes' => [],
        'covered_keywords' => ['Vue.js'],
        'missing_requirements' => [],
        'alerts' => [],
    ]]);

    $user = User::factory()->create();
    $posting = adaptationPosting($user);
    app(ResumeService::class)->save($user, 'Currículo com Vue.js e Laravel.', ResumeLanguage::PtBr);

    $adaptation = app(ResumeAdaptationService::class)->adapt($user, $posting);

    expect($adaptation->covered_keywords)->toBe(['Vue.js']);
});

it('rejects a missing requirement that is present in the original resume', function () {
    ResumeAdaptationAgent::fake([[
        'adapted_resume' => 'Currículo adaptado com Python.',
        'changes' => [],
        'covered_keywords' => ['Python'],
        'missing_requirements' => ['Python'],
        'alerts' => [],
    ]]);

    $user = User::factory()->create();
    $posting = adaptationPosting($user);
    adaptationResume($user);

    app(ResumeAdaptationService::class)->adapt($user, $posting);
})->throws(RuntimeException::class, 'missing_requirements');

it('throws when the posting is not confirmed', function () {
    ResumeAdaptationAgent::fake([validAdaptationResponse()]);

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->create(['description' => str_repeat('Vaga Laravel. ', 20)]);
    adaptationResume($user);

    app(ResumeAdaptationService::class)->adapt($user, $posting);
})->throws(LogicException::class, 'vaga confirmada');

it('throws when no resume is saved', function () {
    ResumeAdaptationAgent::fake([validAdaptationResponse()]);

    $user = User::factory()->create();
    $posting = adaptationPosting($user);

    app(ResumeAdaptationService::class)->adapt($user, $posting);
})->throws(LogicException::class, 'Cadastre o currículo');

it('fails cleanly and persists nothing on invalid JSON', function () {
    ResumeAdaptationAgent::fake(['isto não é um JSON válido']);

    $user = User::factory()->create();
    $posting = adaptationPosting($user);
    adaptationResume($user);

    app(ResumeAdaptationService::class)->adapt($user, $posting);
})->throws(RuntimeException::class, 'JSON válido');

it('fails cleanly and persists nothing on structurally invalid output', function () {
    ResumeAdaptationAgent::fake([['adapted_resume' => 123]]);

    $user = User::factory()->create();
    $posting = adaptationPosting($user);
    adaptationResume($user);

    app(ResumeAdaptationService::class)->adapt($user, $posting);
})->throws(RuntimeException::class, 'adapted_resume');

it('persists nothing when the agent throws', function () {
    ResumeAdaptationAgent::fake(fn () => throw new RuntimeException('Provider indisponível'));

    $user = User::factory()->create();
    $posting = adaptationPosting($user);
    adaptationResume($user);

    app(ResumeAdaptationService::class)->adapt($user, $posting);
})->throws(RuntimeException::class, 'Provider indisponível');

it('mantém adequações pt-BR e EN coexistentes e regenera só a escolhida', function () {
    ResumeAdaptationAgent::fake([
        validAdaptationResponse(),
        [
            'adapted_resume' => 'Adapted resume in English.',
            'changes' => [],
            'covered_keywords' => ['Python'],
            'missing_requirements' => [],
            'alerts' => [],
        ],
        [
            'adapted_resume' => 'Currículo adaptado v2.',
            'changes' => [],
            'covered_keywords' => ['Python'],
            'missing_requirements' => [],
            'alerts' => [],
        ],
    ]);

    $user = User::factory()->create();
    $posting = adaptationPosting($user);
    adaptationResume($user);
    app(ResumeService::class)->save($user, 'Resume with Python and Laravel.', ResumeLanguage::En);

    $service = app(ResumeAdaptationService::class);

    $ptBr = $service->adapt($user, $posting, ResumeLanguage::PtBr);
    $en = $service->adapt($user, $posting->fresh(), ResumeLanguage::En);

    expect(ResumeAdaptation::count())->toBe(2)
        ->and($ptBr->language)->toBe(ResumeLanguage::PtBr)
        ->and($en->language)->toBe(ResumeLanguage::En)
        ->and($en->adapted_content)->toBe('Adapted resume in English.');

    $service->adapt($user, $posting->fresh(), ResumeLanguage::PtBr);

    expect(ResumeAdaptation::count())->toBe(2)
        ->and($ptBr->refresh()->adapted_content)->toBe('Currículo adaptado v2.')
        ->and($en->refresh()->adapted_content)->toBe('Adapted resume in English.');
});

it('instrui o agente a responder no idioma da adaptação', function () {
    $agent = app(ResumeAdaptationAgent::class);

    expect((string) $agent->instructions())->not->toContain('o idioma da adaptação é o inglês');

    $en = $agent->withLanguage(ResumeLanguage::En);

    expect((string) $en->instructions())->toContain('o idioma da adaptação é o inglês')
        ->and((string) $en->instructions())->toContain('adapted_resume');
});

it('consome o currículo do idioma pedido ao adequar', function () {
    $captured = null;
    ResumeAdaptationAgent::fake(function (string $prompt) use (&$captured): array {
        $captured = json_decode($prompt, true);

        return [
            'adapted_resume' => 'Adapted resume in English.',
            'changes' => [],
            'covered_keywords' => ['Python'],
            'missing_requirements' => [],
            'alerts' => [],
        ];
    });

    $user = User::factory()->create();
    $posting = adaptationPosting($user);
    adaptationResume($user);
    app(ResumeService::class)->save($user, 'Resume with Python and Laravel.', ResumeLanguage::En);

    app(ResumeAdaptationService::class)->adapt($user, $posting, ResumeLanguage::En);

    expect($captured['language'])->toBe('en')
        ->and($captured['resume'])->toBe('Resume with Python and Laravel.');
});

it('recusa adequar em EN sem currículo em inglês, com orientação', function () {
    ResumeAdaptationAgent::fake([]);

    $user = User::factory()->create();
    $posting = adaptationPosting($user);
    adaptationResume($user);

    app(ResumeAdaptationService::class)->adapt($user, $posting, ResumeLanguage::En);
})->throws(LogicException::class, 'currículo em inglês');
