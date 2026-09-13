<?php

namespace App\Mcp\Tools\Competencies;

use App\Mcp\Support\McpUserResolver;
use App\Mcp\Tools\JoboraTool;
use App\Services\AiOperationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld]
#[Description('Enfileira a classificação estratégica das competências do usuário configurado (core/expansion/differentiation) por IA. Assíncrona: acompanhe com get_ai_operation.')]
class ClassifyProfessionalCompetenciesTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly AiOperationService $operations,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $operation = $this->operations->enqueueStrategicCompetencyClassification($this->user());

        return $this->ok([
            'operation_id' => $operation->id,
            'status' => $operation->status?->value,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
