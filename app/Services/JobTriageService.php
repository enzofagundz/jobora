<?php

namespace App\Services;

use App\Agents\JobTriageAgent;
use App\DTO\JobTriageResult;
use App\Enums\JobCaptureTriageStatus;
use App\Models\JobCapture;
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
     * @param  Collection<int, JobCapture>  $captures
     */
    public function triageBatch(Collection $captures): void
    {
        $started = microtime(true);
        $ids = $captures->pluck('id')->all();

        try {
            $payload = [
                'captures' => $captures->map(fn (JobCapture $c) => [
                    'id' => $c->id,
                    'source' => $c->source,
                    'external_id' => $c->external_id,
                    'title' => $c->title,
                    'description' => $c->description,
                    'company' => $c->company,
                    'location' => $c->location,
                    'url' => $c->url,
                ])->values()->all(),
                'preferences' => config('triage.preferences', []),
                'rejections' => config('triage.rejections', []),
            ];

            $response = $this->agent->prompt(json_encode($payload, JSON_UNESCAPED_UNICODE));

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

            Log::channel('ai')->info('Job captures triaged.', [
                'job_capture_ids' => $ids,
                'model' => $this->agent->model(),
                'success' => true,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        } catch (Throwable $e) {
            foreach ($captures as $capture) {
                $capture->update([
                    'triage_status' => JobCaptureTriageStatus::Failed,
                    'triage_result' => null,
                    'triage_reason' => null,
                    'triage_error' => $e->getMessage(),
                ]);
            }

            Log::channel('ai')->error('Job captures triage failed.', [
                'job_capture_ids' => $ids,
                'model' => $this->agent->model(),
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
