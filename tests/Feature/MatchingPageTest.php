<?php

use App\Filament\Resources\JobPostings\Pages\MatchingPage;
use App\Filament\Resources\JobPostings\Pages\ViewJobPosting;
use App\Models\JobPosting;
use App\Models\MatchingEvaluation;
use App\Models\User;
use App\Services\AiOperationService;
use App\Services\MatchingService;
use App\Services\ProfessionalProfileService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function matchingUiCases(): array
{
    return json_decode(file_get_contents(__DIR__.'/../Fixtures/matching-v1.json'), true, 512, JSON_THROW_ON_ERROR)['cases'];
}

function matchingUiSetup(object $test, array $case, bool $confirmed = true): void
{
    Http::preventStrayRequests();
    config(['queue.default' => 'database', 'ai.providers.opencode_go.key' => 'test-only-key']);
    $test->owner = User::factory()->create();
    $test->actingAs($test->owner);
    $profile = app(ProfessionalProfileService::class);
    $profile->saveDraft($test->owner, $case['frozen']['profile']['snapshot'], 0);
    if ($confirmed) {
        $profile->confirm($test->owner, 1, ['source' => 'filament']);
        $profile->saveDraft($test->owner, $case['frozen']['profile']['snapshot'], 2);
        $profile->confirm($test->owner, 3, ['source' => 'filament']);
    }
    $test->posting = JobPosting::factory()->for($test->owner, 'user')->create([
        ...$case['frozen']['opportunity']['snapshot'],
        'readiness' => 'ready', 'opportunity_version' => 3,
    ]);
}

function matchingUiResponse(array $case, array $payload): array
{
    $anchors = collect($payload['frozen']['anchors']);
    $criteria = $case['expected']['criteria'];
    $fixtureAnchors = collect($case['frozen']['anchors'])->keyBy('id');
    foreach ($criteria as &$criterion) {
        $criterion['explanation'] = '<script>alert("unsafe")</script> Justificativa de exemplo.';
        foreach ($criterion['evidence'] as &$evidence) {
            $original = $fixtureAnchors->get($evidence['anchor_id']);
            $anchor = $anchors->first(fn (array $candidate): bool => $candidate['source'] === $original['source'] && $candidate['path'] === $original['path']);
            $evidence['anchor_id'] = $anchor['id'];
        }
        unset($evidence);
    }
    unset($criterion);

    $frozen = $payload['frozen'];

    return [
        'schema_version' => 'matching-result-v1',
        'criteria_version' => 'matching-v1',
        'input_fingerprint' => $payload['input_fingerprint'],
        'basis' => [
            'profile_id' => $frozen['profile']['id'],
            'confirmed_version' => $frozen['profile']['confirmed_version'],
            'opportunity_id' => $frozen['opportunity']['id'],
            'opportunity_version' => $frozen['opportunity']['opportunity_version'],
            'profile_hash' => $frozen['profile']['hash'],
            'opportunity_hash' => $frozen['opportunity']['hash'],
        ],
        'criteria' => $criteria,
        'summary' => $case['expected']['summary'],
    ];
}

function matchingUiFakeProvider(array $case): void
{
    Http::fake(function ($request) use ($case) {
        $messages = $request['messages'];
        $payload = json_decode($messages[array_key_last($messages)]['content'], true, 512, JSON_THROW_ON_ERROR);

        return Http::response(['choices' => [[
            'message' => ['content' => json_encode(matchingUiResponse($case, $payload), JSON_THROW_ON_ERROR)],
            'finish_reason' => 'stop',
        ]]]);
    });
}

function matchingUiRunWorker(): void
{
    $job = DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<=', now()->timestamp)->orderBy('id')->first();
    if ($job !== null) {
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 3]);
    }
}

it('links ready opportunity to matching without adding top-level navigation', function () {
    matchingUiSetup($this, matchingUiCases()[0]);

    Livewire::test(ViewJobPosting::class, ['record' => $this->posting->id])
        ->assertActionVisible('matching')
        ->assertSee('Ver compatibilidade');
    Livewire::test(MatchingPage::class, ['record' => $this->posting->id])
        ->assertSuccessful()
        ->assertSee('matching-v1')
        ->assertSee('Solicitar avaliação');
});

