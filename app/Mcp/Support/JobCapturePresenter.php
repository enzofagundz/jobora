<?php

namespace App\Mcp\Support;

use App\Models\JobCapture;

/**
 * Representação pública de uma captura nas respostas das tools MCP.
 */
final class JobCapturePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(JobCapture $capture): array
    {
        return [
            'id' => $capture->id,
            'source' => $capture->source,
            'external_id' => $capture->external_id,
            'url' => $capture->url,
            'search_url' => $capture->search_url,
            'title' => $capture->title,
            'description' => $capture->description,
            'company' => $capture->company,
            'location' => $capture->location,
            'author_name' => $capture->author_name,
            'author_profile_url' => $capture->author_profile_url,
            'captured_at' => $capture->captured_at?->toIso8601String(),
            'triage_status' => $capture->triage_status?->value,
            'triage_result' => $capture->triage_result?->value,
            'triage_reason' => $capture->triage_reason,
            'triage_error' => $capture->triage_error,
            'job_posting_id' => $capture->job_posting_id,
        ];
    }
}
