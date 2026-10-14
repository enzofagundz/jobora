<?php

namespace App\Services;

use App\Agents\AtsScorerAgent;
use App\Enums\ResumeLanguage;
use App\Models\JobPosting;
use App\Models\ResumeAdaptation;
use App\Models\User;
use App\Support\Ai\AiRequestGate;
use App\Support\Ai\AiUsageHelper;
use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

class AtsScorerService
{
    public function __construct(
        private readonly AtsScorerAgent $agent,
        private readonly ResumeService $resumeService,
        private readonly AiInputSnapshotService $snapshots,
        private readonly ?AiRequestGate $gate = null,
    ) {}

    /**
     * @return array{score: int, rationale: string}
     */
    public function score(User $user, JobPosting $posting, ResumeLanguage $language): array
    {
        $this->assertAccess($user, $posting);
        $snapshot = $this->snapshots->atsScore($posting->fresh()->load('items'), $language);

        return $this->scorePayload($user, $posting, $snapshot['payload'], $snapshot['fingerprint']);
    }

    /**
     * Score the immutable payload captured when the operation was queued.
     *
     * @param  array<string, mixed>  $payload
     * @return array{score: int, rationale: string}
     */
    public function scoreFromSnapshot(
        User $user,
        JobPosting $posting,
        array $payload,
        string $fingerprint,
        ?Closure $persistWithinAttempt = null,
    ): array {
        $this->assertAccess($user, $posting);

        if (! is_string($payload['resume'] ?? null) || blank($payload['resume'])) {
            throw new \LogicException('O snapshot da simulação ATS não contém currículo adaptado.');
        }

        if (! is_array($payload['items'] ?? null)) {
            throw new \LogicException('O snapshot da simulação ATS não contém itens da vaga.');
        }

        return $this->scorePayload($user, $posting, $payload, $fingerprint, $persistWithinAttempt);
    }

    private function assertAccess(User $user, JobPosting $posting): void
    {
        Gate::forUser($user)->authorize('useAtsOptimization');
        Gate::forUser($user)->authorize('view', $posting);

        if ($posting->user_id !== $user->id) {
            throw new \LogicException('Vaga não pertence ao usuário.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{score: int, rationale: string}
     */
    private function scorePayload(
        User $user,
        JobPosting $posting,
        array $payload,
        string $fingerprint,
        ?Closure $persistWithinAttempt = null,
        bool $persistResult = true,
    ): array {

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $started = microtime(true);
        $usage = null;

        try {
            $gate = $this->gate ?? new AiRequestGate;
            $response = $gate->run(
                $this->agent->provider(),
                $this->agent->model(),
                fn () => $this->agent->prompt($jsonPayload)
            );
            $usage = $response->usage ?? null;

            $data = json_decode($response->text, true);

            if (! is_array($data)) {
                throw new \InvalidArgumentException('A resposta da IA não é um JSON válido.');
            }

            $score = $data['score'] ?? null;
            $rationale = $data['rationale'] ?? '';

            if (! is_int($score) || $score < 0 || $score > 10) {
                // Allow numeric string that is integer
                if (is_numeric($score)) {
                    $score = (int) $score;
                }

                if (! is_int($score) || $score < 0 || $score > 10) {
                    throw new \InvalidArgumentException('Campo "score" deve ser inteiro entre 0 e 10.');
                }
            }

            if (! is_string($rationale)) {
                $rationale = (string) $rationale;
            }

            $rationale = trim(mb_substr($rationale, 0, 200));

            if ($persistResult) {
                $language = ResumeLanguage::tryFrom((string) ($payload['language'] ?? '')) ?? ResumeLanguage::PtBr;
                $adaptation = $posting->resumeAdaptation($language)->first();

                if (! $adaptation) {
                    throw new \LogicException('Gere a adaptação do currículo antes de simular a nota.');
                }

                $persist = function () use ($adaptation, $fingerprint, $rationale, $score): void {
                    $adaptation->forceFill([
                        'ats_score' => $score,
                        'ats_rationale' => $rationale,
                        'ats_source_fingerprint' => $fingerprint,
                    ])->save();
                };

                if ($persistWithinAttempt !== null) {
                    $persistWithinAttempt($persist);
                } else {
                    $persist();
                }
            }

            Log::channel('ai')->info('ATS score simulated.', array_merge([
                'job_posting_id' => $posting->id,
                'operation' => 'ats_scorer',
                'score' => $score,
                'success' => true,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ], AiUsageHelper::extract($usage)));

            return [
                'score' => $score,
                'rationale' => $rationale,
            ];
        } catch (Throwable $e) {
            Log::channel('ai')->error('ATS scoring failed.', [
                'job_posting_id' => $posting->id,
                'operation' => 'ats_scorer',
                'success' => false,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Falha na simulação de nota ATS: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Score both without and with optimization for comparison.
     *
     * @return array{without: array{score:int, rationale:string}, with: array{score:int, rationale:string}}
     */
    public function scoreComparison(User $user, JobPosting $posting, ResumeLanguage $language): array
    {
        Gate::forUser($user)->authorize('useAtsOptimization');
        Gate::forUser($user)->authorize('view', $posting);

        $posting = $posting->fresh()->load('items');
        $adaptation = $posting->resumeAdaptation($language)->first();

        if (! $adaptation) {
            throw new \LogicException('Gere a adaptação antes de comparar.');
        }

        $prompt = $adaptation->is_ats_optimized && filled($adaptation->ats_prompt)
            ? $adaptation->ats_prompt
            : app(AtsPromptBuilder::class)->build(
                $posting,
                ResumeService::plainText($this->resumeService->content($user, $language) ?? ''),
                $language,
            );

        return [
            'without' => $this->scoreVariant($user, $posting, $adaptation, false, null, $language),
            'with' => $this->scoreVariant($user, $posting, $adaptation, true, $prompt !== '' ? $prompt : null, $language),
        ];
    }

    /**
     * @return array{score: int, rationale: string}
     */
    private function scoreVariant(
        User $user,
        JobPosting $posting,
        ResumeAdaptation $adaptation,
        bool $optimized,
        ?string $prompt,
        ResumeLanguage $language,
    ): array {
        $variantAdaptation = clone $adaptation;
        $variantAdaptation->forceFill([
            'is_ats_optimized' => $optimized,
            'ats_prompt' => $optimized ? $prompt : null,
        ]);

        $snapshot = $this->snapshots->atsScore($posting, $language, $variantAdaptation);

        return $this->scorePayload(
            $user,
            $posting,
            $snapshot['payload'],
            $snapshot['fingerprint'],
            persistResult: false,
        );
    }
}
