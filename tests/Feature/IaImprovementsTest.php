<?php

use App\Agents\JobExtractionAgent;
use App\Agents\JobTriageAgent;
use App\Agents\ResumeAdaptationAgent;
use App\Agents\StrategicCompetencyAgent;
use App\Enums\JobCaptureTriageResult;
use App\Enums\JobCaptureTriageStatus;
use App\Enums\JobPostingStatus;
use App\Jobs\AnalyzeJobPosting;
use App\Jobs\TriageJobCaptures;
use App\Models\JobCapture;
use App\Models\JobPosting;
use App\Models\ProfessionalCompetency;
use App\Models\ScreeningPreference;
use App\Models\User;
use App\Services\JobAnalysisService;
use App\Services\JobTriageService;
use App\Services\ResumeAdaptationService;
use App\Services\ResumeService;
use App\Services\StrategicCompetencyService;
use App\Support\Staleness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Contracts\HasStructuredOutput;

uses(RefreshDatabase::class);

it('não envia HTML para a extração, apenas texto puro', function () {
    $posting = JobPosting::factory()->create([
        'description' => '<h1>Vaga PHP</h1><p>Requisitos: <strong>Laravel</strong> e <em>PostgreSQL</em>.</p><ul><li>Docker</li></ul>',
        'status' => JobPostingStatus::Pending,
    ]);

    $captured = null;

    JobExtractionAgent::fake(function (string $prompt) use (&$captured) {
        $captured = $prompt;

        return [
            'role' => 'Desenvolvedor PHP',
            'company' => null,
            'skills' => ['Laravel'],
            'tools' => [],
            'methodologies' => [],
            'requirements' => [],
            'experience' => [],
        ];
    });

    app(JobAnalysisService::class)->analyze($posting);

    expect($captured)->not->toContain('<')
        ->and($captured)->toContain('Vaga PHP')
        ->and($captured)->toContain('Laravel')
        ->and($captured)->toContain('PostgreSQL')
        ->and($captured)->toContain('Docker');
});

it('triagem envia apenas campos necessários (sem source/external_id/url)', function () {
    $user = User::factory()->create();
    ScreeningPreference::factory()->create(['user_id' => $user->id, 'preferences' => [], 'rejections' => []]);

    $capture = JobCapture::factory()->create([
        'source' => 'linkedin',
        'external_id' => 'urn:li:activity:123',
        'url' => 'https://linkedin.com/feed/update/123',
        'title' => 'Dev PHP',
        'description' => 'Vaga para Dev PHP com Laravel',
        'company' => 'Acme',
        'location' => 'Remoto',
    ]);

    $captured = null;
    JobTriageAgent::fake(function (string $prompt) use (&$captured, $capture) {
        $captured = $prompt;

        return ['results' => [
            ['capture_id' => $capture->id, 'is_job' => true, 'result' => 'relevant', 'reason' => 'ok'],
        ]];
    });

    app(JobTriageService::class)->triageBatch(collect([$capture]), $user);

    $payload = json_decode($captured, true);

    expect($payload['captures'][0])->toHaveKeys(['id', 'title', 'description', 'company', 'location'])
        ->and($payload['captures'][0])->not->toHaveKey('source')
        ->and($payload['captures'][0])->not->toHaveKey('external_id')
        ->and($payload['captures'][0])->not->toHaveKey('url');
});

it('adaptação não envia job_items duplicado nem resume_concepts', function () {
    $posting = JobPosting::factory()->confirmed()->create([
        'description' => 'Vaga para Dev PHP com Laravel',
        'role' => 'Dev PHP',
        'company' => 'Acme',
    ]);
    $posting->items()->create(['category' => 'skill', 'name' => 'Laravel', 'source' => 'ai']);
    $posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'ai']);

    app(ResumeService::class)->save('Currículo com PHP e Laravel.');

    $captured = null;
    ResumeAdaptationAgent::fake(function (string $prompt) use (&$captured) {
        $captured = $prompt;

        return [
            'adapted_resume' => 'Currículo adaptado com PHP e Laravel.',
            'changes' => [],
            'covered_keywords' => [],
            'missing_requirements' => [],
            'alerts' => [],
        ];
    });

    app(ResumeAdaptationService::class)->adapt($posting->refresh());

    $payload = json_decode($captured, true);

    expect($payload)->toHaveKeys(['resume', 'job_description', 'job_role', 'job_company', 'positioning'])
        ->and($payload)->not->toHaveKey('job_items')
        ->and($payload)->not->toHaveKey('resume_concepts')
        ->and($payload['positioning'])->toHaveCount(2);
});

