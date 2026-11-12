<?php

namespace App\Jobs;

use App\Models\PublicationClefResult;
use App\Models\PublicationObservation;
use App\Services\Triage\JobPrefilter;
use App\Services\Triage\PrefilterException;
use App\Services\Triage\PrefilterInput;
use App\Services\Triage\PrefilterPolicy;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ClassifyPublicationWithClef implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public ?string $leaseToken = null;

    public function __construct(public int $resultId, public ?string $recoveryToken = null) {}

    public function uniqueId(): string
    {
        return 'publication-clef-result:'.$this->resultId.':'.($this->recoveryToken ?? 'initial');
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

    public function handle(JobPrefilter $prefilter, PrefilterPolicy $policy): void
    {
        if (! config('triage.prefilter.enabled')) {
            return;
        }

        $this->leaseToken = (string) Str::uuid();
        $result = DB::transaction(function () use ($prefilter): ?PublicationClefResult {
            $result = PublicationClefResult::query()->whereKey($this->resultId)->lockForUpdate()->first();

            if ($result === null || $result->status !== 'pending') {
                return null;
            }

            $result->forceFill([
                'status' => 'running',
                'model' => $prefilter->configuredModel(),
                'classifier_identifier' => $prefilter->classifierIdentifier(),
                'lease_token' => $this->leaseToken,
                'started_at' => now(),
            ])->save();

            return $result;
        });

        if ($result === null) {
            return;
        }

        try {
            $observation = PublicationObservation::query()
                ->where('publication_id', $result->publication_id)
                ->where('content_version', $result->content_version)
                ->whereNotNull('content')
                ->orderBy('id')
                ->first();

            if ($observation === null || trim((string) $observation->content) === '') {
                $this->complete('inconclusive', null, null, null, 'content_version_unavailable');

                return;
            }

            $verdict = $prefilter->classify(new PrefilterInput(
                title: null,
                description: $observation->content,
                company: null,
                location: null,
            ));

            $this->complete(
                $policy->bars($verdict) ? 'barred' : 'eligible',
                $verdict->isJobProbability,
                $verdict->nonJobKind,
                $verdict->confidence,
                null,
            );
        } catch (Throwable $exception) {
            $error = $exception instanceof PrefilterException
                ? $this->safeError($exception, (string) config('triage.prefilter.api_token', ''))
                : 'classification_failed';

            Log::channel('ai')->warning('Publication Clef prefilter failed.', [
                'publication_id' => $result->publication_id,
                'content_version' => $result->content_version,
                'attempt' => $result->attempt,
                'exception' => $exception::class,
                'error' => $error,
            ]);

            $this->complete('inconclusive', null, null, null, $error);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->leaseToken === null) {
            return;
        }

        $this->complete('inconclusive', null, null, null, 'worker_failed');
    }

    private function complete(
        string $status,
        ?float $probability,
        ?string $category,
        ?float $confidence,
        ?string $error,
    ): void {
        PublicationClefResult::query()
            ->whereKey($this->resultId)
            ->where('status', 'running')
            ->where('lease_token', $this->leaseToken)
            ->update([
                'status' => $status,
                'probability' => $probability,
                'category' => $category,
                'confidence' => $confidence,
                'error' => $error,
                'lease_token' => null,
                'classified_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function safeError(PrefilterException $exception, string $apiToken): string
    {
        $message = $exception->getMessage();

        if ($apiToken !== '') {
            $message = str_replace($apiToken, '[redacted]', $message);
        }

        $message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message) ?? 'prefilter_failed';

        return mb_substr(trim($message), 0, 1000);
    }
}
