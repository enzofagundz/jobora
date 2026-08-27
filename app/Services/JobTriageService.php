<?php

namespace App\Services;

use App\Agents\JobTriageAgent;
use App\DTO\JobTriageResult;
use App\Enums\JobCaptureTriageStatus;
use App\Jobs\TriageJobCaptures;
use App\Models\JobCapture;
use App\Models\User;
use App\Support\Ai\AiRequestGate;
use App\Support\Ai\AiUsageHelper;
use App\Support\Ai\TransientAiClassifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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

    public function __construct(
        private readonly JobTriageAgent $agent,
        private readonly ?AiRequestGate $gate = null,
    ) {}

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
        // Filtrar seleção por usuário — nunca processar coleção estrangeira.
        $captures = $captures->filter(fn (JobCapture $c): bool => $c->user_id === $user->id);

        $eligible = $captures->filter(fn (JobCapture $c): bool => $c->job_posting_id === null
            && in_array($c->triage_status, [JobCaptureTriageStatus::Pending, JobCaptureTriageStatus::Failed], true));

        if ($eligible->count() > self::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException('Selecione no máximo '.self::MAX_BATCH_SIZE.' capturas por lote.');
        }

        if ($eligible->isEmpty()) {
            return $eligible;
        }

        $store = config('cache.lock_store', config('cache.default', 'file'));
        $stores = config('cache.stores', []);

        if (! isset($stores[$store])) {
            $store = 'file';
        }

        $lock = Cache::store($store)->lock("triage:start:{$user->id}", 10);

        if (! $lock->get()) {
            throw new \RuntimeException('Triagem em andamento para este usuário. Tente novamente em instantes.');
        }

        try {
            // Re-validate inside lock and claim only still-eligible rows
            $ids = $eligible->pluck('id')->all();

            $claimed = $user->jobCaptures()
                ->whereIn('id', $ids)
                ->whereNull('job_posting_id')
                ->whereIn('triage_status', [JobCaptureTriageStatus::Pending, JobCaptureTriageStatus::Failed])
                ->pluck('id')
                ->all();

            if ($claimed === []) {
                return collect();
            }

            $user->jobCaptures()->whereIn('id', $claimed)->update([
                'triage_status' => JobCaptureTriageStatus::Processing,
                'triage_error' => null,
            ]);

            TriageJobCaptures::dispatch($claimed, (int) $user->id);

            return $eligible->whereIn('id', $claimed)->values();
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
            }
        }
    }

    /**
     * @param  Collection<int, JobCapture>  $captures
     */
    public function triageBatch(Collection $captures, User $user): void
    {
        // Filtragem por proprietário — nunca processar captura estrangeira.
        $captures = $captures->filter(fn (JobCapture $c): bool => $c->user_id === $user->id);

        if ($captures->isEmpty()) {
            return;
        }

        // Avoid duplicate AI calls: skip captures already completed (e.g., overlapping batch after first succeeded)
        $fresh = $user->jobCaptures()
            ->whereIn('id', $captures->pluck('id')->all())
            ->where('triage_status', '!=', JobCaptureTriageStatus::Completed)
            ->pluck('id')
            ->all();

        if ($fresh === []) {
            return;
        }

        $captures = $captures->whereIn('id', $fresh)->values();

        $started = microtime(true);
        $ids = $captures->pluck('id')->all();
        $inputChars = 0;
        $usage = null;

        try {
            $criteria = $user->screeningPreference;

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

            $gate = $this->gate ?? new AiRequestGate;
            $response = $gate->run(
                $this->agent->provider(),
                $this->agent->model(),
                fn () => $this->agent->prompt($jsonPayload)
            );
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
            ], AiUsageHelper::extract($usage)));
        } catch (Throwable $e) {
            if (TransientAiClassifier::isTransient($e)) {
                $meta = TransientAiClassifier::metadata($e);

                Log::channel('ai')->warning('Job captures triage deferred due to transient error.', array_merge([
                    'job_capture_ids' => $ids,
                    'operation' => 'job_triage',
                    'provider' => $this->agent->provider(),
                    'model' => $this->agent->model(),
                    'input_chars' => $inputChars,
                    'success' => false,
                    'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                    'error' => $e->getMessage(),
                    'retry_after' => $meta['retry_after'],
                    'status' => $meta['status'],
                ], AiUsageHelper::extract($usage)));

                throw $e;
            }

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
            ], AiUsageHelper::extract($usage)));
        }
    }
}
