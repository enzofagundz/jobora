<?php

namespace App\Support\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

final class TransientAiClassifier
{
    public static function isTransient(Throwable $e): bool
    {
        if ($e instanceof RateLimitedException
            || $e instanceof ProviderOverloadedException
            || $e instanceof InsufficientCreditsException
            || $e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof RequestException) {
            $status = $e->response?->status();

            if ($status === 429 || $status === 402 || $status === 503 || $status === 529) {
                return true;
            }

            // Fallback: any 5xx as transient
            if ($status !== null && $status >= 500 && $status < 600) {
                return true;
            }

            return true;
        }

        if ($e instanceof AiException) {
            // Generic AiException from gateway (rate limit, overloaded, etc.)
            return true;
        }

        return false;
    }

    /**
     * @return array{retry_after: int|null, status: int|null}
     */
    public static function metadata(Throwable $e): array
    {
        $retryAfter = null;
        $status = null;

        if ($e instanceof RequestException && $e->response) {
            $status = $e->response->status();
            $header = $e->response->header('Retry-After');

            if ($header !== null && $header !== '') {
                $retryAfter = is_numeric($header) ? (int) $header : null;
            }
        }

        if ($e instanceof RateLimitedException || $e instanceof ProviderOverloadedException) {
            $previous = $e->getPrevious();

            if ($previous instanceof RequestException && $previous->response) {
                $status = $previous->response->status();
                $header = $previous->response->header('Retry-After');

                if ($header !== null && $header !== '') {
                    $retryAfter = is_numeric($header) ? (int) $header : null;
                }
            }
        }

        return [
            'retry_after' => $retryAfter,
            'status' => $status,
        ];
    }
}
