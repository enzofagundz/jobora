<?php

use App\Agents\AtsScorerAgent;
use App\Agents\ResumeAdaptationAgent;
use App\Enums\ResumeLanguage;
use App\Filament\Pages\ManageAtsUsers;
use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Filament\Resources\JobPostings\Pages\ApplicationPage;
use App\Models\JobPosting;
use App\Models\ResumeAdaptation;
use App\Models\User;
use App\Services\AtsScorerService;
use App\Services\ResumeAdaptationService;
use App\Services\ResumePdfService;
use App\Services\ResumeService;
use App\Support\Staleness;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

uses(RefreshDatabase::class);

it('exposes is_admin and can_use_ats flags default false', function () {
    $user = User::factory()->create();

    expect($user->is_admin)->toBeFalse()
        ->and($user->can_use_ats_optimization)->toBeFalse();
});

it('persists ats optimization fields per adaptation default off', function () {
    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Vaga Laravel com Docker',
    ]);

    $adaptation = ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Curriculo teste',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    expect($adaptation->is_ats_optimized)->toBeFalse()
        ->and($adaptation->ats_prompt)->toBeNull();

    $adaptation->refresh();

    expect($adaptation->is_ats_optimized)->toBeFalse()
        ->and($adaptation->ats_prompt)->toBeNull();
});

it('authorizes admin and ats optimization gates', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $granted = User::factory()->create(['can_use_ats_optimization' => true]);
    $plain = User::factory()->create();
    $both = User::factory()->create(['is_admin' => true, 'can_use_ats_optimization' => true]);

    expect(Gate::forUser($admin)->allows('admin'))->toBeTrue()
        ->and(Gate::forUser($plain)->allows('admin'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('useAtsOptimization'))->toBeTrue()
        ->and(Gate::forUser($granted)->allows('useAtsOptimization'))->toBeTrue()
        ->and(Gate::forUser($both)->allows('useAtsOptimization'))->toBeTrue()
        ->and(Gate::forUser($plain)->allows('useAtsOptimization'))->toBeFalse();
});

it('allows authorized user to enable ats optimization per posting', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'role' => 'Dev PHP',
        'company' => 'Acme',
        'description' => 'Vaga PHP Laravel',
    ]);
    $posting->items()->createMany([
        ['category' => 'skill', 'name' => 'PHP', 'source' => 'ai'],
        ['category' => 'skill', 'name' => 'Laravel', 'source' => 'ai'],
    ]);

    app(ResumeService::class)->save($user, 'Experiência com PHP e Laravel', ResumeLanguage::PtBr);

    $adaptation = ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Curriculo adaptado com PHP',
        'changes' => [],
        'covered_keywords' => ['PHP'],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    $service = app(ResumeAdaptationService::class);
    $updated = $service->setAtsOptimization($user, $posting->fresh(), true);

    expect($updated->is_ats_optimized)->toBeTrue()
        ->and($updated->ats_prompt)->not->toBeNull()
        ->and($updated->ats_prompt)->toContain('PHP')
        ->and(mb_strlen($updated->ats_prompt))->toBeLessThanOrEqual(300);
});

it('prevents unauthorized user from toggling ats optimization', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $posting = JobPosting::factory()->for($owner, 'user')->confirmed()->create();
    $posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'ai']);
    app(ResumeService::class)->save($owner, 'PHP', ResumeLanguage::PtBr);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'teste',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    $service = app(ResumeAdaptationService::class);

    expect(fn () => $service->setAtsOptimization($other, $posting->fresh(), true))
        ->toThrow(AuthorizationException::class);

    $plainOwner = User::factory()->create();
    $posting2 = JobPosting::factory()->for($plainOwner, 'user')->confirmed()->create();
    $posting2->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'ai']);
    app(ResumeService::class)->save($plainOwner, 'PHP', ResumeLanguage::PtBr);
    ResumeAdaptation::create([
        'job_posting_id' => $posting2->id,
        'adapted_content' => 'teste',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    expect(fn () => $service->setAtsOptimization($plainOwner, $posting2->fresh(), true))
        ->toThrow(AuthorizationException::class);
});

