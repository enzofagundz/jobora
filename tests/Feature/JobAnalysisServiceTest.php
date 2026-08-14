<?php

use App\Agents\JobExtractionAgent;
use App\Enums\JobPostingStatus;
use App\Models\JobPosting;
use App\Services\JobAnalysisService;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

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
        'technologies' => ['Laravel', 'postgres'],
        'languages' => ['PHP'],
    ]]);

    $posting = postingWithDescription();

    app(JobAnalysisService::class)->analyze($posting);

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::NeedsReview)
        ->and($posting->role)->toBe('Desenvolvedor PHP')
        ->and($posting->company)->toBeNull()
        ->and($posting->error_message)->toBeNull()
        ->and($posting->suggested_data['items'])->toContain(['category' => 'technology', 'name' => 'Laravel'])
        ->and($posting->suggested_data['items'])->toContain(['category' => 'technology', 'name' => 'PostgreSQL'])
        ->and($posting->suggested_data['items'])->toContain(['category' => 'language', 'name' => 'PHP']);
});

it('marks the posting failed when the agent throws', function () {
    JobExtractionAgent::fake(fn () => throw new RuntimeException('Provider indisponível'));

    $posting = postingWithDescription();

    app(JobAnalysisService::class)->analyze($posting);

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::Failed)
        ->and($posting->error_message)->toContain('Provider indisponível')
        ->and($posting->suggested_data)->toBeNull();
});

it('keeps the posting pending on a transient provider error', function () {
    $response = new Response(new PsrResponse(401, [], json_encode([
        'type' => 'error',
        'error' => ['type' => 'CreditsError', 'message' => 'Insufficient balance.'],
    ])));

    JobExtractionAgent::fake(fn () => throw new RequestException($response));

    $posting = postingWithDescription();

    app(JobAnalysisService::class)->analyze($posting);

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::Pending)
        ->and($posting->error_message)->toBeNull()
        ->and($posting->suggested_data)->toBeNull();
});

it('marks the posting failed on structurally invalid output', function () {
    JobExtractionAgent::fake([['technologies' => 'not-a-list']]);

    $posting = postingWithDescription();

    app(JobAnalysisService::class)->analyze($posting);

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::Failed)
        ->and($posting->error_message)->toContain('deve ser uma lista de strings');
});

it('marks the posting failed when the response is not valid JSON', function () {
    JobExtractionAgent::fake(['isto não é um JSON válido']);

    $posting = postingWithDescription();

    app(JobAnalysisService::class)->analyze($posting);

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::Failed)
        ->and($posting->error_message)->toContain('JSON válido');
});

it('marks the posting failed when the description is out of bounds', function () {
    JobExtractionAgent::fake([['role' => 'Dev']]);

    $posting = JobPosting::factory()->create(['description' => 'curta']);

    app(JobAnalysisService::class)->analyze($posting);

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::Failed)
        ->and($posting->error_message)->toContain('entre 20 e 100000 caracteres');
});

it('retries a failed posting', function () {
    $attempts = 0;

    JobExtractionAgent::fake(function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            throw new RuntimeException('boom');
        }

        return ['role' => 'Dev', 'technologies' => ['PHP']];
    });

    $posting = postingWithDescription();
    $service = app(JobAnalysisService::class);

    $service->analyze($posting);
    expect($posting->refresh()->status)->toBe(JobPostingStatus::Failed);

    $service->analyze($posting->refresh());
    expect($posting->refresh()->status)->toBe(JobPostingStatus::NeedsReview)
        ->and($posting->role)->toBe('Dev')
        ->and($posting->error_message)->toBeNull();
});

it('never re-analyzes a confirmed posting', function () {
    JobExtractionAgent::fake([['role' => 'Dev']]);

    $posting = JobPosting::factory()->confirmed()->create(['description' => str_repeat('Vaga Laravel. ', 20)]);

    app(JobAnalysisService::class)->analyze($posting);

    $posting->refresh();

    expect($posting->status)->toBe(JobPostingStatus::Confirmed)
        ->and($posting->suggested_data)->toBeNull();
})->throws(LogicException::class, 'não pode ser re-analisada');
