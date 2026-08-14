<?php

namespace App\Services;

use App\Agents\JobExtractionAgent;
use App\DTO\JobAnalysisResult;
use App\Enums\JobPostingStatus;
use App\Models\JobPosting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\AiException;
use Throwable;

class JobAnalysisService
{
    public const MIN_DESCRIPTION_LENGTH = 20;

    public const MAX_DESCRIPTION_LENGTH = 100000;

    public function __construct(
        private readonly JobExtractionAgent $agent,
        private readonly ConceptNormalizer $normalizer,
    ) {}

    /**
     * Run the AI extraction for a posting and transition its status.
     *
     * pending → needs_review (suggestion stored) | failed (error recorded).
     * Erros transitórios do provedor (saldo, limite, HTTP/transporte) mantêm a
     * vaga em pending — não são falha da análise.
     * Confirmed postings are never re-analyzed.
     */
    public function analyze(JobPosting $posting): JobPosting
    {
        if ($posting->status === JobPostingStatus::Confirmed) {
            throw new \LogicException('Vaga confirmada não pode ser re-analisada.');
        }

        $started = microtime(true);

        try {
            $this->assertDescriptionWithinLimits($posting->description);

            $response = $this->agent->prompt($posting->description);

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

            $this->log('info', 'Job posting analyzed successfully.', $posting, $started, [
                'item_count' => count($result->items, COUNT_RECURSIVE) - count($result->items),
            ]);

            return $posting;
        } catch (Throwable $e) {
            if ($this->isTransientProviderError($e)) {
                $this->log('warning', 'Job posting analysis deferred due to a transient provider error.', $posting, $started, [
                    'error' => $e->getMessage(),
                ]);

                return $posting;
            }

            $posting->forceFill([
                'status' => JobPostingStatus::Failed,
                'suggested_data' => null,
                'error_message' => 'Falha na análise com IA: '.$e->getMessage(),
            ])->save();

            $this->log('error', 'Job posting analysis failed.', $posting, $started, [
                'error' => $e->getMessage(),
            ]);

            return $posting;
        }
    }

    private function isTransientProviderError(Throwable $e): bool
    {
        // Erros do provedor de IA: HTTP (401 saldo, 5xx), rede e falhas mapeadas
        // pelo gateway do laravel/ai (saldo, limite de requisições, sobrecarga).
        return $e instanceof RequestException
            || $e instanceof ConnectionException
            || $e instanceof AiException;
    }

    private function assertDescriptionWithinLimits(string $description): void
    {
        $length = mb_strlen($description);

        if ($length < self::MIN_DESCRIPTION_LENGTH || $length > self::MAX_DESCRIPTION_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'A descrição deve ter entre %d e %d caracteres (atual: %d).',
                self::MIN_DESCRIPTION_LENGTH,
                self::MAX_DESCRIPTION_LENGTH,
                $length,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $level, string $message, JobPosting $posting, float $started, array $context = []): void
    {
        Log::channel('ai')->{$level}($message, array_merge([
            'job_posting_id' => $posting->id,
            'model' => $this->agent->model(),
            'success' => $level === 'info',
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
        ], $context));
    }
}
