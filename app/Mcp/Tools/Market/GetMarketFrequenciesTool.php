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
#[Description('Frequência dos conceitos nas vagas confirmadas do usuário configurado: quantas vagas citam cada item e o percentual. Filtra por categoria e período.')]
class GetMarketFrequenciesTool extends JoboraTool
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
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $user = $this->user();
        $category = isset($validated['category']) ? ConceptCategory::tryFrom($validated['category']) : null;
        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;

        $items = $this->market->relativeFrequencies($user, $category, $from, $to);
        $limit = (int) ($validated['limit'] ?? 100);

        return $this->ok([
            'total_confirmed' => $this->market->totalConfirmed($user, $from, $to),
            'items' => array_values(array_slice($items, 0, $limit)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()->description('skill, tool, methodology, requirement ou experience.'),
            'from' => $schema->string()->description('Data inicial de coleta (ISO-8601).'),
            'to' => $schema->string()->description('Data final de coleta (ISO-8601, exclusiva).'),
            'limit' => $schema->integer()->min(1)->max(500)->description('Máximo de itens (padrão 100).'),
        ];
    }
}
