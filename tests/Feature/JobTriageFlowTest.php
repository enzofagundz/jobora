<?php

use App\Agents\JobTriageAgent;
use App\Enums\JobCaptureTriageResult;
use App\Enums\JobCaptureTriageStatus;
use App\Enums\TriageOrigin;
use App\Filament\Resources\JobCaptures\Pages\ListJobCaptures;
use App\Jobs\AnalyzeJobPosting;
use App\Jobs\PrefilterJobCaptures;
use App\Jobs\TriageJobCaptures;
use App\Models\JobCapture;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\JobTriageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
    Queue::fake();
});

function triageCapture(array $overrides = []): JobCapture
{
    return JobCapture::factory()->for(auth()->user(), 'user')->create(array_merge([
        'description' => str_repeat('Vaga para desenvolvedor PHP com Laravel. ', 5),
    ], $overrides));
}

it('marks captures as processing and dispatches the prefilter job with the ids', function () {
    $c1 = triageCapture();
    $c2 = triageCapture();

    Livewire::test(ListJobCaptures::class)
        ->callTableBulkAction('triage', [$c1, $c2]);

    Queue::assertPushed(PrefilterJobCaptures::class, function (PrefilterJobCaptures $job) use ($c1, $c2): bool {
        $ids = $job->captureIds;
        sort($ids);

        // A ordem vem da query da tabela (defaultSort), não da seleção —
        // o serviço associa resultados por capture_id, não por posição.
        return $ids === [$c1->id, $c2->id]
            && $job->userId === auth()->id();
    });

    expect(JobCapture::find($c1->id)->triage_status)->toBe(JobCaptureTriageStatus::Processing)
        ->and(JobCapture::find($c2->id)->triage_status)->toBe(JobCaptureTriageStatus::Processing);

    JobTriageAgent::assertNeverPrompted();
});

it('ignores ineligible captures in the selection', function () {
    $pending = triageCapture();
    $completed = triageCapture(['triage_status' => JobCaptureTriageStatus::Completed, 'triage_result' => JobCaptureTriageResult::Relevant]);
    $processing = triageCapture(['triage_status' => JobCaptureTriageStatus::Processing]);
    $promoted = triageCapture(['job_posting_id' => JobPosting::factory()->create()->id]);

    Livewire::test(ListJobCaptures::class)
        ->callTableBulkAction('triage', [$pending, $completed, $processing, $promoted]);

    Queue::assertPushed(PrefilterJobCaptures::class, fn (PrefilterJobCaptures $job): bool => $job->captureIds === [$pending->id] && $job->userId === auth()->id());

    expect(JobCapture::find($pending->id)->triage_status)->toBe(JobCaptureTriageStatus::Processing);
});

it('warns and does not dispatch when nothing is eligible', function () {
    triageCapture(['triage_status' => JobCaptureTriageStatus::Completed, 'triage_result' => JobCaptureTriageResult::Relevant]);

    Livewire::test(ListJobCaptures::class)
        ->callTableBulkAction('triage', [JobCapture::query()->sole()]);

    Queue::assertNothingPushed();
});

it('promotes only completed relevant/uncertain captures', function () {
    $relevant = triageCapture(['triage_status' => JobCaptureTriageStatus::Completed, 'triage_result' => JobCaptureTriageResult::Relevant]);
    $uncertain = triageCapture(['triage_status' => JobCaptureTriageStatus::Completed, 'triage_result' => JobCaptureTriageResult::Uncertain]);
    $rejected = triageCapture(['triage_status' => JobCaptureTriageStatus::Completed, 'triage_result' => JobCaptureTriageResult::Rejected]);
    $notAJob = triageCapture(['triage_status' => JobCaptureTriageStatus::Completed, 'triage_result' => JobCaptureTriageResult::NotAJob]);
    $pending = triageCapture();

    Livewire::test(ListJobCaptures::class)
        ->callTableBulkAction('promoteSelected', [$relevant, $uncertain, $rejected, $notAJob, $pending]);

    expect(JobPosting::query()->count())->toBe(2)
        ->and($relevant->refresh()->job_posting_id)->not->toBeNull()
        ->and($uncertain->refresh()->job_posting_id)->not->toBeNull()
        ->and($rejected->refresh()->job_posting_id)->toBeNull()
        ->and($notAJob->refresh()->job_posting_id)->toBeNull()
        ->and($pending->refresh()->job_posting_id)->toBeNull();

    Queue::assertCount(2, AnalyzeJobPosting::class);
});

