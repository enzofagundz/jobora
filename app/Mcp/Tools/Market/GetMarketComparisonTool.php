<?php

namespace App\Mcp\Tools\Market;

use App\Enums\ConceptCategory;
use App\Mcp\Support\McpUserResolver;
use App\Mcp\Tools\JoboraTool;
use App\Services\MarketAnalysisService;
use App\Services\MarketComparisonService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Cruza os conceitos das vagas confirmadas com o currículo do usuário configurado: tabela item × frequência × presente/ausente e as principais lacunas (frequentes nas vagas e ausentes no currículo).')]
class GetMarketComparisonTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly MarketComparisonService $comparison,
        private readonly MarketAnalysisService $market,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'category' => ['nullable', Rule::enum(ConceptCategory::class)],
            'gaps_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $this->user();
        $category = isset($validated['category']) ? ConceptCategory::tryFrom($validated['category']) : null;
        $gapsLimit = (int) ($validated['gaps_limit'] ?? 10);

        return $this->ok([
            'total_confirmed' => $this->market->totalConfirmed($user),
            'table' => $this->comparison->table($user, $category),
            'gaps' => $this->comparison->gaps($user, $gapsLimit),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()->description('skill, tool, methodology, requirement ou experience.'),
            'gaps_limit' => $schema->integer()->min(1)->max(100)->description('Máximo de lacunas (padrão 10).'),
        ];
    }
}
