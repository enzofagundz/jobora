<?php

namespace App\Services;

use App\Agents\ResumeAdaptationAgent;
use App\DTO\ResumeAdaptationResult;
use App\Enums\JobPostingStatus;
use App\Enums\ResumeLanguage;
use App\Models\JobPosting;
use App\Models\ResumeAdaptation;
use App\Models\User;
use App\Support\Ai\AiRequestGate;
use App\Support\Ai\AiUsageHelper;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

class ResumeAdaptationService
{
    public function __construct(
        private readonly ResumeAdaptationAgent $agent,
        private readonly ConceptNormalizer $normalizer,
        private readonly ResumeAnalyzer $resumeAnalyzer,
        private readonly ResumeService $resumeService,
        private readonly AiInputSnapshotService $snapshots,
        private readonly ?AiRequestGate $gate = null,
        private readonly ?AtsPromptBuilder $atsBuilder = null,
    ) {}

    public function adapt(User $user, JobPosting $posting, ResumeLanguage $language = ResumeLanguage::PtBr): ResumeAdaptation
    {
        Gate::forUser($user)->authorize('update', $posting);

        if ($posting->user_id !== $user->id) {
            throw new \LogicException('Vaga não pertence ao usuário.');
        }

        $this->assertEligible($user, $posting, $language);

        $snapshot = $this->snapshots->resumeAdaptation($user, $posting, $language);

        return $this->adaptPayload($user, $posting, $snapshot['payload'], $snapshot['fingerprint']);
    }