it('shows the rejection reason as a tooltip only on the rejected badge', function () {
    triageCapture([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::Rejected,
        'triage_reason' => 'CLT presencial.',
    ]);
    triageCapture([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::Relevant,
        'triage_reason' => 'Vaga de Laravel.',
    ]);

    // O filtro default oculta rejeitadas; trocar para "Todas" para renderizá-las.
    $html = Livewire::test(ListJobCaptures::class)
        ->filterTable('triage_result', 'all')
        ->html();

    // O badge "Rejeitada" (danger) carrega o motivo no tooltip; o badge
    // "Relevante" (success) não tem tooltip — a coluna de justificativa
    // segue exibindo o motivo de qualquer resultado.
    expect((int) preg_match("/x-tooltip=\"\{\s*content: 'CLT presencial\.',[^}]*\}\"\s+class=\"[^\"]*fi-color-danger/", $html))->toBe(1)
        ->and((int) preg_match('/x-tooltip=[^>]*fi-color-success/', $html))->toBe(0);
});

it('hides rejected and non-job captures by default and shows them with the filter set to all', function () {
    triageCapture([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::Rejected,
    ]);
    triageCapture([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::NotAJob,
    ]);
    $relevant = triageCapture([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::Relevant,
    ]);
    $pending = triageCapture();

    Livewire::test(ListJobCaptures::class)
        ->assertSee('Relevante')
        ->assertSee('Aguardando triagem')
        ->assertDontSee('Rejeitada')
        ->assertDontSee('Não é uma vaga')
        ->filterTable('triage_result', 'all')
        ->assertSee('Rejeitada')
        ->assertSee('Não é uma vaga');

    expect(JobCapture::find($relevant->id)->triage_result)->toBe(JobCaptureTriageResult::Relevant)
        ->and(JobCapture::find($pending->id)->triage_status)->toBe(JobCaptureTriageStatus::Pending);
});

it('runs the triage end to end through the job', function () {
    $c1 = triageCapture();
    $c2 = triageCapture();

    Livewire::test(ListJobCaptures::class)
        ->callTableBulkAction('triage', [$c1, $c2]);

    JobTriageAgent::fake([[
        'results' => [
            ['capture_id' => $c1->id, 'is_job' => true, 'result' => 'relevant', 'reason' => 'Vaga de Laravel.'],
            ['capture_id' => $c2->id, 'is_job' => false, 'result' => 'not_a_job', 'reason' => 'Artigo sobre carreira.'],
        ],
    ]]);

    (new TriageJobCaptures([$c1->id, $c2->id], auth()->id()))->handle(app(JobTriageService::class));

    expect(JobCapture::find($c1->id)->triage_status)->toBe(JobCaptureTriageStatus::Completed)
        ->and(JobCapture::find($c1->id)->triage_result)->toBe(JobCaptureTriageResult::Relevant)
        ->and(JobCapture::find($c2->id)->triage_status)->toBe(JobCaptureTriageStatus::Completed)
        ->and(JobCapture::find($c2->id)->triage_result)->toBe(JobCaptureTriageResult::NotAJob);
});

it('marks prefilter-barred results and shows the reason for non-job badges', function () {
    triageCapture([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::NotAJob,
        'triage_origin' => TriageOrigin::Prefilter,
        'triage_reason' => 'Pré-filtro: Artigo, dica, tutorial, curso, evento ou novidade (p=0,12)',
    ]);
    triageCapture([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::NotAJob,
        'triage_origin' => TriageOrigin::Llm,
        'triage_reason' => 'Conteúdo editorial.',
    ]);

    $html = Livewire::test(ListJobCaptures::class)
        ->filterTable('triage_result', 'all')
        ->assertSee('Não é uma vaga · pré')
        ->html();

    expect($html)->toContain("content: 'Pré-filtro: Artigo, dica, tutorial, curso, evento ou novidade (p=0,12)'")
        ->and($html)->toContain("content: 'Conteúdo editorial.'");
});

