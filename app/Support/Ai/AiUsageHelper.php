<?php

namespace App\Support\Ai;

use Throwable;

final class AiUsageHelper
{
    /**
     * @return array<string, int>
     */
    public static function extract(mixed $usage): array
    {
        try {
            if ($usage === null) {
                return self::empty();
            }

            return [
                'prompt_tokens' => (int) ($usage->promptTokens ?? $usage->prompt_tokens ?? 0),
                'completion_tokens' => (int) ($usage->completionTokens ?? $usage->completion_tokens ?? 0),
                'cache_write_input_tokens' => (int) ($usage->cacheWriteInputTokens ?? $usage->cache_write_input_tokens ?? 0),
                'cache_read_input_tokens' => (int) ($usage->cacheReadInputTokens ?? $usage->cache_read_input_tokens ?? 0),
                'reasoning_tokens' => (int) ($usage->reasoningTokens ?? $usage->reasoning_tokens ?? 0),
            ];
        } catch (Throwable) {
            return self::empty();
        }
    }

    /**
     * @return array<string, int>
     */
    private static function empty(): array
    {
        return [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'cache_write_input_tokens' => 0,
            'cache_read_input_tokens' => 0,
            'reasoning_tokens' => 0,
        ];
    }
}
