<?php

namespace Tests\Feature\Mcp;

use App\Enums\ConceptCategory;
use App\Models\JobPosting;
use App\Models\JobPostingItem;
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
}
