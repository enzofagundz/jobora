<?php

namespace Tests\Feature\Mcp;

use App\Enums\ConceptCategory;
use App\Models\JobPosting;
use App\Models\JobPostingItem;
use App\Models\ResumeAdaptation;
use App\Models\User;

/**
 * Helpers compartilhados dos testes de MCP. A identidade do servidor é
 * resolvida por config('jobora.mcp.user'), então os testes apontam a config
 * para o usuário desejado em vez de autenticar uma sessão.
 */
final class McpTestHelpers
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function user(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        config(['jobora.mcp.user' => $user->email]);

        return $user;
    }

    public static function item(JobPosting $posting, string $name, ConceptCategory $category = ConceptCategory::Skill): JobPostingItem
    {
        return JobPostingItem::factory()->for($posting, 'jobPosting')->create([
            'category' => $category,
            'name' => $name,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function adaptation(User $user, JobPosting $posting, array $attributes = []): ResumeAdaptation
    {
        return ResumeAdaptation::create(array_merge([
            'job_posting_id' => $posting->id,
            'adapted_content' => 'Currículo adaptado com PHP.',
            'changes' => ['Resumo reescrito'],
            'covered_keywords' => ['PHP'],
            'missing_requirements' => ['React'],
            'alerts' => [],
        ], $attributes));
    }
}
