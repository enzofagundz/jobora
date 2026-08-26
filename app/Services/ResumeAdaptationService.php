<?php

namespace App\Services;

use App\Agents\ResumeAdaptationAgent;
use App\DTO\ResumeAdaptationResult;
use App\Enums\JobPostingStatus;
use App\Models\JobPosting;
use App\Models\ResumeAdaptation;
use App\Models\User;
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

        try {
            $payload = $this->buildPayload($user, $posting);
            $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $inputChars = mb_strlen($jsonPayload);

            $response = $this->agent->prompt($jsonPayload);
            $usage = $response->usage ?? null;

            $data = json_decode($response->text, true);

            if (! is_array($data)) {
                throw new \InvalidArgumentException('A resposta da IA não é um JSON válido.');
            }

            $result = ResumeAdaptationResult::fromStructured($data);

            $this->assertIntegrity($user, $result, $posting);

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

            Log::channel('ai')->info('Resume adaptation generated.', array_merge([
                'job_posting_id' => $posting->id,
                'operation' => 'resume_adaptation',
                'provider' => $this->agent->provider(),
                'model' => $this->agent->model(),
                'input_chars' => $inputChars,
                'success' => true,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ], $this->extractUsageData($usage)));

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
            ], $this->extractUsageData($usage)));

            throw new \RuntimeException('Falha na adequação do currículo: '.$e->getMessage(), 0, $e);
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