it('shows the triage origin in the capture infolist', function () {
    $capture = triageCapture([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::NotAJob,
        'triage_origin' => TriageOrigin::Prefilter,
        'triage_reason' => 'Pré-filtro: Outro conteúdo que não anuncia vaga (p=0,10)',
    ]);

    $modalHtml = Livewire::test(ListJobCaptures::class)
        ->filterTable('triage_result', 'all')
        ->mountTableAction('view', $capture)
        ->assertActionMounted()
        ->getMountedActionModalHtml();

    expect($modalHtml)->toContain('Origem da triagem')
        ->and($modalHtml)->toContain('Pré-filtro Clef');
});

it('re-evaluates only prefilter-barred non-jobs without the prefilter', function () {
    $barred = triageCapture([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::NotAJob,
        'triage_origin' => TriageOrigin::Prefilter,
        'triage_reason' => 'Pré-filtro: Outro conteúdo que não anuncia vaga (p=0,10)',
        'triage_error' => 'erro antigo',
    ]);
    $decidedByAi = triageCapture([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::NotAJob,
        'triage_origin' => TriageOrigin::Llm,
    ]);
    $promoted = triageCapture([
        'job_posting_id' => JobPosting::factory()->create()->id,
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::NotAJob,
        'triage_origin' => TriageOrigin::Prefilter,
    ]);

    Livewire::test(ListJobCaptures::class)
        ->filterTable('triage_result', 'all')
        ->callTableBulkAction('retriage', [$barred, $decidedByAi, $promoted]);

    $barred->refresh();

    expect($barred->triage_status)->toBe(JobCaptureTriageStatus::Processing)
        ->and($barred->triage_result)->toBeNull()
        ->and($barred->triage_reason)->toBeNull()
        ->and($barred->triage_origin)->toBeNull()
        ->and($barred->triage_error)->toBeNull()
        ->and($decidedByAi->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Completed)
        ->and($decidedByAi->refresh()->triage_origin)->toBe(TriageOrigin::Llm)
        ->and($promoted->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Completed);

    Queue::assertPushed(TriageJobCaptures::class, function (TriageJobCaptures $job) use ($barred): bool {
        return $job->captureIds === [$barred->id] && $job->triageIds === null;
    });
    Queue::assertNotPushed(PrefilterJobCaptures::class);
});

it('warns and dispatches nothing when no selected capture is eligible for re-evaluation', function () {
    $decidedByAi = triageCapture([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::NotAJob,
        'triage_origin' => TriageOrigin::Llm,
    ]);

    Livewire::test(ListJobCaptures::class)
        ->filterTable('triage_result', 'all')
        ->callTableBulkAction('retriage', [$decidedByAi])
        ->assertNotified('Nenhuma captura elegível para reavaliação.');

    Queue::assertNothingPushed();
});

it('keeps the batch limit for re-evaluation without resetting the captures', function () {
    $captures = collect(range(1, 51))->map(fn (): JobCapture => triageCapture([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::NotAJob,
        'triage_origin' => TriageOrigin::Prefilter,
        'triage_reason' => 'Pré-filtro: Outro conteúdo que não anuncia vaga (p=0,10)',
    ]));

    Livewire::test(ListJobCaptures::class)
        ->filterTable('triage_result', 'all')
        ->callTableBulkAction('retriage', $captures->all())
        ->assertNotified('Selecione no máximo 50 capturas por lote.');

    Queue::assertNothingPushed();

    expect($captures->first()->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Completed)
        ->and($captures->first()->refresh()->triage_origin)->toBe(TriageOrigin::Prefilter);
});