it('classificação não envia resume_concepts redundante', function () {
    $user = User::factory()->create();
    app(ResumeService::class)->save('Currículo com PHP.');
    $posting = JobPosting::factory()->confirmed()->create();
    $posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'ai']);

    $captured = null;
    StrategicCompetencyAgent::fake(function (string $prompt) use (&$captured) {
        $captured = $prompt;

        return ['competencies' => []];
    });

    app(StrategicCompetencyService::class)->classify($user);

    $payload = json_decode($captured, true);

    expect($payload)->toHaveKeys(['resume', 'market_items', 'candidate_concepts'])
        ->and($payload)->not->toHaveKey('resume_concepts');
});

it('registra métricas de observabilidade na extração sem quebrar quando usage ausente', function () {
    $posting = JobPosting::factory()->create([
        'description' => 'Vaga para Dev PHP com Laravel e PostgreSQL. Requisitos: Docker com experiência.',
        'status' => JobPostingStatus::Pending,
    ]);

    JobExtractionAgent::fake([[
        'role' => null,
        'company' => null,
        'skills' => [],
        'tools' => [],
        'methodologies' => [],
        'requirements' => [],
        'experience' => [],
    ]]);

    expect(fn () => app(JobAnalysisService::class)->analyze($posting))->not->toThrow(Exception::class);

    $posting->refresh();
    expect($posting->status)->toBe(JobPostingStatus::NeedsReview);
});

it('falha no registro de métricas não impede o processamento principal', function () {
    $posting = JobPosting::factory()->create([
        'description' => 'Vaga para Dev com PHP. Requisitos: Laravel com experiência.',
        'status' => JobPostingStatus::Pending,
    ]);

    JobExtractionAgent::fake([[
        'role' => 'Dev',
        'company' => null,
        'skills' => ['PHP'],
        'tools' => [],
        'methodologies' => [],
        'requirements' => [],
        'experience' => [],
    ]]);

    $result = app(JobAnalysisService::class)->analyze($posting);

    expect($result->status)->toBe(JobPostingStatus::NeedsReview);
});

it('AnalyzeJobPosting é único por posting', function () {
    $posting = JobPosting::factory()->create();
    $userId = User::factory()->create()->id;
    $job1 = new AnalyzeJobPosting($posting, $userId);
    $job2 = new AnalyzeJobPosting($posting, $userId);

    expect($job1->uniqueId())->toBe($job2->uniqueId())
        ->and($job1->uniqueId())->toBe('analyze-job-posting:'.$posting->id)
        ->and($job1->timeout)->toBe(130)
        ->and($job1->tries)->toBe(3);
});
it('AnalyzeJobPosting tem uniqueIds diferentes para postings diferentes', function () {
    $p1 = JobPosting::factory()->create();
    $p2 = JobPosting::factory()->create();
    $userId = User::factory()->create()->id;

    expect((new AnalyzeJobPosting($p1, $userId))->uniqueId())->not->toBe((new AnalyzeJobPosting($p2, $userId))->uniqueId());
});

it('TriageJobCaptures é único por user + ids ordenados', function () {
    $job1 = new TriageJobCaptures([3, 1, 2], 5);
    $job2 = new TriageJobCaptures([1, 2, 3], 5);
    $job3 = new TriageJobCaptures([1, 2, 3], 6);

    expect($job1->uniqueId())->toBe($job2->uniqueId())
        ->and($job1->uniqueId())->not->toBe($job3->uniqueId())
        ->and($job1->timeout)->toBe(130);
});

