<?php

namespace App\Jobs;

use App\Enums\JobCaptureTriageStatus;
use App\Enums\TriageOrigin;
use App\Models\JobCapture;
use App\Models\User;
use App\Services\JobTriageService;
use App\Services\Triage\TriageBatchNotifier;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TriageJobCaptures implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Queueable;

    public int $timeout = 130;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    /**
     * @param  array<int, int>  $captureIds  lote completo, usado no aviso final
     * @param  array<int, int>|null  $triageIds  capturas encaminhadas ao LLM; null = todas
     */
    public function __construct(
        public array $captureIds,
        public int $userId,
        public ?array $triageIds = null,
    ) {}

    public function uniqueId(): string
    {
        $sorted = $this->captureIds;
        sort($sorted);

        return 'triage:'.$this->userId.':'.md5(implode(',', $sorted));
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

    public function handle(JobTriageService $service): void
    {
        $notifier = app(TriageBatchNotifier::class);

        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        $triageIds = $this->triageIds ?? $this->captureIds;

        $captures = $user->jobCaptures()->whereIn('id', $triageIds)->get();

        if ($captures->isEmpty()) {
            return;
        }

        $service->triageBatch(
            $captures,
            $user,
        );

        $fresh = $user->jobCaptures()->whereIn('id', $this->captureIds)->get();

        if ($fresh->isEmpty()) {
            return;
        }

        $forwarded = $fresh->whereIn('id', $triageIds)->count();
        $barred = $fresh
            ->filter(fn (JobCapture $capture): bool => $capture->triage_origin === TriageOrigin::Prefilter)
            ->count();
        $failed = $fresh->where('triage_status', JobCaptureTriageStatus::Failed)->count();

        $notifier->send($user, $forwarded, $barred, $failed);
    }

    /**
     * Ensure captures stuck in processing are marked failed if the job
     * itself fails (e.g., timeout or unhandled exception).
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