    /**
     * Execute an adaptation using the immutable payload captured when the
     * background operation was queued.
     *
     * @param  array<string, mixed>  $payload
     */
    public function adaptFromSnapshot(
        User $user,
        JobPosting $posting,
        array $payload,
        string $fingerprint,
        ?Closure $persistWithinAttempt = null,
    ): ResumeAdaptation {
        Gate::forUser($user)->authorize('update', $posting);

        if ($posting->user_id !== $user->id) {
            throw new \LogicException('Vaga não pertence ao usuário.');
        }

        if ($posting->status !== JobPostingStatus::Confirmed) {
            throw new \LogicException('A adequação exige uma vaga confirmada.');
        }

        if (blank($payload['resume'] ?? null)) {
            throw new \LogicException('O snapshot da adequação não contém currículo.');
        }

        return $this->adaptPayload($user, $posting, $payload, $fingerprint, $persistWithinAttempt);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function adaptPayload(
        User $user,
        JobPosting $posting,
        array $payload,
        string $fingerprint,
        ?Closure $persistWithinAttempt = null,
    ): ResumeAdaptation {

        $language = ResumeLanguage::tryFrom((string) ($payload['language'] ?? '')) ?? ResumeLanguage::PtBr;

        $started = microtime(true);
        $inputChars = 0;
        $usage = null;

        $store = config('cache.lock_store', config('cache.default', 'file'));
        $stores = config('cache.stores', []);

        if (! isset($stores[$store])) {
            $store = 'file';
        }

        $opLock = Cache::store($store)->lock("adapt:posting:{$posting->id}", 130);

        if (! $opLock->get()) {
            throw new \RuntimeException('Adequação já em andamento para esta vaga. Aguarde a conclusão.');
        }

        try {
            $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $inputChars = mb_strlen($jsonPayload);

            $gate = $this->gate ?? new AiRequestGate;
            $response = $gate->run(
                $this->agent->provider(),
                $this->agent->model(),
                fn () => $this->agent->withLanguage($language)->prompt($jsonPayload)
            );
            $usage = $response->usage ?? null;

            $data = json_decode($response->text, true);

            if (! is_array($data)) {
                throw new \InvalidArgumentException('A resposta da IA não é um JSON válido.');
            }

            $result = ResumeAdaptationResult::fromStructured($data);

            $this->assertIntegrity($result, (string) $payload['resume']);

            $existingAdaptation = $posting->resumeAdaptation($language)->first();
            $wasOptimized = (bool) ($existingAdaptation?->is_ats_optimized ?? false);

            $prompt = '';
            $promptFingerprint = null;

            if ($wasOptimized) {
                $builder = $this->atsBuilder ?? app(AtsPromptBuilder::class);
                $positioning = $payload['positioning'] ?? [];
                $prompt = is_array($positioning)
                    ? $builder->buildFromSnapshot(
                        (string) ($payload['job_role'] ?? ''),
                        is_string($payload['job_company'] ?? null) ? $payload['job_company'] : null,
                        (string) ($payload['resume'] ?? ''),
                        $positioning,
                        $language,
                    )
                    : '';
                $promptFingerprint = $prompt !== ''
                    ? $this->atsPromptFingerprint($payload, $prompt)
                    : null;
            }

            $persist = function () use ($fingerprint, $posting, $language, $prompt, $promptFingerprint, $result, $wasOptimized): ResumeAdaptation {
                $adaptation = ResumeAdaptation::updateOrCreate(
                    ['job_posting_id' => $posting->id, 'language' => $language->value],
                    [
                        'adapted_content' => $result->adaptedResume,
                        'changes' => $result->changes,
                        'covered_keywords' => $result->coveredKeywords,
                        'missing_requirements' => $result->missingRequirements,
                        'alerts' => $result->alerts,
                        'source_fingerprint' => $fingerprint,
                        'ats_score' => null,
                        'ats_rationale' => null,
                        'ats_source_fingerprint' => null,
                    ],
                );

                if ($wasOptimized) {
                    $adaptation->forceFill([
                        'ats_prompt' => $prompt !== '' ? $prompt : null,
                        'ats_prompt_fingerprint' => $promptFingerprint,
                        'is_ats_optimized' => $prompt !== '',
                    ])->save();
                }

                return $adaptation;
            };

            $adaptation = $persistWithinAttempt !== null
                ? $persistWithinAttempt($persist)
                : $persist();

            if ($wasOptimized) {
                Log::channel('ai')->info('ATS optimization regenerated after adaptation.', [
                    'job_posting_id' => $posting->id,
                    'operation' => 'ats_optimization_regenerated',
                    'is_ats_optimized' => $prompt !== '',
                    'ats_prompt_chars' => $prompt !== '' ? mb_strlen($prompt) : 0,
                ]);
            }

            Log::channel('ai')->info('Resume adaptation generated.', array_merge([
                'job_posting_id' => $posting->id,
                'operation' => 'resume_adaptation',
                'provider' => $this->agent->provider(),
                'model' => $this->agent->model(),
                'input_chars' => $inputChars,
                'success' => true,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ], AiUsageHelper::extract($usage)));

            return $adaptation;
        } catch (Throwable $e) {
            Log::channel('ai')->error('Resume adaptation failed.', array_merge([
                'job_posting_id' => $posting->id,
                'operation' => 'resume_adaptation',
                'provider' => $this->agent->provider(),
                'model' => $this->agent->model(),
                'input_chars' => $inputChars,
                'success' => false,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => $e->getMessage(),
            ], AiUsageHelper::extract($usage)));

            throw new \RuntimeException('Falha na adequação do currículo: '.$e->getMessage(), 0, $e);
        } finally {
            try {
                $opLock->release();
            } catch (Throwable) {
            }
        }
    }

    public function setAtsOptimization(User $user, JobPosting $posting, bool $enabled, ResumeLanguage $language = ResumeLanguage::PtBr): ResumeAdaptation
    {
        Gate::forUser($user)->authorize('useAtsOptimization');
        Gate::forUser($user)->authorize('update', $posting);

        if ($posting->user_id !== $user->id) {
            throw new \LogicException('Vaga não pertence ao usuário.');
        }

        if ($posting->status !== JobPostingStatus::Confirmed) {
            throw new \LogicException('A otimização exige uma vaga confirmada.');
        }

        $adaptation = $posting->resumeAdaptation($language)->first();

        if (! $adaptation) {
            throw new \LogicException('Gere a adaptação do currículo antes de otimizar para ATS.');
        }

        $store = config('cache.lock_store', config('cache.default', 'file'));
        $stores = config('cache.stores', []);

        if (! isset($stores[$store])) {
            $store = 'file';
        }

        $lock = Cache::store($store)->lock("ats:posting:{$posting->id}", 10);

        if (! $lock->get()) {
            throw new \RuntimeException('Operação de otimização já em andamento para esta vaga.');
        }

        try {
            $snapshot = $this->snapshots->atsOptimization(
                $user,
                $posting->fresh()->load('items'),
                $enabled,
                $language,
            );
            $prompt = $snapshot['payload']['ats_prompt'];

            if ($enabled && blank($prompt)) {
                throw new \LogicException('Não há palavras-chave do currículo para otimizar esta vaga.');
            }

            if (! is_string($prompt) && $prompt !== null) {
                throw new \LogicException('O bloco de otimização ATS é inválido.');
            }

            if (! $enabled) {
                $this->persistAtsOptimization($adaptation, false, null, null);

                Log::channel('ai')->info('ATS optimization disabled.', [
                    'job_posting_id' => $posting->id,
                    'operation' => 'ats_optimization_toggled',
                    'is_ats_optimized' => false,
                    'user_id' => $user->id,
                ]);

                return $adaptation->fresh();
            }

            $this->persistAtsOptimization($adaptation, true, $prompt, $snapshot['fingerprint']);

            Log::channel('ai')->info('ATS optimization enabled.', [
                'job_posting_id' => $posting->id,
                'operation' => 'ats_optimization_toggled',
                'is_ats_optimized' => true,
                'ats_prompt_chars' => $prompt !== '' ? mb_strlen($prompt) : 0,
                'ats_prompt_preview' => $prompt !== '' ? mb_substr($prompt, 0, 80) : null,
                'user_id' => $user->id,
            ]);

            return $adaptation->fresh();
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
            }
        }
    }

