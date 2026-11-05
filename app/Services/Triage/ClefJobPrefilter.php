<?php

namespace App\Services\Triage;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cliente HTTP do pré-filtro Clef Flash (Cloudflare Workers AI).
 *
 * A Cloudflare é usada como provedor de IA fora do laravel/ai nesta etapa
 * (ADR 0002). O envelope e cada resposta são validados de forma estrita;
 * qualquer desvio lança PrefilterException e a captura segue para o LLM.
 */
final class ClefJobPrefilter implements JobPrefilter
{
    private const PROBABILITY_TOLERANCE = 0.01;

    public function __construct(
        private readonly string $accountId,
        private readonly string $apiToken,
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly int $concurrency = 5,
    ) {}

    public function classify(PrefilterInput $input): PrefilterVerdict
    {
        $result = $this->classifyMany([$input])[0] ?? null;

        if ($result instanceof PrefilterException) {
            throw $result;
        }

        if (! $result instanceof PrefilterVerdict) {
            throw new PrefilterException('O pré-filtro não devolveu um veredito.');
        }

        return $result;
    }

    public function classifyMany(array $inputs): array
    {
        if ($inputs === []) {
            return [];
        }

        if ($this->accountId === '' || $this->apiToken === '') {
            return array_map(
                fn (): PrefilterException => new PrefilterException('Cloudflare Workers AI não está configurado.'),
                $inputs,
            );
        }

        $responses = Http::pool(function (Pool $pool) use ($inputs): void {
            foreach ($inputs as $input) {
                $pool->withToken($this->apiToken)
                    ->timeout($this->timeout)
                    ->acceptJson()
                    ->post($this->endpoint(), $this->payload($input));
            }
        }, $this->concurrency);

        $results = [];

        foreach (array_keys($inputs) as $index) {
            $results[$index] = $this->verdict($responses[$index] ?? null);
        }

        return $results;
    }

    public function configuredModel(): string
    {
        return $this->model;
    }