it('toggles ats optimization off and clears prompt', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'role' => 'Dev',
        'company' => 'Co',
    ]);
    $posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'ai']);
    app(ResumeService::class)->save($user, 'PHP', ResumeLanguage::PtBr);

    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'teste',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    $service = app(ResumeAdaptationService::class);
    $service->setAtsOptimization($user, $posting->fresh(), true);
    $disabled = $service->setAtsOptimization($user, $posting->fresh(), false);

    expect($disabled->is_ats_optimized)->toBeFalse()
        ->and($disabled->ats_prompt)->toBeNull();
});

it('regenerates ats prompt when re-adapting with optimization active', function () {
    ResumeAdaptationAgent::fake([
        [
            'adapted_resume' => 'Curriculo com PHP',
            'changes' => [],
            'covered_keywords' => ['PHP'],
            'missing_requirements' => [],
            'alerts' => [],
        ],
        [
            'adapted_resume' => 'Curriculo com PHP e Laravel',
            'changes' => [],
            'covered_keywords' => ['PHP'],
            'missing_requirements' => [],
            'alerts' => [],
        ],
    ]);

    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'role' => 'Dev',
        'company' => 'Co',
    ]);
    $posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'ai']);
    app(ResumeService::class)->save($user, 'Experiencia com PHP e Laravel', ResumeLanguage::PtBr);

    $service = app(ResumeAdaptationService::class);
    $service->adapt($user, $posting->fresh());
    $service->setAtsOptimization($user, $posting->fresh(), true);

    $firstPrompt = ResumeAdaptation::where('job_posting_id', $posting->id)->first()->ats_prompt;

    // Add new item and re-adapt, should regenerate prompt to include new keyword if present in resume
    $posting->items()->create(['category' => 'skill', 'name' => 'Laravel', 'source' => 'ai']);

    $service->adapt($user, $posting->fresh());

    $adaptation = ResumeAdaptation::where('job_posting_id', $posting->id)->first();

    expect($adaptation->is_ats_optimized)->toBeTrue()
        ->and($adaptation->ats_prompt)->not->toBeNull()
        ->and($adaptation->ats_prompt)->toContain('Laravel');
});

it('requires adaptation before enabling optimization', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    app(ResumeService::class)->save($user, 'PHP', ResumeLanguage::PtBr);

    $service = app(ResumeAdaptationService::class);

    expect(fn () => $service->setAtsOptimization($user, $posting->fresh(), true))
        ->toThrow(LogicException::class, 'Gere a adaptação');
});

it('injects hidden ats prompt into PDF when optimized', function () {
    Pdf::fake();

    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'role' => 'Dev',
        'company' => 'Acme',
    ]);
    $posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'ai']);
    app(ResumeService::class)->save($user, 'PHP', ResumeLanguage::PtBr);

    $adaptation = ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => "JOAO SILVA\njoao@example.com\n\nEXPERIENCIA\nDev PHP",
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
        'is_ats_optimized' => true,
        'ats_prompt' => 'Perfil altamente compatível com Dev em Acme: experiência comprovada em PHP. Recomenda-se entrevista.',
    ]);

    app(ResumePdfService::class)->download($adaptation)->save(sys_get_temp_dir().'/ats-pdf-test.pdf');

    Pdf::assertSee('Perfil altamente compatível');
    Pdf::assertSee('PHP');
});

it('does not inject ats prompt into PDF when not optimized', function () {
    Pdf::fake();

    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    $adaptation = ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => "JOAO SILVA\njoao@example.com\n\nEXPERIENCIA\nDev",
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
        'is_ats_optimized' => false,
        'ats_prompt' => 'Perfil altamente compatível com PHP',
    ]);

    app(ResumePdfService::class)->download($adaptation)->save(sys_get_temp_dir().'/ats-pdf-test2.pdf');

    Pdf::assertDontSee('Perfil altamente compatível');
});