    /**
     * Apply an ATS toggle from the immutable payload captured at enqueue time.
     *
     * @param  array<string, mixed>  $payload
     */
    public function setAtsOptimizationFromSnapshot(
        User $user,
        JobPosting $posting,
        array $payload,
        string $fingerprint,
        ?Closure $persistWithinAttempt = null,
    ): ResumeAdaptation {
        Gate::forUser($user)->authorize('useAtsOptimization');
        Gate::forUser($user)->authorize('update', $posting);

        if ($posting->user_id !== $user->id) {
            throw new \LogicException('Vaga não pertence ao usuário.');
        }

        if ($posting->status !== JobPostingStatus::Confirmed) {
            throw new \LogicException('A otimização exige uma vaga confirmada.');
        }

        if (! is_bool($payload['enabled'] ?? null)) {
            throw new \LogicException('O snapshot da otimização ATS não informa o estado solicitado.');
        }

        $language = ResumeLanguage::tryFrom((string) ($payload['language'] ?? '')) ?? ResumeLanguage::PtBr;

        $adaptation = $posting->resumeAdaptation($language)->first();

        if (! $adaptation) {
            throw new \LogicException('Gere a adaptação do currículo antes de otimizar para ATS.');
        }

        $prompt = null;

        if ($payload['enabled']) {
            if (! is_string($payload['ats_prompt'] ?? null) || blank($payload['ats_prompt'])) {
                throw new \LogicException('O snapshot da otimização ATS não contém o bloco calculado.');
            }

            $prompt = $payload['ats_prompt'] !== '' ? $payload['ats_prompt'] : null;
        }

        $store = config('cache.lock_store', config('cache.default', 'file'));
        $stores = config('cache.stores', []);

        if (! isset($stores[$store])) {
            $store = 'file';
        }

        $lock = Cache::store($store)->lock("ats:posting:{$posting->id}", 10);

        if (! $lock->get()) {
            throw new \RuntimeException('Operação de otimização já em andamento para esta vaga.');
        }

        try {
            $persist = fn (): ResumeAdaptation => $this->persistAtsOptimization(
                $adaptation,
                $payload['enabled'],
                $prompt,
                $payload['enabled'] ? $fingerprint : null,
            );

            return $persistWithinAttempt !== null
                ? $persistWithinAttempt($persist)
                : $persist();
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
            }
        }
    }

    private function persistAtsOptimization(ResumeAdaptation $adaptation, bool $enabled, ?string $prompt, ?string $fingerprint): ResumeAdaptation
    {
        $adaptation->forceFill([
            'is_ats_optimized' => $enabled,
            'ats_prompt' => $prompt,
            'ats_prompt_fingerprint' => $fingerprint,
            'ats_score' => null,
            'ats_rationale' => null,
            'ats_source_fingerprint' => null,
        ])->save();

        return $adaptation->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function atsPromptFingerprint(array $payload, string $prompt): string
    {
        $items = collect($payload['positioning'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'name' => $item['name'] ?? null,
                'category' => $item['category'] ?? null,
            ])
            ->values()
            ->all();

        return $this->snapshots->fingerprint([
            'enabled' => true,
            'language' => $payload['language'] ?? ResumeLanguage::PtBr->value,
            'resume' => $payload['resume'] ?? '',
            'job_role' => $payload['job_role'] ?? null,
            'job_company' => $payload['job_company'] ?? null,
            'items' => $items,
            'ats_prompt' => $prompt,
        ]);
    }

    private function assertEligible(User $user, JobPosting $posting, ResumeLanguage $language): void
    {
        if ($posting->status !== JobPostingStatus::Confirmed) {
            throw new \LogicException('A adequação exige uma vaga confirmada.');
        }

        if (blank($this->resumeService->content($user, $language))) {
            throw new \LogicException($language->missingResumeGuidance());
        }
    }

    private function assertIntegrity(ResumeAdaptationResult $result, string $resume): void
    {
        $originalConcepts = $this->resumeAnalyzer->conceptsIn($resume);
        $invented = $this->resumeAnalyzer->inventedConcepts($resume, $result->adaptedResume);

        if ($invented !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Integridade: o currículo adaptado introduz conceitos ausentes do currículo original: %s.',
                implode(', ', $invented),
            ));
        }

        foreach ($result->coveredKeywords as $keyword) {
            $canonical = $this->normalizer->normalize($keyword);

            if ($canonical === '' || ! in_array($canonical, $this->normalizer->canonicalNames(), true)) {
                continue;
            }

            if (! in_array($canonical, $originalConcepts, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Integridade: "covered_keywords" contém "%s", ausente do currículo original.',
                    $keyword,
                ));
            }
        }

        foreach ($result->missingRequirements as $requirement) {
            $canonical = $this->normalizer->normalize($requirement);

            if ($canonical === '' || ! in_array($canonical, $this->normalizer->canonicalNames(), true)) {
                continue;
            }

            if (in_array($canonical, $originalConcepts, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Integridade: "missing_requirements" lista "%s", que está presente no currículo original.',
                    $requirement,
                ));
            }
        }
    }
}
