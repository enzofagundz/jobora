<?php

namespace App\Mcp\Support;

use App\Enums\ResumeLanguage;
use App\Models\JobPosting;

/**
 * Representação pública de uma vaga nas respostas das tools MCP.
 */
final class JobPostingPresenter
{
    /**
     * Resumo de vaga usado em listagens. Tolera contadores/relações já
     * carregados (withCount/with) para evitar N+1.
     *
     * @return array<string, mixed>
     */
    public static function summary(JobPosting $posting): array
    {
        return [
            'id' => $posting->id,
            'role' => $posting->role,
            'company' => $posting->company,
            'status' => $posting->status?->value,
            'collected_at' => $posting->collected_at?->toIso8601String(),
            'source_url' => $posting->source_url,
            'items_count' => $posting->items_count ?? $posting->items()->count(),
            'has_adaptation' => $posting->relationLoaded('resumeAdaptations')
                ? $posting->resumeAdaptations->isNotEmpty()
                : $posting->resumeAdaptations()->exists(),
            'application_status' => $posting->relationLoaded('application')
                ? $posting->application?->status?->value
                : $posting->application()->first()?->status?->value,
        ];
    }

    /**
     * Detalhe completo da vaga: resumo + descrição, sugestão pendente,
     * itens, métodos de candidatura, adaptação, candidatura e última tentativa.
     *
     * @return array<string, mixed>
     */
    public static function detail(JobPosting $posting, ResumeLanguage $language): array
    {
        $adaptation = $posting->resumeAdaptation($language)->first();
        $application = $posting->application;
        $attempt = $posting->latestAttempt();

        return [
            ...self::summary($posting),
            'description' => $posting->description,
            'suggested_data' => $posting->suggested_data,
            'error_message' => $posting->error_message,
            'items' => $posting->items
                ->map(fn ($item): array => [
                    'category' => $item->category?->value,
                    'name' => $item->name,
                    'source' => $item->source?->value,
                ])
                ->values()
                ->all(),
            'application_methods' => $posting->applicationMethods
                ->map(fn ($method): array => [
                    'type' => $method->type?->value,
                    'platform' => $method->platform,
                    'url' => $method->url,
                    'email' => $method->email,
                    'phone' => $method->phone,
                    'instructions' => $method->instructions,
                    'source' => $method->source?->value,
                ])
                ->values()
                ->all(),
            'adaptation' => $adaptation === null ? null : [
                'is_ats_optimized' => (bool) $adaptation->is_ats_optimized,
                'ats_score' => $adaptation->ats_score,
                'updated_at' => $adaptation->updated_at?->toIso8601String(),
            ],
            'application' => $application === null ? null : [
                'id' => $application->id,
                'status' => $application->status?->value,
            ],
            'latest_attempt' => $attempt === null ? null : [
                'attempt_no' => $attempt->attempt_no,
                'status' => $attempt->status,
                'created_at' => $attempt->created_at?->toIso8601String(),
            ],
        ];
    }
}
