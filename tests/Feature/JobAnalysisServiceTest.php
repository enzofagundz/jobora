<?php

use App\Agents\JobExtractionAgent;
use App\Enums\JobPostingStatus;
use App\Models\JobPosting;
use App\Services\JobAnalysisService;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Laravel\Ai\Exceptions\AiException;

uses(RefreshDatabase::class);

function postingWithDescription(): JobPosting
{
    return JobPosting::factory()->create([
        'description' => 'Vaga para desenvolvedor PHP com Laravel e PostgreSQL. Requisitos: Docker, AWS e banco de dados relacional.'.str_repeat(' ', 20),
    ]);
}

it('moves a pending posting to needs_review with the suggestion stored', function () {
    JobExtractionAgent::fake([[
        'role' => 'Desenvolvedor PHP',
        'company' => null,
        'skills' => ['Laravel', 'postgres', 'PHP'],
    ]]);

    $posting = postingWithDescription();

    app(JobAnalysisService::class)->analyze($posting->user, $posting);

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::NeedsReview)
        ->and($posting->role)->toBe('Desenvolvedor PHP')
        ->and($posting->company)->toBeNull()
        ->and($posting->error_message)->toBeNull()
        ->and($posting->suggested_data['items'])->toContain(['category' => 'skill', 'name' => 'Laravel'])
        ->and($posting->suggested_data['items'])->toContain(['category' => 'skill', 'name' => 'PostgreSQL'])
        ->and($posting->suggested_data['items'])->toContain(['category' => 'skill', 'name' => 'PHP']);
});

it('stores suggested application methods and keeps the prompt contract', function () {
    JobExtractionAgent::fake([[
        'role' => 'Dev',
        'application_methods' => [
            ['type' => 'email', 'email' => 'recrutamento@empresa.com'],
            ['type' => 'external_url', 'url' => 'https://empresa.gupy.io/jobs/123', 'platform' => 'Gupy'],
        ],
    ]]);

    $posting = postingWithDescription();

    app(JobAnalysisService::class)->analyze($posting->user, $posting);

    $posting->refresh();

    expect($posting->suggested_data['application_methods'])->toBe([
        ['type' => 'email', 'platform' => null, 'url' => null, 'email' => 'recrutamento@empresa.com', 'phone' => null, 'instructions' => null],
        ['type' => 'external_url', 'platform' => 'Gupy', 'url' => 'https://empresa.gupy.io/jobs/123', 'email' => null, 'phone' => null, 'instructions' => null],
    ]);
});

it('keeps old fakes without application methods as an empty list', function () {
    JobExtractionAgent::fake([['role' => 'Dev', 'skills' => ['Laravel']]]);

    $posting = postingWithDescription();

    app(JobAnalysisService::class)->analyze($posting->user, $posting);

    $posting->refresh();

    expect($posting->suggested_data['application_methods'])->toBe([]);
});

it('marks the posting failed when the agent throws', function () {
    JobExtractionAgent::fake(fn () => throw new RuntimeException('Provider indisponível'));

    $posting = postingWithDescription();

    app(JobAnalysisService::class)->analyze($posting->user, $posting);

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::Failed)
        ->and($posting->error_message)->toBe('Não foi possível concluir a análise com IA. Tente novamente.')
        ->and($posting->suggested_data)->toBeNull()
        ->and($posting->attempts()->first()?->error_message)->toContain('Provider indisponível');
});

it('keeps the posting pending on a transient provider error', function () {
    $response = new Response(new PsrResponse(401, [], json_encode([
        'type' => 'error',
        'error' => ['type' => 'CreditsError', 'message' => 'Insufficient balance.'],
    ])));

    JobExtractionAgent::fake(fn () => throw new RequestException($response));

    $posting = postingWithDescription();

    try {
        app(JobAnalysisService::class)->analyze($posting->user, $posting);
        expect(false)->toBeTrue('Expected RequestException not thrown');
    } catch (RequestException $e) {
        expect($e->getMessage())->not->toBeEmpty();
    }

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::Pending)
        ->and($posting->error_message)->toBeNull()
        ->and($posting->suggested_data)->toBeNull();
});