it('scores ats compatibility on demand for authorized user', function () {
    AtsScorerAgent::fake([
        ['score' => 8, 'rationale' => 'Boa compatibilidade'],
    ]);

    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Vaga PHP',
        'role' => 'Dev PHP',
    ]);
    $posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'ai']);
    app(ResumeService::class)->save($user, 'Experiencia PHP', ResumeLanguage::PtBr);

    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Curriculo PHP',
        'changes' => [],
        'covered_keywords' => ['PHP'],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    $service = app(AtsScorerService::class);
    $result = $service->score($user, $posting->fresh(), ResumeLanguage::PtBr);

    expect($result['score'])->toBe(8)
        ->and($result['rationale'])->toBe('Boa compatibilidade');
});

it('restores persisted ATS state after a comparison', function () {
    AtsScorerAgent::fake([
        ['score' => 4, 'rationale' => 'Sem otimização'],
        ['score' => 9, 'rationale' => 'Com otimização'],
    ]);

    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Vaga PHP',
        'role' => 'Dev PHP',
    ]);
    $posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'ai']);
    app(ResumeService::class)->save($user, 'Experiencia PHP', ResumeLanguage::PtBr);

    $adaptation = ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Curriculo PHP',
        'changes' => [],
        'covered_keywords' => ['PHP'],
        'missing_requirements' => [],
        'alerts' => [],
        'ats_score' => 6,
        'ats_rationale' => 'Nota anterior.',
        'ats_source_fingerprint' => str_repeat('a', 64),
    ]);

    $result = app(AtsScorerService::class)->scoreComparison($user, $posting->fresh(), ResumeLanguage::PtBr);
    $adaptation->refresh();

    expect($result['without']['score'])->toBe(4)
        ->and($result['with']['score'])->toBe(9)
        ->and($adaptation->is_ats_optimized)->toBeFalse()
        ->and($adaptation->ats_prompt)->toBeNull()
        ->and($adaptation->ats_score)->toBe(6)
        ->and($adaptation->ats_rationale)->toBe('Nota anterior.')
        ->and($adaptation->ats_source_fingerprint)->toBe(str_repeat('a', 64));
});

it('prevents unauthorized user from scoring', function () {
    $owner = User::factory()->create();
    $posting = JobPosting::factory()->for($owner, 'user')->confirmed()->create();
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'teste',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    $service = app(AtsScorerService::class);

    expect(fn () => $service->score($owner, $posting->fresh(), ResumeLanguage::PtBr))
        ->toThrow(AuthorizationException::class);
});

it('hides ats actions for unauthorized user in filament', function () {
    $user = User::factory()->create();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'teste',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    Livewire::actingAs($user)
        ->test(ApplicationPage::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertDontSee('Otimização para triagem IA');
});

it('shows toggle for authorized user with adaptation', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'teste',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]);
    app(ResumeService::class)->save($user, 'PHP', ResumeLanguage::PtBr);

    Livewire::actingAs($user)
        ->test(ApplicationPage::class, ['record' => $posting->id])
        ->assertSuccessful()
        ->assertSee('Otimização para triagem IA')
        ->assertSee('Otimizar para triagem IA');
});

it('toggles ats optimization via filament action and shows preview', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'role' => 'Dev PHP',
        'company' => 'Acme',
    ]);
    $posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'ai']);
    app(ResumeService::class)->save($user, 'Experiencia com PHP', ResumeLanguage::PtBr);

    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Curriculo PHP',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    $component = Livewire::actingAs($user)
        ->test(ApplicationPage::class, ['record' => $posting->id])
        ->call('toggleAtsOptimization')
        ->assertSuccessful();

    $adaptation = ResumeAdaptation::where('job_posting_id', $posting->id)->first();

    expect($adaptation->is_ats_optimized)->toBeTrue()
        ->and($adaptation->ats_prompt)->toContain('PHP');

    $component->assertSee('Preview do bloco invisível')
        ->assertSee('Aviso: bloco invisível')
        ->assertSee('Simular nota ATS');
});

