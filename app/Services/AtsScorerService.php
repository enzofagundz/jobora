<?php

namespace App\Services;

use App\Agents\AtsScorerAgent;
use App\Models\JobPosting;
use App\Models\User;
use App\Support\Ai\AiRequestGate;
use App\Support\Ai\AiUsageHelper;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

class AtsScorerService
{
    public function __construct(
        private readonly AtsScorerAgent $agent,
        private readonly ResumeService $resumeService,
        private readonly ?AiRequestGate $gate = null,
    ) {}

    /**
     * @return array{score: int, rationale: string}
     */
    public function score(User $user, JobPosting $posting): array
    {
        Gate::forUser($user)->authorize('useAtsOptimization');
        Gate::forUser($user)->authorize('view', $posting);

        if ($posting->user_id !== $user->id) {
            throw new \LogicException('Vaga não pertence ao usuário.');
        }

        $adaptation = $posting->resumeAdaptation()->first();

        if (! $adaptation) {
            throw new \LogicException('Gere a adaptação do currículo antes de simular a nota.');
        }

        $resume = $adaptation->adapted_content;

        // Include hidden prompt in scoring if optimized (simulates ATS extraction)
        if ($adaptation->is_ats_optimized && filled($adaptation->ats_prompt)) {
            $resume .= "\n\n".$adaptation->ats_prompt;
        }

        $payload = [
            'resume' => ResumeService::plainText($resume),
            'job_description' => ResumeService::plainText($posting->description),
            'job_role' => $posting->role,
            'job_company' => $posting->company,
            'items' => $posting->items->map(fn ($item): array => [
                'name' => $item->name,
                'category' => $item->category->value,
            ])->values()->all(),
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
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
    public function scoreComparison(User $user, JobPosting $posting): array
    {
        Gate::forUser($user)->authorize('useAtsOptimization');
        Gate::forUser($user)->authorize('view', $posting);

        $adaptation = $posting->resumeAdaptation()->first();

        if (! $adaptation) {
            throw new \LogicException('Gere a adaptação antes de comparar.');
        }

        $originalPrompt = $adaptation->ats_prompt;
        $originalFlag = $adaptation->is_ats_optimized;

        // Score without
        $adaptation->forceFill(['is_ats_optimized' => false])->save();
        $without = $this->score($user, $posting->fresh()->load(['items', 'resumeAdaptation']));

        // Score with (if had prompt, restore)
        if ($originalFlag && filled($originalPrompt)) {
            $adaptation->forceFill(['is_ats_optimized' => true, 'ats_prompt' => $originalPrompt])->save();
            $with = $this->score($user, $posting->fresh()->load(['items', 'resumeAdaptation']));
        } else {
            // Build temporary prompt for comparison if not yet optimized
            $builder = app(AtsPromptBuilder::class);
            $resumeContent = $this->resumeService->content($user) ?? '';
            $prompt = $builder->build($posting->fresh()->load('items'), ResumeService::plainText($resumeContent));
            $adaptation->forceFill(['is_ats_optimized' => true, 'ats_prompt' => $prompt !== '' ? $prompt : null])->save();
            $with = $this->score($user, $posting->fresh()->load(['items', 'resumeAdaptation']));

            // Restore original
            $adaptation->forceFill(['is_ats_optimized' => $originalFlag, 'ats_prompt' => $originalPrompt])->save();
        }

        // Ensure original state restored
        if ($adaptation->is_ats_optimized !== $originalFlag || $adaptation->ats_prompt !== $originalPrompt) {
            $adaptation->forceFill(['is_ats_optimized' => $originalFlag, 'ats_prompt' => $originalPrompt])->save();
        }

        return [
            'without' => $without,
            'with' => $with,
        ];
    }
}
