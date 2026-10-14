<?php

namespace App\Services;

use App\Agents\ResumeTranslationAgent;
use App\DTO\ResumeTranslationResult;
use App\Models\Resume;
use App\Models\User;
use App\Support\Ai\AiRequestGate;
use App\Support\Ai\AiUsageHelper;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Tradução do currículo canônico (PT-BR) para a versão EN.
 *
 * O texto EN é derivado do PT-BR e editável à mão; guarda-se o fingerprint do
 * PT-BR de origem para detectar drift. A integridade é checada pelo mesmo
 * analisador de conceitos da adequação: o texto traduzido não pode introduzir
 * conceitos ausentes do original.
 */
class ResumeTranslationService
{
    public function __construct(
        private readonly ResumeTranslationAgent $agent,
        private readonly ResumeAnalyzer $resumeAnalyzer,
        private readonly ResumeService $resumeService,
        private readonly ?AiRequestGate $gate = null,
    ) {}

    /**
     * Execute a translation using the immutable payload captured when the
     * background operation was queued.
     *
     * @param  array<string, mixed>  $payload
     */
    public function translateFromSnapshot(
        User $user,
        array $payload,
        string $fingerprint,
        ?Closure $persistWithinAttempt = null,
    ): Resume {
        $original = $payload['resume'] ?? null;

        if (! is_string($original) || blank($original)) {
            throw new LogicException('O snapshot da tradução não contém currículo.');
        }

        $store = config('cache.lock_store', config('cache.default', 'file'));
        $stores = config('cache.stores', []);

        if (! isset($stores[$store])) {
            $store = 'file';
        }

        $opLock = Cache::store($store)->lock("translation:user:{$user->id}", 130);

        if (! $opLock->get()) {
            throw new RuntimeException('Tradução já em andamento para este usuário. Aguarde a conclusão.');
        }

        $started = microtime(true);
        $usage = null;

        try {
            $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

            $gate = $this->gate ?? new AiRequestGate;
            $response = $gate->run(
                $this->agent->provider(),
                $this->agent->model(),
                fn () => $this->agent->prompt($jsonPayload)
            );
            $usage = $response->usage ?? null;

            $data = json_decode($response->text, true);

            if (! is_array($data)) {
                throw new InvalidArgumentException('A resposta da IA não é um JSON válido.');
            }

            $result = ResumeTranslationResult::fromStructured($data);

            $this->assertIntegrity($result, $original);

            $persist = fn (): Resume => $this->resumeService->saveTranslation(
                $user,
                $result->translatedResume,
                $fingerprint,
            );

            $resume = $persistWithinAttempt !== null
                ? $persistWithinAttempt($persist)
                : $persist();

            Log::channel('ai')->info('Resume translation generated.', array_merge([
                'user_id' => $user->id,
                'operation' => 'resume_translation',
                'provider' => $this->agent->provider(),
                'model' => $this->agent->model(),
                'input_chars' => mb_strlen($jsonPayload),
                'success' => true,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ], AiUsageHelper::extract($usage)));

            return $resume;
        } catch (Throwable $e) {
            Log::channel('ai')->error('Resume translation failed.', array_merge([
                'user_id' => $user->id,
                'operation' => 'resume_translation',
                'provider' => $this->agent->provider(),
                'model' => $this->agent->model(),
                'input_chars' => mb_strlen((string) json_encode($payload, JSON_UNESCAPED_UNICODE)),
                'success' => false,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => $e->getMessage(),
            ], AiUsageHelper::extract($usage)));

            throw new RuntimeException('Falha na tradução do currículo: '.$e->getMessage(), 0, $e);
        } finally {
            try {
                $opLock->release();
            } catch (Throwable) {
            }
        }
    }

    private function assertIntegrity(ResumeTranslationResult $result, string $original): void
    {
        $invented = $this->resumeAnalyzer->inventedConcepts($original, $result->translatedResume);

        if ($invented !== []) {
            throw new InvalidArgumentException(sprintf(
                'Integridade: a versão em inglês introduz conceitos ausentes do currículo original: %s.',
                implode(', ', $invented),
            ));
        }
    }
}