it('requires ready opportunity and confirmed profile without dispatching', function () {
    matchingUiSetup($this, matchingUiCases()[0], confirmed: false);

    Livewire::test(MatchingPage::class, ['record' => $this->posting->id])
        ->assertSee('Confirme seu perfil')
        ->call('requestMatching')
        ->assertNotified('Não é possível solicitar compatibilidade');
    $this->assertDatabaseCount('ai_operations', 0);

    $this->posting->update(['readiness' => 'needs_review']);
    Livewire::test(MatchingPage::class, ['record' => $this->posting->id])
        ->assertSee('Oportunidade ainda não está pronta')
        ->call('requestMatching');
    $this->assertDatabaseCount('ai_operations', 0);
    Http::assertNothingSent();
});

it('requests matching, runs real operation and refreshes current result and history', function () {
    $case = matchingUiCases()[0];
    matchingUiSetup($this, $case);
    $page = Livewire::test(MatchingPage::class, ['record' => $this->posting->id])
        ->call('requestMatching')
        ->assertNotified('Avaliação solicitada')
        ->assertSet('operationState.status', 'queued');

    Http::assertNothingSent();
    matchingUiFakeProvider($case);
    matchingUiRunWorker();

    $page->call('refreshMatching')
        ->assertSet('operationState.status', 'completed')
        ->assertSee('Avaliação atual')
        ->assertSee('Atendido')
        ->assertSee('Sem evidência')
        ->assertSee('Não é recomendação de candidatura')
        ->assertSee('Justificativa de exemplo.')
        ->assertDontSeeHtml('<script>alert("unsafe")</script>')
        ->assertSee('Histórico de avaliações');
    expect(MatchingEvaluation::query()->count())->toBe(1)
        ->and(app(MatchingService::class)->forOpportunity($this->owner, $this->posting->id)['history']['total'])->toBe(1);
    Http::assertSentCount(1);
});

it('preserves stale history after draft changes and marks confirmed source changes stale', function () {
    $case = matchingUiCases()[0];
    matchingUiSetup($this, $case);
    Livewire::test(MatchingPage::class, ['record' => $this->posting->id])->call('requestMatching');
    matchingUiFakeProvider($case);
    matchingUiRunWorker();

    app(ProfessionalProfileService::class)->saveDraft($this->owner, array_replace($case['frozen']['profile']['snapshot'], ['competencies' => ['Draft-only']]), 4);
    $draftPage = Livewire::test(MatchingPage::class, ['record' => $this->posting->id])
        ->assertSet('activeEvaluation.stale', false)
        ->assertSee('Avaliação atual');

    app(ProfessionalProfileService::class)->confirm($this->owner, 5, ['source' => 'filament']);
    $confirmedPage = Livewire::test(MatchingPage::class, ['record' => $this->posting->id])
        ->assertSet('activeEvaluation.stale', true)
        ->assertSee('Avaliação anterior — fontes alteradas')
        ->assertSee('não representa estado atual');
    expect(MatchingEvaluation::query()->count())->toBe(1);
    $draftPage->assertSee('Atendidos: 1');
    $confirmedPage->call('refreshMatching');
});

it('does not replace versions captured on page load after a concurrent update', function () {
    matchingUiSetup($this, matchingUiCases()[0]);
    $page = Livewire::test(MatchingPage::class, ['record' => $this->posting->id]);
    app(ProfessionalProfileService::class)->confirm($this->owner, 4, ['source' => 'filament']);

    $page->call('requestMatching')
        ->assertNotified('Não foi possível solicitar compatibilidade')
        ->assertSet('expectedProfileConfirmedVersion', 4);
    $this->assertDatabaseCount('ai_operations', 0);
    Http::assertNothingSent();
});

