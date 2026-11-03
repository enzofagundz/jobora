<?php

use App\Services\Triage\ClefJobPrefilter;
use App\Services\Triage\PrefilterException;
use App\Services\Triage\PrefilterInput;
use App\Services\Triage\PrefilterVerdict;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function clefPrefilter(): ClefJobPrefilter
{
    return new ClefJobPrefilter(
        accountId: 'account-123',
        apiToken: 'token-abc',
        model: '@cf/cloudflare/clef-flash',
        baseUrl: 'https://api.cloudflare.com/client/v4',
        timeout: 15,
        concurrency: 5,
    );
}

function clefInput(): PrefilterInput
{
    return new PrefilterInput(
        title: 'Desenvolvedor PHP',
        description: 'Vaga para desenvolvedor PHP com Laravel e testes.',
        company: 'Acme',
        location: 'Remoto',
    );
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function clefResponse(array $overrides = []): array
{
    return array_replace_recursive([
        'result' => [
            'model' => 'clef-flash',
            'answers' => [
                'is_job' => ['type' => 'noul', 'noul' => 0.87],
                'non_job_kind' => [
                    'type' => 'choice',
                    'choice' => 'editorial_content',
                    'probabilities' => [
                        'candidate_offering' => 0.02,
                        'job_curation' => 0.03,
                        'editorial_content' => 0.90,
                        'promotion' => 0.03,
                        'other' => 0.02,
                    ],
                    'confidence' => 0.91,
                ],
            ],
        ],
        'success' => true,
        'errors' => [],
        'messages' => [],
    ], $overrides);
}

beforeEach(function (): void {
    config([
        'triage.questions' => [
            'is_job' => 'Esta publicação anuncia uma vaga real e aberta a candidaturas?',
            'non_job_kind' => 'Se não anuncia vaga, qual é o tipo principal do conteúdo? Se anuncia, responda other.',
        ],
        'triage.non_job_kinds' => [
            'candidate_offering' => 'Pessoa se oferecendo para trabalhar / buscando oportunidade',
            'job_curation' => 'Lista ou agregação de vagas de terceiros, com link externo',
            'editorial_content' => 'Artigo, dica, tutorial, curso, evento ou novidade',
            'promotion' => 'Anúncio comercial, promocional ou de produto',
            'other' => 'Outro conteúdo que não anuncia vaga',
        ],
    ]);
});

it('sends the publication and the strict Clef questions and returns a verdict', function (): void {
    Http::fake(['*' => Http::response(clefResponse())]);

    $verdict = clefPrefilter()->classify(clefInput());

    expect($verdict)->toBeInstanceOf(PrefilterVerdict::class)
        ->and($verdict->isJobProbability)->toBe(0.87)
        ->and($verdict->nonJobKind)->toBe('editorial_content');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->url() === 'https://api.cloudflare.com/client/v4/accounts/account-123/ai/run/@cf/cloudflare/clef-flash'
            && $request->hasHeader('Authorization', 'Bearer token-abc')
            && $body['model'] === 'clef-flash'
            && $body['state']['post'] === [
                'title' => 'Desenvolvedor PHP',
                'description' => 'Vaga para desenvolvedor PHP com Laravel e testes.',
                'company' => 'Acme',
                'location' => 'Remoto',
            ]
            && ! array_key_exists('preferences', $body['state'])
            && $body['questions']['is_job']['type'] === 'noul'
            && $body['questions']['is_job']['instructions'] !== ''
            && $body['questions']['non_job_kind']['type'] === 'choice'
            && array_keys($body['questions']['non_job_kind']['criteria']) === [
                'candidate_offering',
                'job_curation',
                'editorial_content',
                'promotion',
                'other',
            ];
    });
});

it('rejects a response with a missing answer', function (): void {
    $response = clefResponse();
    unset($response['result']['answers']['non_job_kind']);

    Http::fake(['*' => Http::response($response)]);

    expect(fn () => clefPrefilter()->classify(clefInput()))
        ->toThrow(PrefilterException::class, 'non_job_kind');
});

it('rejects a response with the wrong answer type', function (): void {
    Http::fake(['*' => Http::response(clefResponse([
        'result' => ['answers' => ['is_job' => ['type' => 'choice', 'noul' => 0.9]]],
    ]))]);

    expect(fn () => clefPrefilter()->classify(clefInput()))
        ->toThrow(PrefilterException::class, 'is_job');
});

it('rejects a probability outside the zero to one range', function (): void {
    Http::fake(['*' => Http::response(clefResponse([
        'result' => ['answers' => ['is_job' => ['noul' => 1.4]]],
    ]))]);

    expect(fn () => clefPrefilter()->classify(clefInput()))
        ->toThrow(PrefilterException::class, 'is_job');
});

it('rejects a choice outside the configured categories', function (): void {
    Http::fake(['*' => Http::response(clefResponse([
        'result' => ['answers' => ['non_job_kind' => ['choice' => 'politics']]],
    ]))]);

    expect(fn () => clefPrefilter()->classify(clefInput()))
        ->toThrow(PrefilterException::class, 'non_job_kind');
});

it('rejects probabilities that do not sum to one', function (): void {
    Http::fake(['*' => Http::response(clefResponse([
        'result' => ['answers' => ['non_job_kind' => ['probabilities' => ['editorial_content' => 0.5, 'other' => 0.3]]]],
    ]))]);

    expect(fn () => clefPrefilter()->classify(clefInput()))
        ->toThrow(PrefilterException::class, 'não somam 1');
});

it('rejects probabilities with a category outside the configured categories', function (): void {
    Http::fake(['*' => Http::response(clefResponse([
        'result' => ['answers' => ['non_job_kind' => ['probabilities' => ['editorial_content' => 0.5, 'other' => 0.4, 'politics' => 0.1]]]],
    ]))]);

    expect(fn () => clefPrefilter()->classify(clefInput()))
        ->toThrow(PrefilterException::class, 'probabilidades inválidas');
});

it('rejects a response with an invalid confidence', function (): void {
    Http::fake(['*' => Http::response(clefResponse([
        'result' => ['answers' => ['non_job_kind' => ['confidence' => 1.2]]],
    ]))]);

    expect(fn () => clefPrefilter()->classify(clefInput()))
        ->toThrow(PrefilterException::class, 'confiança inválida');
});

it('rejects an unsuccessful API envelope', function (): void {
    Http::fake(['*' => Http::response(clefResponse(['success' => false]))]);

    expect(fn () => clefPrefilter()->classify(clefInput()))
        ->toThrow(PrefilterException::class, 'success');
});

it('rejects an HTTP error response', function (): void {
    Http::fake(['*' => Http::response('boom', 500)]);

    expect(fn () => clefPrefilter()->classify(clefInput()))
        ->toThrow(PrefilterException::class, 'HTTP 500');
});

it('wraps a connection failure as a prefilter exception', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    expect(fn () => clefPrefilter()->classify(clefInput()))
        ->toThrow(PrefilterException::class, 'timed out');
});

it('fails open per capture when one pool request fails', function (): void {
    Http::fakeSequence()
        ->push(clefResponse())
        ->push('boom', 500);

    $results = clefPrefilter()->classifyMany([clefInput(), clefInput()]);

    expect($results[0])->toBeInstanceOf(PrefilterVerdict::class)
        ->and($results[0]->isJobProbability)->toBe(0.87)
        ->and($results[1])->toBeInstanceOf(PrefilterException::class)
        ->and($results[1]->getMessage())->toContain('HTTP 500');
});