    public function classifierIdentifier(): string
    {
        $configuration = [
            'provider' => 'cloudflare-workers-ai',
            'account_id' => $this->accountId,
            'base_url' => rtrim($this->baseUrl, '/'),
            'model' => $this->model,
            'questions' => (array) config('triage.questions', []),
            'non_job_kinds' => (array) config('triage.non_job_kinds', []),
            'min_job_probability' => (float) config('triage.prefilter.min_job_probability', 0.50),
        ];

        return 'clef-config-sha256:'.hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function verdict(mixed $response): PrefilterVerdict|PrefilterException
    {
        try {
            if ($response instanceof ConnectionException) {
                throw new PrefilterException('Falha de conexão com o Workers AI: '.$response->getMessage(), previous: $response);
            }

            if ($response instanceof Throwable) {
                throw new PrefilterException('Falha ao consultar o Workers AI: '.$response->getMessage(), previous: $response);
            }

            if (! $response instanceof Response) {
                throw new PrefilterException('O Workers AI não devolveu uma resposta HTTP.');
            }

            if ($response->failed()) {
                throw new PrefilterException("Cloudflare Workers AI retornou HTTP {$response->status()}.");
            }

            $answers = $this->answers($response);
            $nonJobKind = $this->choice($answers, 'non_job_kind');

            return new PrefilterVerdict(
                isJobProbability: $this->noulProbability($answers, 'is_job'),
                nonJobKind: $nonJobKind['choice'],
                confidence: $nonJobKind['confidence'],
            );
        } catch (PrefilterException $exception) {
            return $exception;
        } catch (Throwable $exception) {
            return new PrefilterException('Falha ao interpretar a resposta do Workers AI: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PrefilterException
     */
    private function answers(Response $response): array
    {
        $json = $response->json();

        if (! is_array($json) || ($json['success'] ?? false) !== true) {
            throw new PrefilterException('Cloudflare Workers AI retornou uma resposta sem success=true.');
        }

        foreach (['errors', 'messages'] as $field) {
            if (! is_array($json[$field] ?? null) || $json[$field] !== []) {
                throw new PrefilterException("Cloudflare Workers AI retornou um envelope inválido em \"{$field}\".");
            }
        }

        $result = $json['result'] ?? null;

        if (! is_array($result)) {
            throw new PrefilterException('A resposta do Workers AI não contém "result".');
        }

        $answers = $result['answers'] ?? null;

        if (! is_array($answers)) {
            throw new PrefilterException('A resposta do Workers AI não contém "answers".');
        }

        if (! $this->hasExactKeys($answers, ['is_job', 'non_job_kind'])) {
            $missing = array_values(array_diff(['is_job', 'non_job_kind'], array_keys($answers)));
            $unknown = array_values(array_diff(array_keys($answers), ['is_job', 'non_job_kind']));

            if ($missing !== []) {
                throw new PrefilterException('A resposta do Workers AI não contém a resposta "'.$missing[0].'".');
            }

            throw new PrefilterException('A resposta do Workers AI contém uma resposta desconhecida: "'.$unknown[0].'".');
        }

        $this->assertType($answers, 'is_job', 'noul');
        $this->assertType($answers, 'non_job_kind', 'choice');

        return $answers;
    }

    /**
     * @param  array<string, mixed>  $answers
     *
     * @throws PrefilterException
     */
    private function assertType(array $answers, string $id, string $type): void
    {
        $answer = $answers[$id] ?? null;
        $keys = $type === 'noul' ? ['type', 'noul'] : ['type', 'choice', 'probabilities', 'confidence'];

        if (! is_array($answer) || ! $this->hasExactKeys($answer, $keys) || ($answer['type'] ?? null) !== $type) {
            throw new PrefilterException("A resposta do pré-filtro não contém uma resposta válida para \"{$id}\".");
        }
    }

    /**
     * @param  array<string, mixed>  $answers
     *
     * @throws PrefilterException
     */
    private function noulProbability(array $answers, string $id): float
    {
        $answer = $answers[$id] ?? null;
        $probability = is_array($answer) ? ($answer['noul'] ?? null) : null;

        if ((! is_int($probability) && ! is_float($probability)) || $probability < 0 || $probability > 1) {
            throw new PrefilterException("A resposta do pré-filtro tem uma probabilidade inválida para \"{$id}\".");
        }

        return (float) $probability;
    }

    /**
     * @param  array<string, mixed>  $answers
     *
     * @throws PrefilterException
     */
    private function choice(array $answers, string $id): array
    {
        $answer = $answers[$id] ?? null;

        if (! is_array($answer) || ! $this->hasExactKeys($answer, ['type', 'choice', 'probabilities', 'confidence'])) {
            throw new PrefilterException("A resposta do pré-filtro tem uma escolha inválida para \"{$id}\".");
        }

        $allowed = $this->allowedKinds();
        $chosen = $answer['choice'] ?? null;

        if (! is_string($chosen) || ! in_array($chosen, $allowed, true)) {
            throw new PrefilterException("A resposta do pré-filtro tem uma escolha inválida para \"{$id}\".");
        }

        $this->assertProbabilities($answer['probabilities'] ?? null, $allowed, $id);
        $this->assertConfidence($answer['confidence'] ?? null, $id);

        return ['choice' => $chosen, 'confidence' => (float) $answer['confidence']];
    }

    /**
     * @param  list<string>  $allowed
     *
     * @throws PrefilterException
     */
    private function assertProbabilities(mixed $probabilities, array $allowed, string $id): void
    {
        if (! is_array($probabilities) || $probabilities === []) {
            throw new PrefilterException("A resposta do pré-filtro não contém as probabilidades de \"{$id}\".");
        }

        $sum = 0.0;

        foreach ($probabilities as $kind => $probability) {
            if (! in_array((string) $kind, $allowed, true)
                || (! is_int($probability) && ! is_float($probability))
                || $probability < 0
                || $probability > 1) {
                throw new PrefilterException("A resposta do pré-filtro tem probabilidades inválidas para \"{$id}\".");
            }

            $sum += (float) $probability;
        }

        if (array_diff($allowed, array_map(strval(...), array_keys($probabilities))) !== []
            || abs($sum - 1.0) > self::PROBABILITY_TOLERANCE) {
            throw new PrefilterException("As probabilidades de \"{$id}\" não somam 1.");
        }
    }

    /**
     * @throws PrefilterException
     */
    private function assertConfidence(mixed $confidence, string $id): void
    {
        if ((! is_int($confidence) && ! is_float($confidence)) || $confidence < 0 || $confidence > 1) {
            throw new PrefilterException("A resposta do pré-filtro tem uma confiança inválida para \"{$id}\".");
        }
    }

    /**
     * @return list<string>
     */
    private function allowedKinds(): array
    {
        return array_map(strval(...), array_keys((array) config('triage.non_job_kinds', [])));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PrefilterInput $input): array
    {
        return [
            'model' => Str::afterLast($this->model, '/'),
            'state' => [
                'post' => [
                    'title' => $input->title,
                    'description' => $input->description,
                    'company' => $input->company,
                    'location' => $input->location,
                ],
            ],
            'questions' => [
                'is_job' => [
                    'type' => 'noul',
                    'instructions' => (string) config('triage.questions.is_job', ''),
                ],
                'non_job_kind' => [
                    'type' => 'choice',
                    'instructions' => (string) config('triage.questions.non_job_kind', ''),
                    'criteria' => (array) config('triage.non_job_kinds', []),
                ],
            ],
        ];
    }

    private function endpoint(): string
    {
        return sprintf(
            '%s/accounts/%s/ai/run/%s',
            rtrim($this->baseUrl, '/'),
            $this->accountId,
            $this->model,
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $expected
     */
    private function hasExactKeys(array $values, array $expected): bool
    {
        return array_diff(array_keys($values), $expected) === []
            && array_diff($expected, array_keys($values)) === [];
    }
}
