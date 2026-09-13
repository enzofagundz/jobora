<?php

namespace App\Mcp\Tools\Market;

use App\Enums\ConceptCategory;
use App\Mcp\Support\McpUserResolver;
use App\Mcp\Tools\JoboraTool;
use App\Services\MarketAnalysisService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Combinações recorrentes de conceitos da mesma categoria nas vagas confirmadas do usuário configurado (pares que aparecem juntos), ordenadas por frequência.')]
class GetMarketPairsTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly MarketAnalysisService $market,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'category' => ['nullable', Rule::enum(ConceptCategory::class)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $category = isset($validated['category']) ? ConceptCategory::tryFrom($validated['category']) : null;
        $limit = (int) ($validated['limit'] ?? 20);

        return $this->ok([
            'items' => $this->market->pairsByCategory($this->user(), $category, $limit),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()->description('skill, tool, methodology, requirement ou experience.'),
            'limit' => $schema->integer()->min(1)->max(100)->description('Máximo de pares (padrão 20).'),
        ];
    }
}
