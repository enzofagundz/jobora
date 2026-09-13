<?php

namespace App\Mcp\Tools\Market;

use App\Enums\AnalysisPeriod;
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
#[Description('Evolução temporal dos conceitos nas vagas confirmadas do usuário configurado: série por período, comparação entre janelas e aviso de dados insuficientes.')]
class GetMarketTrendsTool extends JoboraTool
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
            'period' => ['nullable', Rule::enum(AnalysisPeriod::class)],
            'category' => ['nullable', Rule::enum(ConceptCategory::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $user = $this->user();
        $period = AnalysisPeriod::tryFrom($validated['period'] ?? 'month') ?? AnalysisPeriod::Month;
        $category = isset($validated['category']) ? ConceptCategory::tryFrom($validated['category']) : null;
        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;

        return $this->ok([
            'period' => $period->value,
            'series' => $this->market->periodSeries($user, $period, $category, $from, $to),
            'comparison' => $this->market->periodComparison($user, $period, $category),
            'insufficient_reason' => $this->market->periodInsufficientReason($user, $period, $category),
            'labels' => $this->market->periodComparisonLabels($period),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()->description('week, month (padrão), quarter ou year.'),
            'category' => $schema->string()->description('skill, tool, methodology, requirement ou experience.'),
            'from' => $schema->string()->description('Data inicial (ISO-8601).'),
            'to' => $schema->string()->description('Data final (ISO-8601, exclusiva).'),
        ];
    }
}