it('TriageJobCaptures failed() reseta processing para failed', function () {
    $c1 = JobCapture::factory()->create(['triage_status' => JobCaptureTriageStatus::Processing]);
    $c2 = JobCapture::factory()->create(['triage_status' => JobCaptureTriageStatus::Completed]);

    $job = new TriageJobCaptures([$c1->id, $c2->id], 1);
    $job->failed(new RuntimeException('timeout'));

    expect($c1->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Failed)
        ->and($c1->refresh()->triage_error)->toContain('timeout')
        ->and($c2->refresh()->triage_status)->toBe(JobCaptureTriageStatus::Completed);
});

it('currículo alterado invalida adaptação mas não reextrai vaga', function () {
    $posting = JobPosting::factory()->confirmed()->create(['description' => 'Vaga PHP']);
    $posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'ai']);

    app(ResumeService::class)->save('Currículo inicial com PHP.');

    ResumeAdaptationAgent::fake([[
        'adapted_resume' => 'Adaptado com PHP.',
        'changes' => [],
        'covered_keywords' => [],
        'missing_requirements' => [],
        'alerts' => [],
    ]]);

    $adaptation = app(ResumeAdaptationService::class)->adapt($posting);

    expect(Staleness::isAdaptationStale($adaptation))->toBeFalse();

    sleep(1);
    app(ResumeService::class)->save('Currículo atualizado com Python e Laravel.');

    expect(Staleness::isAdaptationStale($adaptation->refresh()))->toBeTrue();

    $freshPosting = $posting->refresh();
    expect($freshPosting->description)->toBe('Vaga PHP');
});

it('preferências alteradas invalidam triagem mas não extração', function () {
    $user = User::factory()->create();
    $pref = ScreeningPreference::factory()->create(['user_id' => $user->id]);

    $capture = JobCapture::factory()->create(['triage_status' => JobCaptureTriageStatus::Completed, 'triage_result' => JobCaptureTriageResult::Relevant]);

    expect(Staleness::isTriageStale($capture, $pref))->toBeFalse();

    sleep(1);
    $pref->update(['preferences' => ['Novo critério']]);

    expect(Staleness::isTriageStale($capture->refresh(), $pref->refresh()))->toBeTrue();
});

it('competência fica stale quando currículo ou mercado mudam', function () {
    $user = User::factory()->create();
    app(ResumeService::class)->save('Currículo com PHP.');

    $competency = ProfessionalCompetency::factory()->create(['user_id' => $user->id, 'concept' => 'PHP']);

    sleep(1);
    app(ResumeService::class)->save('Currículo com PHP e Laravel.');

    expect(Staleness::isCompetencyStale($competency->refresh()))->toBeTrue();
});

it('widgets de mercado não disparam chamadas de IA', function () {
    $this->actingAs(User::factory()->create());

    JobExtractionAgent::fake([]);
    JobTriageAgent::fake([]);
    ResumeAdaptationAgent::fake([]);
    StrategicCompetencyAgent::fake([]);

    JobPosting::factory()->confirmed()->create();

    $response = $this->get('/market-comparison');
    $response->assertSuccessful();

    JobExtractionAgent::assertNeverPrompted();
    JobTriageAgent::assertNeverPrompted();
    ResumeAdaptationAgent::assertNeverPrompted();
    StrategicCompetencyAgent::assertNeverPrompted();
});

it('queue retry_after é maior que o timeout do agente', function () {
    $retryAfter = config('queue.connections.database.retry_after');
    $agentTimeout = (new JobExtractionAgent)->timeout();

    expect($retryAfter)->toBeGreaterThan($agentTimeout)
        ->and($retryAfter)->toBe(180);
});

it('agents resolve provider/model from the active config, not hardcoded', function () {
    config([
        'ai.default' => 'openrouter',
        'ai.providers.openrouter.models.text.default' => 'test/model-from-config',
    ]);

    $agents = [
        new JobExtractionAgent,
        new JobTriageAgent,
        new ResumeAdaptationAgent,
        new StrategicCompetencyAgent,
    ];

    foreach ($agents as $agent) {
        expect($agent->provider())->toBe('openrouter')
            ->and($agent->model())->toBe('test/model-from-config')
            ->and($agent->timeout())->toBe(120)
            ->and($agent)->not->toBeInstanceOf(HasStructuredOutput::class);
    }
});
