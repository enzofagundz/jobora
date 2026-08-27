<?php

namespace App\Support\Ai;

use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

/**
 * Serialize AI provider calls per provider:model using an atomic lock.
 * Avoids simultaneous requests that trigger provider rate limits.
 */
final class AiRequestGate
{
    /**
     * Execute the callback under a per-provider lock.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws RateLimitedException when the provider is busy
     */
    public function run(string $provider, string $model, callable $callback): mixed
    {
        $store = config('cache.lock_store', config('cache.default', 'file'));
        // Fallback to file if configured store does not exist
        $stores = config('cache.stores', []);

        if (! isset($stores[$store])) {
            $store = 'file';
        }

        $key = "ai:gate:{$provider}:{$model}";
        $seconds = 130;

        try {
            $cache = Cache::store($store);
        } catch (Throwable) {
            $cache = Cache::store('file');
        }

        $lock = $cache->lock($key, $seconds);

        // Try to acquire quickly; block up to 3s to serialize concurrent calls
        $acquired = false;

        try {
            $acquired = $lock->block(3);
        } catch (Throwable) {
            $acquired = $lock->get();
        }

        if (! $acquired) {
            throw RateLimitedException::forProvider($provider, 0, new \RuntimeException('AI provider busy [concurrent lock].'));
        }

        try {
            return $callback();
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // ignore
            }
        }
    }

    /**
     * Attempt to acquire a lock without blocking, for synchronous UI actions.
     * Returns false if not acquired.
     */
    public function tryAcquire(string $provider, string $model, int $seconds = 130): mixed
    {
        $store = config('cache.lock_store', config('cache.default', 'file'));
        $stores = config('cache.stores', []);

        if (! isset($stores[$store])) {
            $store = 'file';
        }

        try {
            $cache = Cache::store($store);
        } catch (Throwable) {
            $cache = Cache::store('file');
        }

        $key = "ai:gate:{$provider}:{$model}";

        return $cache->lock($key, $seconds);
    }
}
