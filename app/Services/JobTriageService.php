<?php

namespace App\Services;

use App\Agents\JobTriageAgent;
use App\DTO\JobTriageResult;
use App\Enums\JobCaptureTriageStatus;
use App\Jobs\TriageJobCaptures;
use App\Models\JobCapture;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Orquestra a triagem em lote de capturas: monta o payload (nunca
 * persistido), chama o agente uma vez por lote e persiste o resultado
 * por captura, associado por capture_id (não por posição).
 *
 * Falha total do lote → todas as capturas ficam failed (recuperável via
 * re-envio). Falha parcial → a captura sem decisão válida fica failed;
 * nunca fica completed sem resultado interpretável.
 */
final class JobTriageService
{
    public const MAX_BATCH_SIZE = 50;

    public function __construct(private readonly JobTriageAgent $agent) {}

    /**
     * Inicia a triagem em lote das capturas elegíveis da seleção: marca
     * como processing e despacha o job. Rejeita lotes acima do limite.
     *
     * @param  Collection<int, JobCapture>  $captures
     * @return Collection<int, JobCapture> capturas elegíveis enviadas
     *
     * @throws InvalidArgumentException quando a seleção excede MAX_BATCH_SIZE
     */
    public function startBatch(Collection $captures, User $user): Collection
    {
        $eligible = $captures->filter(fn (JobCapture $c): bool => $c->job_posting_id === null
            && in_array($c->triage_status, [JobCaptureTriageStatus::Pending, JobCaptureTriageStatus::Failed], true));

        if ($eligible->count() > self::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException('Selecione no máximo '.self::MAX_BATCH_SIZE.' capturas por lote.');
        }

        if ($eligible->isEmpty()) {
            return $eligible;
        }

        $ids = $eligible->pluck('id')->all();

        JobCapture::query()->whereIn('id', $ids)->update([
            'triage_status' => JobCaptureTriageStatus::Processing,
            'triage_error' => null,
        ]);

        TriageJobCaptures::dispatch($ids, (int) $user->id);

        return $eligible;
    }

    /**
     * @param  Collection<int, JobCapture>  $captures
     */
    public function triageBatch(Collection $captures, ?User $user = null): void
    {
        $started = microtime(true);
        $ids = $captures->pluck('id')->all();
        $inputChars = 0;
        $usage = null;

        try {
            $criteria = $user?->screeningPreference;

            $payload = [
                'captures' => $captures->map(fn (JobCapture $c) => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'description' => $c->description,
                    'company' => $c->company,
                    'location' => $c->location,
                ])->values()->all(),
                'preferences' => $criteria?->preferences ?? [],
                'rejections' => $criteria?->rejections ?? [],
            ];

            $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $inputChars = mb_strlen($jsonPayload);

            $response = $this->agent->prompt($jsonPayload);
            $usage = $response->usage ?? null;

            $data = json_decode($response->text, true);

            if (! is_array($data) || ! isset($data['results']) || ! is_array($data['results'])) {
                throw new InvalidArgumentException('A resposta da IA não contém um array "results" válido.');
            }

            /** @var array<int, JobTriageResult> $decisions */
            $decisions = [];

            foreach ($data['results'] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                try {
                    $decision = JobTriageResult::fromStructured($entry);
                    $decisions[$decision->captureId] = $decision;
                } catch (InvalidArgumentException $e) {
                    Log::channel('ai')->warning('Job triage entry ignored.', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            foreach ($captures as $capture) {
                $decision = $decisions[$capture->id] ?? null;

                if ($decision === null) {
                    $capture->update([
                        'triage_status' => JobCaptureTriageStatus::Failed,
                        'triage_result' => null,
                        'triage_reason' => null,
                        'triage_error' => 'A resposta da IA não contém um resultado válido para esta captura.',
                    ]);

                    continue;
                }

                $capture->update([
                    'triage_status' => JobCaptureTriageStatus::Completed,
                    'triage_result' => $decision->result,
                    'triage_reason' => $decision->reason,
                    'triage_error' => null,
                ]);
            }

            Log::channel('ai')->info('Job captures triaged.', array_merge([
                'job_capture_ids' => $ids,
                'operation' => 'job_triage',
                'provider' => $this->agent->provider(),
                'model' => $this->agent->model(),
                'input_chars' => $inputChars,
                'success' => true,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ], $this->extractUsageData($usage)));
        } catch (Throwable $e) {
            foreach ($captures as $capture) {
                $capture->update([
                    'triage_status' => JobCaptureTriageStatus::Failed,
                    'triage_result' => null,
                    'triage_reason' => null,
                    'triage_error' => $e->getMessage(),
                ]);
            }

            Log::channel('ai')->error('Job captures triage failed.', array_merge([
                'job_capture_ids' => $ids,
                'operation' => 'job_triage',
                'provider' => $this->agent->provider(),
                'model' => $this->agent->model(),
                'input_chars' => $inputChars,
                'success' => false,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => $e->getMessage(),
            ], $this->extractUsageData($usage)));
        }
    }

    /**
     * @return array<string, int>
     */
    private function extractUsageData(mixed $usage): array
    {
        try {
            if ($usage === null) {
                return [
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'cache_write_input_tokens' => 0,
                    'cache_read_input_tokens' => 0,
                    'reasoning_tokens' => 0,
                ];
            }

            return [
                'prompt_tokens' => (int) ($usage->promptTokens ?? $usage->prompt_tokens ?? 0),
                'completion_tokens' => (int) ($usage->completionTokens ?? $usage->completion_tokens ?? 0),
                'cache_write_input_tokens' => (int) ($usage->cacheWriteInputTokens ?? $usage->cache_write_input_tokens ?? 0),
                'cache_read_input_tokens' => (int) ($usage->cacheReadInputTokens ?? $usage->cache_read_input_tokens ?? 0),
                'reasoning_tokens' => (int) ($usage->reasoningTokens ?? $usage->reasoning_tokens ?? 0),
            ];
        } catch (Throwable) {
            return [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'cache_write_input_tokens' => 0,
                'cache_read_input_tokens' => 0,
                'reasoning_tokens' => 0,
            ];
        }
    }
}