it('keeps the posting pending on a provider rate-limit AiException', function () {
    JobExtractionAgent::fake(fn () => throw new AiException('Rate limit exceeded for the free model.'));

    $posting = postingWithDescription();

    try {
        app(JobAnalysisService::class)->analyze($posting->user, $posting);
        expect(false)->toBeTrue('Expected AiException not thrown');
    } catch (AiException $e) {
        expect($e->getMessage())->toContain('Rate limit');
    }

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::Pending)
        ->and($posting->error_message)->toBeNull()
        ->and($posting->suggested_data)->toBeNull();
});

it('marks the posting failed on structurally invalid output', function () {
    JobExtractionAgent::fake([['skills' => 'not-a-list']]);

    $posting = postingWithDescription();

    app(JobAnalysisService::class)->analyze($posting->user, $posting);

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::Failed)
        ->and($posting->error_message)->toBe('Não foi possível concluir a análise com IA. Tente novamente.')
        ->and($posting->attempts()->first()?->error_message)->toContain('deve ser uma lista de strings');
});

it('marks the posting failed when the response is not valid JSON', function () {
    JobExtractionAgent::fake(['isto não é um JSON válido']);

    $posting = postingWithDescription();

    app(JobAnalysisService::class)->analyze($posting->user, $posting);

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::Failed)
        ->and($posting->error_message)->toBe('Não conseguimos analisar esta vaga porque a IA devolveu um formato inválido. Tente novamente — se persistir, revise a descrição.')
        ->and($posting->attempts()->first()?->error_message)->toContain('JSON válido');
});

it('marks the posting failed when the description is out of bounds', function () {
    JobExtractionAgent::fake([['role' => 'Dev']]);

    $posting = JobPosting::factory()->create(['description' => 'curta']);

    app(JobAnalysisService::class)->analyze($posting->user, $posting);

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::Failed)
        ->and($posting->error_message)->toBe('Não foi possível concluir a análise com IA. Tente novamente.')
        ->and($posting->attempts()->first()?->error_message)->toContain('entre 20 e 100000 caracteres');
});

it('recovers owner categories end to end from misclassified output', function () {
    JobExtractionAgent::fake([[
        'role' => 'Desenvolvedor PHP Sênior',
        'company' => null,
        'skills' => ['api rest'],
        'tools' => ['php', 'REST API'],
        'methodologies' => [],
        'requirements' => [],
        'experience' => [],
    ]]);

    $posting = postingWithDescription();

    app(JobAnalysisService::class)->analyze($posting->user, $posting);

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::NeedsReview)
        ->and($posting->role)->toBe('Desenvolvedor PHP Sênior')
        ->and($posting->suggested_data['items'])->toContain(['category' => 'skill', 'name' => 'PHP'])
        ->and($posting->suggested_data['items'])->toContain(['category' => 'skill', 'name' => 'REST API'])
        ->and($posting->suggested_data['items'])->not->toContain(['category' => 'tool', 'name' => 'PHP']);
});

it('retries a failed posting', function () {
    $attempts = 0;

    JobExtractionAgent::fake(function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            throw new RuntimeException('boom');
        }

        return ['role' => 'Dev', 'skills' => ['PHP']];
    });

    $posting = postingWithDescription();
    $service = app(JobAnalysisService::class);

    $service->analyze($posting->user, $posting);
    expect($posting->refresh()->status)->toBe(JobPostingStatus::Failed);

    $service->analyze($posting->user, $posting->refresh());
    expect($posting->refresh()->status)->toBe(JobPostingStatus::NeedsReview)
        ->and($posting->role)->toBe('Dev')
        ->and($posting->error_message)->toBeNull();
});

it('never re-analyzes a confirmed posting', function () {
    JobExtractionAgent::fake([['role' => 'Dev']]);

    $posting = JobPosting::factory()->confirmed()->create(['description' => str_repeat('Vaga Laravel. ', 20)]);

    app(JobAnalysisService::class)->analyze($posting->user, $posting);

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::Confirmed)
        ->and($posting->suggested_data)->toBeNull();
})->throws(LogicException::class, 'não pode ser re-analisada');