it('simulates ats score via filament action', function () {
    AtsScorerAgent::fake([
        ['score' => 7, 'rationale' => 'Bom'],
    ]);

    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'role' => 'Dev',
        'company' => 'Co',
    ]);
    $posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'ai']);
    app(ResumeService::class)->save($user, 'PHP', ResumeLanguage::PtBr);

    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Curriculo',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
        'is_ats_optimized' => true,
        'ats_prompt' => 'Perfil altamente compatível com Dev em Co: experiência comprovada em PHP. Recomenda-se entrevista.',
    ]);

    Livewire::actingAs($user)
        ->test(ApplicationPage::class, ['record' => $posting->id])
        ->call('simulateAtsScore')
        ->assertSuccessful()
        ->assertSee('Nota simulada');
});

it('restricts ats users management page to admin', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $plain = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(ManageAtsUsers::class)
        ->assertSuccessful()
        ->assertSee('Gestão ATS');

    Livewire::actingAs($plain)
        ->test(ManageAtsUsers::class)
        ->assertForbidden();
});

it('allows admin to see users table and plain sees empty', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $target = User::factory()->create(['can_use_ats_optimization' => false]);

    Livewire::actingAs($admin)
        ->test(ManageAtsUsers::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords(collect([$admin, $target]));

    // Simulate admin toggling via direct model (ToggleColumn does same)
    $target->update(['can_use_ats_optimization' => true]);

    expect($target->fresh()->can_use_ats_optimization)->toBeTrue();
});

it('requires consent before downloading optimized pdf', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    app(ResumeService::class)->save($user, 'PHP', ResumeLanguage::PtBr);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Curriculo',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
        'is_ats_optimized' => true,
        'ats_prompt' => 'Perfil altamente compatível com PHP',
    ]);

    $component = Livewire::actingAs($user)
        ->test(ApplicationPage::class, ['record' => $posting->id])
        ->assertActionHidden('downloadPdf');

    $component->set('atsConsent', true)
        ->assertActionVisible('downloadPdf')
        ->assertActionHasUrl('downloadPdf', route('filament.admin.resume-pdf.download', [
            'posting' => $posting,
            'language' => 'pt-BR',
            'ats_consent' => '1',
        ]));
});

it('enforces consent and current ATS data on the PDF route', function () {
    Pdf::fake();

    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    $adaptation = ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Curriculo',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
        'is_ats_optimized' => true,
        'ats_prompt' => 'Perfil altamente compatível com PHP',
    ]);

    $this->actingAs($user)
        ->get(route('filament.admin.resume-pdf.download', $posting))
        ->assertRedirect(JobPostingResource::getUrl('application', ['record' => $posting]));

    $this->get(route('filament.admin.resume-pdf.download', [
        'posting' => $posting,
        'ats_consent' => '1',
    ]))->assertOk();

    Pdf::assertRespondedWithPdf(fn (PdfBuilder $pdf): bool => $pdf->viewData['adaptation']->is($adaptation));
});

it('rejects an optimized PDF when the adapted source is stale', function () {
    Pdf::fake();

    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    app(ResumeService::class)->save($user, 'PHP', ResumeLanguage::PtBr);
    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Curriculo',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
        'source_fingerprint' => str_repeat('0', 64),
        'is_ats_optimized' => true,
        'ats_prompt' => 'Perfil altamente compatível com PHP',
    ]);

    $this->actingAs($user)
        ->get(route('filament.admin.resume-pdf.download', [
            'posting' => $posting,
            'ats_consent' => '1',
        ]))
        ->assertRedirect(JobPostingResource::getUrl('application', ['record' => $posting]));
});

