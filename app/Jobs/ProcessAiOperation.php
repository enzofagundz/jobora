<?php

namespace App\Jobs;

use App\Enums\AiOperationType;
use App\Models\AiOperation;
use App\Services\AiOperationService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessAiOperation implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 60];

    public int $timeout = 130;

    public function uniqueFor(): int
    {
        return AiOperation::query()->whereKey($this->operationId)->first()?->operation_type === AiOperationType::PublicationTriage
            ? 300
            : 600;
    }

    public function __construct(
        public int $operationId,
        public string $runToken,
        public ?string $attemptToken = null,
        public ?string $recoveryToken = null,
    ) {}

    public function uniqueId(): string
    {
        return 'ai-operation:'.$this->operationId.':'.$this->runToken.':'.($this->recoveryToken ?? 'initial');
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

    public function handle(AiOperationService $service): void
    {
        $service->process($this->operationId, $this->runToken, $this->attemptToken);
    }

    public function failed(?Throwable $exception): void
    {
        app(AiOperationService::class)->fail(
            $this->operationId,
            $this->runToken,
            $exception,
            $this->attemptToken,
            $this->attempts(),
        );
    }
}
