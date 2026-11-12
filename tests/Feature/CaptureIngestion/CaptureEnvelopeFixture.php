<?php

namespace Tests\Feature\CaptureIngestion;

final class CaptureEnvelopeFixture
{
    /** @return array{format: string, version: int, capture: array{idempotency_key: string, execution_id: null, source: string, surface: string, initial_url: string, final_url: string, captured_at: string, coverage: array{status: string, reason: null, blocks_expected: int, blocks_captured: int}, html: string}, blocks: array{}} */
    public static function make(string $key): array
    {
        return ['format' => 'jobora.capture-envelope', 'version' => 1, 'capture' => [
            'idempotency_key' => $key, 'execution_id' => null, 'source' => 'linkedin', 'surface' => 'linkedin_publications',
            'initial_url' => 'https://www.linkedin.com/search/results/content/?keywords=php',
            'final_url' => 'https://www.linkedin.com/search/results/content/?keywords=php', 'captured_at' => now()->toISOString(),
            'coverage' => ['status' => 'complete', 'reason' => null, 'blocks_expected' => 0, 'blocks_captured' => 0],
            'html' => '<main data-capture-surface="linkedin_publications" data-capture-version="1"></main>',
        ], 'blocks' => []];
    }
}
