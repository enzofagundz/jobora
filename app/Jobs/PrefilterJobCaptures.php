<?php

namespace App\Jobs;

use App\Enums\JobCaptureTriageResult;
use App\Enums\JobCaptureTriageStatus;
use App\Enums\TriageOrigin;
use App\Models\JobCapture;
use App\Models\User;
use App\Services\Triage\JobPrefilter;
use App\Services\Triage\PrefilterException;
use App\Services\Triage\PrefilterInput;
use App\Services\Triage\PrefilterPolicy;
use App\Services\Triage\PrefilterVerdict;
use App\Services\Triage\TriageBatchNotifier;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Primeiro passo do lote de triagem: pergunta ao Clef, em paralelo, se cada
 * captura anuncia vaga. As barradas são concluídas como "não é uma vaga";
 * as aprovadas (e as que falharam no pré-filtro, por fail-open) seguem para
 * o TriageJobCaptures.
 */
class PrefilterJobCaptures implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Queueable;

    public int $timeout = 130;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    /**
     * @param  array<int, int>  $captureIds
     */
    public function __construct(
        public array $captureIds,
        public int $userId,
    ) {}

    public function uniqueId(): string
    {
        $sorted = $this->captureIds;
        sort($sorted);

        return 'prefilter:'.$this->userId.':'.md5(implode(',', $sorted));
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function uniqueVia(): CacheRepository
    {
        $store = config('cache.lock_store', config('cache.default', 'file'));
        $stores = config('cache.stores', []);

        if (! isset($stores[$store])) {
            $store = 'file';
        }

        try {
            return Cache::store($store);
        } catch (Throwable) {
            return Cache::store('file');
        }
    }

    public function handle(
        JobPrefilter $prefilter,
        PrefilterPolicy $policy,
        TriageBatchNotifier $notifier,
    ): void {
        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        $captures = $user->jobCaptures()
            ->whereIn('id', $this->captureIds)
            ->where('triage_status', JobCaptureTriageStatus::Processing)
            ->orderBy('id')
            ->get()
            ->values();

        if ($captures->isEmpty()) {
            return;
        }

        $inputs = $captures
            ->map(fn (JobCapture $capture): PrefilterInput => new PrefilterInput(
                title: $capture->title,
                description: $capture->description,
                company: $capture->company,
                location: $capture->location,
            ))
            ->all();

        $started = microtime(true);
        $verdicts = [];

        try {
            $verdicts = $prefilter->classifyMany($inputs);
        } catch (Throwable $exception) {
            Log::channel('ai')->warning('Job captures prefilter failed before classification.', [
                'job_capture_ids' => $captures->pluck('id')->all(),
                'operation' => 'job_triage_prefilter',
                'error' => $exception->getMessage(),
            ]);
        }

        $barredIds = [];
        $approvedIds = [];
        $failedIds = [];

        foreach ($captures as $index => $capture) {
            $verdict = $verdicts[$index] ?? null;

            if ($verdict instanceof PrefilterException) {
                $failedIds[] = $capture->id;

                continue;
            }

            if (! $verdict instanceof PrefilterVerdict) {
                $failedIds[] = $capture->id;

                continue;
            }

            if ($policy->bars($verdict)) {
                $capture->update([
                    'triage_status' => JobCaptureTriageStatus::Completed,
                    'triage_result' => JobCaptureTriageResult::NotAJob,
                    'triage_reason' => $policy->reason($verdict),
                    'triage_origin' => TriageOrigin::Prefilter,
                    'triage_error' => null,
                ]);

                $barredIds[] = $capture->id;

                continue;
            }

            $approvedIds[] = $capture->id;
        }

        $forwardedIds = array_merge($approvedIds, $failedIds);

        Log::channel('ai')->info('Job captures prefiltered.', [
            'job_capture_ids' => $captures->pluck('id')->all(),
            'operation' => 'job_triage_prefilter',
            'provider' => 'cloudflare',
            'model' => (string) config('triage.prefilter.model'),
            'barred_count' => count($barredIds),
            'approved_count' => count($approvedIds),
            'forwarded_count' => count($forwardedIds),
            'prefilter_failed_count' => count($failedIds),
            'prefilter_failed_ids' => $failedIds,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'success' => $failedIds === [],
        ]);

        if ($forwardedIds === []) {
            $notifier->send($user, 0, count($barredIds), 0);

            return;
        }

        TriageJobCaptures::dispatch($this->captureIds, $this->userId, $forwardedIds);
    }

    /**
     * If the prefilter job fails, captures stuck in processing must not
     * remain ambiguous.
     */
    public function failed(?Throwable $exception): void
    {
        JobCapture::query()
            ->whereIn('id', $this->captureIds)
            ->where('user_id', $this->userId)
            ->where('triage_status', JobCaptureTriageStatus::Processing)
            ->update([
                'triage_status' => JobCaptureTriageStatus::Failed,
                'triage_error' => $exception?->getMessage() ?? 'Job falhou (timeout)',
            ]);

        $recipient = User::find($this->userId);

        if ($recipient) {
            app(TriageBatchNotifier::class)->sendFailure($recipient);
        }
    }
}
