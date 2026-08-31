<?php

namespace App\Services;

use App\Agents\ResumeAdaptationAgent;
use App\DTO\ResumeAdaptationResult;
use App\Enums\JobPostingStatus;
use App\Models\JobPosting;
use App\Models\ResumeAdaptation;
use App\Models\User;
use App\Support\Ai\AiRequestGate;
use App\Support\Ai\AiUsageHelper;
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
        private readonly ?AiRequestGate $gate = null,
        private readonly ?AtsPromptBuilder $atsBuilder = null,
    ) {}

    public function adapt(User $user, JobPosting $posting): ResumeAdaptation
    {
        Gate::forUser($user)->authorize('update', $posting);

        if ($posting->user_id !== $user->id) {
            throw new \LogicException('Vaga não pertence ao usuário.');
        }

        $this->assertEligible($user, $posting);

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
            $payload = $this->buildPayload($user, $posting);
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

            if (! is_array($data)) {
                throw new \InvalidArgumentException('A resposta da IA não é um JSON válido.');
            }

            $result = ResumeAdaptationResult::fromStructured($data);

            $this->assertIntegrity($user, $result, $posting);

            $existingAdaptation = ResumeAdaptation::where('job_posting_id', $posting->id)->first();
            $wasOptimized = (bool) ($existingAdaptation?->is_ats_optimized ?? false);

            $adaptation = ResumeAdaptation::updateOrCreate(
                ['job_posting_id' => $posting->id],
                [
                    'adapted_content' => $result->adaptedResume,
                    'changes' => $result->changes,
                    'covered_keywords' => $result->coveredKeywords,
                    'missing_requirements' => $result->missingRequirements,
                    'alerts' => $result->alerts,
                ],
            );

            if ($wasOptimized) {
                $resumeContent = $this->resumeService->content($user) ?? '';
                $builder = $this->atsBuilder ?? app(AtsPromptBuilder::class);
                $freshPosting = $posting->fresh()->load('items');
                $prompt = $builder->build($freshPosting, ResumeService::plainText($resumeContent));
                $adaptation->forceFill([
                    'ats_prompt' => $prompt !== '' ? $prompt : null,
                    'is_ats_optimized' => true,
                ])->save();

                Log::channel('ai')->info('ATS optimization regenerated after adaptation.', [
                    'job_posting_id' => $posting->id,
                    'operation' => 'ats_optimization_regenerated',
                    'is_ats_optimized' => true,
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

    public function setAtsOptimization(User $user, JobPosting $posting, bool $enabled): ResumeAdaptation
    {
        Gate::forUser($user)->authorize('useAtsOptimization');
        Gate::forUser($user)->authorize('update', $posting);

        if ($posting->user_id !== $user->id) {
            throw new \LogicException('Vaga não pertence ao usuário.');
        }

        if ($posting->status !== JobPostingStatus::Confirmed) {
            throw new \LogicException('A otimização exige uma vaga confirmada.');
        }

        $adaptation = ResumeAdaptation::where('job_posting_id', $posting->id)->first();

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
            if (! $enabled) {
                $adaptation->forceFill([
                    'is_ats_optimized' => false,
                    'ats_prompt' => null,
                ])->save();

                Log::channel('ai')->info('ATS optimization disabled.', [
                    'job_posting_id' => $posting->id,
                    'operation' => 'ats_optimization_toggled',
                    'is_ats_optimized' => false,
                    'user_id' => $user->id,
                ]);

                return $adaptation->fresh();
            }

            $resumeContent = $this->resumeService->content($user);

            if (blank($resumeContent)) {
                throw new \LogicException('Cadastre o currículo antes de otimizar para ATS.');
            }

            $builder = $this->atsBuilder ?? app(AtsPromptBuilder::class);
            $freshPosting = $posting->fresh()->load('items');
            $prompt = $builder->build($freshPosting, ResumeService::plainText($resumeContent));

            if (mb_strlen($prompt) > AtsPromptBuilder::MAX_LENGTH) {
                $prompt = mb_substr($prompt, 0, AtsPromptBuilder::MAX_LENGTH);
            }

            // Guardrail: prompt must only contain present keywords — builder already enforces
            $adaptation->forceFill([
                'is_ats_optimized' => true,
                'ats_prompt' => $prompt !== '' ? $prompt : null,
            ])->save();

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

    private function assertEligible(User $user, JobPosting $posting): void
    {
        if ($posting->status !== JobPostingStatus::Confirmed) {
            throw new \LogicException('A adequação exige uma vaga confirmada.');
        }

        if (blank($this->resumeService->content($user))) {
            throw new \LogicException('Cadastre o currículo antes de adequá-lo a uma vaga.');
        }
    }

    private function buildPayload(User $user, JobPosting $posting): array
    {
        $resume = ResumeService::plainText($this->resumeService->content($user));
        $description = ResumeService::plainText($posting->description);

        $positioning = $posting->items
            ->map(fn ($item): array => [
                'name' => $item->name,
                'category' => $item->category->value,
                'present_in_resume' => $this->resumeAnalyzer->hasConcept($resume, $item->name),
            ])
            ->values()
            ->all();

        return [
            'resume' => $resume,
            'job_description' => $description,
            'job_role' => $posting->role,
            'job_company' => $posting->company,
            'positioning' => $positioning,
        ];
    }

    private function assertIntegrity(User $user, ResumeAdaptationResult $result, JobPosting $posting): void
    {
        $resume = $this->resumeService->content($user);
        $originalConcepts = $this->resumeAnalyzer->conceptsIn($resume);
        $adaptedConcepts = $this->resumeAnalyzer->conceptsIn($result->adaptedResume);

        $invented = array_values(array_diff($adaptedConcepts, $originalConcepts));

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