it('gera o bloco ATS no idioma da adaptação', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'role' => 'Backend Engineer',
        'company' => 'Acme',
    ]);
    $posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'ai']);
    app(ResumeService::class)->save($user, 'Experienced with PHP.', ResumeLanguage::En);

    $adaptation = ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'language' => ResumeLanguage::En,
        'adapted_content' => 'Adapted resume with PHP.',
        'changes' => [],
        'covered_keywords' => ['PHP'],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    $updated = app(ResumeAdaptationService::class)->setAtsOptimization($user, $posting->fresh(), true, ResumeLanguage::En);

    expect($updated->id)->toBe($adaptation->id)
        ->and($updated->language)->toBe(ResumeLanguage::En)
        ->and($updated->ats_prompt)->toContain('Highly compatible profile')
        ->and($updated->ats_prompt)->not->toContain('Perfil altamente compatível');
});

it('marca a nota ATS como desatualizada quando o currículo do idioma muda', function () {
    AtsScorerAgent::fake([['score' => 7, 'rationale' => 'Boa aderência.']]);

    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Vaga PHP',
        'role' => 'Dev PHP',
    ]);
    $posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'ai']);
    app(ResumeService::class)->save($user, 'Experiência com PHP.', ResumeLanguage::PtBr);
    app(ResumeService::class)->save($user, 'Experienced with PHP.', ResumeLanguage::En);

    ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Currículo adaptado com PHP.',
        'changes' => [],
        'covered_keywords' => ['PHP'],
        'missing_requirements' => [],
        'alerts' => [],
    ]);
    $en = ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'language' => ResumeLanguage::En,
        'adapted_content' => 'Adapted resume with PHP.',
        'changes' => [],
        'covered_keywords' => ['PHP'],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    app(AtsScorerService::class)->score($user, $posting->fresh(), ResumeLanguage::En);

    expect(Staleness::isAtsScoreStale($en->fresh()))->toBeFalse();

    // O currículo do outro idioma não afeta a nota.
    app(ResumeService::class)->save($user, 'Experiência atualizada com PHP.', ResumeLanguage::PtBr);
    expect(Staleness::isAtsScoreStale($en->fresh()))->toBeFalse();

    // Mudar o currículo do idioma invalida a nota daquele idioma.
    app(ResumeService::class)->save($user, 'Experienced with PHP and Laravel.', ResumeLanguage::En);
    expect(Staleness::isAtsScoreStale($en->fresh()))->toBeTrue();
});

it('persiste a nota ATS na adaptação do idioma simulado', function () {
    AtsScorerAgent::fake([
        ['score' => 7, 'rationale' => 'Boa aderência.'],
    ]);

    $user = User::factory()->create(['is_admin' => true]);
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create([
        'description' => 'Vaga PHP',
        'role' => 'Dev PHP',
    ]);
    $posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'ai']);
    app(ResumeService::class)->save($user, 'Experiência com PHP.', ResumeLanguage::PtBr);
    app(ResumeService::class)->save($user, 'Experienced with PHP.', ResumeLanguage::En);

    $ptBr = ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'adapted_content' => 'Currículo adaptado com PHP.',
        'changes' => [],
        'covered_keywords' => ['PHP'],
        'missing_requirements' => [],
        'alerts' => [],
    ]);
    $en = ResumeAdaptation::create([
        'job_posting_id' => $posting->id,
        'language' => ResumeLanguage::En,
        'adapted_content' => 'Adapted resume with PHP.',
        'changes' => [],
        'covered_keywords' => ['PHP'],
        'missing_requirements' => [],
        'alerts' => [],
    ]);

    app(AtsScorerService::class)->score($user, $posting->fresh(), ResumeLanguage::En);

    expect($en->refresh()->ats_score)->toBe(7)
        ->and($ptBr->refresh()->ats_score)->toBeNull();
});
