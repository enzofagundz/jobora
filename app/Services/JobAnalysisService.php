<?php

namespace App\Services;

use App\Agents\JobExtractionAgent;
use App\DTO\JobAnalysisResult;
use App\Enums\JobPostingStatus;
use App\Models\JobIaAttempt;
use App\Models\JobPosting;
use App\Models\User;
use App\Support\Ai\AiRequestGate;
use App\Support\Ai\AiUsageHelper;
use App\Support\Ai\FriendlyAiErrorMapper;
use App\Support\Ai\TransientAiClassifier;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

class JobAnalysisService
{
    public const MIN_DESCRIPTION_LENGTH = 20;

    public const MAX_DESCRIPTION_LENGTH = 100000;

    public function __construct(
        private readonly JobExtractionAgent $agent,
        private readonly ConceptNormalizer $normalizer,
        private readonly ?AiRequestGate $gate = null,
    ) {}

    /**
     * Run the AI extraction for a posting and transition its status.
     *
     * pending → needs_review (suggestion stored) | failed (error recorded).
     * Erros transitórios do provedor (saldo, limite, HTTP/transporte) mantêm a
     * vaga em pending — não são falha da análise.
     * Confirmed postings are never re-analyzed.
     */
    public function analyze(User $user, JobPosting $posting, string $trigger = 'auto_dispatch'): JobPosting
    {
        Gate::forUser($user)->authorize('update', $posting);

        if ($posting->user_id !== $user->id) {
            throw new \LogicException('Vaga não pertence ao usuário.');
        }

        if ($posting->status === JobPostingStatus::Confirmed) {
            throw new \LogicException('Vaga confirmada não pode ser re-analisada.');
        }

        $started = microtime(true);
        $inputChars = 0;
        $usage = null;

        try {
            $plainDescription = ResumeService::plainText($posting->description);
            $inputChars = mb_strlen($plainDescription);
            $this->assertDescriptionWithinLimits($plainDescription);

            $gate = $this->gate ?? new AiRequestGate;
            $response = $gate->run(
                $this->agent->provider(),
                $this->agent->model(),
                fn () => $this->agent->prompt($plainDescription)
            );
            $usage = $response->usage ?? null;

            $data = json_decode($response->text, true);

            if (! is_array($data)) {
                throw new \InvalidArgumentException('A resposta da IA não é um JSON válido.');
            }

            $result = JobAnalysisResult::fromStructured($data, $this->normalizer);

            $posting->forceFill([
                'status' => JobPostingStatus::NeedsReview,
                'role' => $result->role,
                'company' => $result->company,
                'suggested_data' => $result->toSuggestedData(),
                'error_message' => null,
            ])->save();

            $attempt = $this->recordAttempt($user, $posting, $this->baseAttemptPayload($posting, $started, $inputChars, $trigger) + [
                'status' => 'success',
                'transient' => false,
            ] + AiUsageHelper::extract($usage));

            $this->log('info', 'Job posting analyzed successfully.', $posting, $started, [
                'item_count' => count($result->items, COUNT_RECURSIVE) - count($result->items),
                'operation' => 'job_extraction',
                'provider' => $this->agent->provider(),
                'model' => $this->agent->model(),
                'input_chars' => $inputChars,
                'usage' => $usage,
                'attempt_id' => $attempt->id,
                'attempt_no' => $attempt->attempt_no,
                'trigger' => $trigger,
            ] + AiUsageHelper::extract($usage));

            return $posting;
        } catch (Throwable $e) {
            if (TransientAiClassifier::isTransient($e)) {
                $meta = TransientAiClassifier::metadata($e);

                $attempt = $this->recordAttempt($user, $posting, $this->baseAttemptPayload($posting, $started, $inputChars, $trigger) + [
                    'status' => 'transient_error',
                    'error_class' => $e::class,
                    'error_message' => $e->getMessage(),
                    'transient' => true,
                ] + AiUsageHelper::extract($usage));

                $this->log('warning', 'Job posting analysis deferred due to a transient provider error.', $posting, $started, [
                    'error' => $e->getMessage(),
                    'operation' => 'job_extraction',
                    'provider' => $this->agent->provider(),
                    'model' => $this->agent->model(),
                    'input_chars' => $inputChars,
                    'retry_after' => $meta['retry_after'],
                    'status' => $meta['status'],
                    'usage' => $usage,
                    'attempt_id' => $attempt->id,
                    'attempt_no' => $attempt->attempt_no,
                    'trigger' => $trigger,
                ] + AiUsageHelper::extract($usage));

                throw $e;
            }

            $posting->forceFill([
                'status' => JobPostingStatus::Failed,
                'suggested_data' => null,
                'error_message' => FriendlyAiErrorMapper::for($e),
            ])->save();

            $attempt = $this->recordAttempt($user, $posting, $this->baseAttemptPayload($posting, $started, $inputChars, $trigger) + [
                'status' => 'permanent_error',
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
                'transient' => false,
            ] + AiUsageHelper::extract($usage));

            $this->log('error', 'Job posting analysis failed.', $posting, $started, [
                'error' => $e->getMessage(),
                'error_class' => $e::class,
                'operation' => 'job_extraction',
                'provider' => $this->agent->provider(),
                'model' => $this->agent->model(),
                'input_chars' => $inputChars,
                'usage' => $usage,
                'attempt_id' => $attempt->id,
                'attempt_no' => $attempt->attempt_no,
                'trigger' => $trigger,
            ] + AiUsageHelper::extract($usage));

            return $posting;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function baseAttemptPayload(JobPosting $posting, float $started, int $inputChars, string $trigger): array
    {
        return [
            'operation' => 'job_extraction',
            'provider' => $this->agent->provider(),
            'model' => $this->agent->model(),
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'input_chars' => $inputChars,
            'attempt_no' => $posting->nextAttemptNo(),
            'trigger' => $trigger,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function recordAttempt(User $user, JobPosting $posting, array $attributes): JobIaAttempt
    {
        return JobIaAttempt::query()->create(array_merge($attributes, [
            'job_posting_id' => $posting->id,
            'user_id' => $user->id,
        ]));
    }

    private function assertDescriptionWithinLimits(string $description): void
    {
        if (mb_strlen($description) < self::MIN_DESCRIPTION_LENGTH) {
            throw new \InvalidArgumentException(
                'A descrição da vaga deve ter entre '.self::MIN_DESCRIPTION_LENGTH.' e '.self::MAX_DESCRIPTION_LENGTH.' caracteres.'
            );
        }

        if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw new \InvalidArgumentException(
                'A descrição da vaga deve ter entre '.self::MIN_DESCRIPTION_LENGTH.' e '.self::MAX_DESCRIPTION_LENGTH.' caracteres.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $level, string $message, JobPosting $posting, float $started, array $context = []): void
    {
        Log::channel('ai')->$level($message, array_merge([
            'job_posting_id' => $posting->id,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
        ], $context));
    }
}