it('locks captured versions against Livewire property tampering', function () {
    matchingUiSetup($this, matchingUiCases()[0]);

    expect(fn () => Livewire::test(MatchingPage::class, ['record' => $this->posting->id])
        ->set('expectedOpportunityVersion', 999))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('isolates opportunity and evaluation data by authenticated owner', function () {
    matchingUiSetup($this, matchingUiCases()[0]);
    $other = User::factory()->create();
    $this->actingAs($other);

    $this->get(MatchingPage::getUrl(['record' => $this->posting->id]))->assertNotFound();
    expect(fn () => app(MatchingService::class)->forOpportunity($other, $this->posting->id))
        ->toThrow(ModelNotFoundException::class);
});

it('renders four criterion states, missing source warning and escaped free explanation', function () {
    $case = matchingUiCases()[0];
    matchingUiSetup($this, $case);
    $service = app(MatchingService::class);
    $frozen = $service->snapshot($this->owner, $this->posting->id)['frozen'];
    $criteria = [];
    foreach (['met', 'unsupported', 'incompatible', 'uncertain'] as $status) {
        $criteria[] = [
            'id' => $status, 'kind' => 'experience', 'status' => $status,
            'reason_code' => match ($status) {
                'met' => 'explicit_support', 'incompatible' => 'explicit_conflict', 'uncertain' => 'ambiguous_evidence', default => 'criterion_not_stated'
            },
            'explanation' => '<b>Justificativa livre</b>', 'evidence' => [], 'missing_paths' => ['profile:/experiences'],
        ];
    }
    $result = [
        'criteria' => $criteria,
        'summary' => ['met' => 1, 'unsupported' => 1, 'incompatible' => 1, 'uncertain' => 1],
        'basis' => ['confirmed_version' => 4, 'opportunity_version' => 3],
    ];
    $operation = app(AiOperationService::class)->enqueueMatching(
        $this->owner, $this->posting->id, 3, 4, 'matching-v1', ['source' => 'filament'],
    );
    $operation->update(['status' => 'completed', 'completed_at' => now()]);
    MatchingEvaluation::query()->create([
        'user_id' => $this->owner->id, 'job_posting_id' => $this->posting->id,
        'professional_profile_id' => $this->owner->professionalProfile()->value('id'),
        'ai_operation_id' => $operation->id, 'run_token' => $operation->run_token, 'attempt_token' => $operation->attempt_token,
        'profile_hash' => $frozen['profile']['hash'], 'opportunity_hash' => $frozen['opportunity']['hash'],
        'input_fingerprint' => MatchingService::hash($frozen),
        'confirmed_version' => 4, 'opportunity_version' => 3, 'frozen' => $frozen,
        'result' => $result, 'provenance_warnings' => ['source_snapshot_unavailable:1'],
        'provider' => 'opencode_go', 'model' => 'deepseek-v4.1-flash', 'criteria_version' => 'matching-v1', 'schema_version' => 'matching-result-v1',
    ]);

    Livewire::test(MatchingPage::class, ['record' => $this->posting->id])
        ->assertSee('Atendido')
        ->assertSee('Sem evidência')
        ->assertSee('Incompatível')
        ->assertSee('Incerto')
        ->assertSee('Perfil confirmado v4')
        ->assertSee('Proveniência incompleta')
        ->assertDontSeeHtml('<b>Justificativa livre</b>');
});

function matchingUiHistory(object $test, int $count): array
{
    $case = matchingUiCases()[0];
    matchingUiSetup($test, $case);
    Livewire::test(MatchingPage::class, ['record' => $test->posting->id])->call('requestMatching');
    matchingUiFakeProvider($case);
    matchingUiRunWorker();
    $evaluation = MatchingEvaluation::query()->sole();
    for ($index = 1; $index < $count; $index++) {
        $copy = $evaluation->replicate();
        $copy->input_fingerprint = hash('sha256', 'history-'.$index);
        $copy->attempt_token = (string) Str::uuid();
        $copy->save();
    }

    return MatchingEvaluation::query()->latest('id')->pluck('id')->all();
}

it('appends earlier evaluations without losing displayed history', function () {
    $ids = matchingUiHistory($this, 23);
    $page = Livewire::test(MatchingPage::class, ['record' => $this->posting->id]);
    expect(array_column($page->get('matchingData.history.items'), 'id'))->toBe(array_slice($ids, 0, 10));

    $page->call('showMoreHistory');
    expect(array_column($page->get('matchingData.history.items'), 'id'))->toBe(array_slice($ids, 0, 20));
    $page->call('showMoreHistory')->assertSet('nextHistoryCursor', null);
    expect(array_column($page->get('matchingData.history.items'), 'id'))->toBe($ids);
    $page->call('showMoreHistory');
    expect(array_column($page->get('matchingData.history.items'), 'id'))->toBe($ids);
});
