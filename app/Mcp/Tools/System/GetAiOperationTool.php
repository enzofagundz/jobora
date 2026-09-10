<?php

namespace App\Mcp\Tools\System;

use App\Enums\AiOperationType;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\AiOperation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Consulta o estado de uma operação de IA (adequação de currículo, ATS ou competências) do usuário configurado. Não dispara IA.')]
class GetAiOperationTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $user = $this->user();

        $id = $request->get('id');

        $operation = $id !== null
            ? AiOperation::query()->where('user_id', $user->id)->whereKey((int) $id)->first()
            : $this->findByTarget($request, $user->id);

        if ($operation === null) {
            throw ToolException::notFound('Operação de IA não encontrada.');
        }

        return $this->ok($this->serialize($operation));
    }

    private function findByTarget(Request $request, int $userId): ?AiOperation
    {
        $postingId = $request->get('job_posting_id');
        $type = $request->get('type');

        if ($postingId === null || blank($type)) {
            throw ToolException::validation('Informe "id" ou, alternativamente, "job_posting_id" e "type".');
        }

        $operationType = AiOperationType::tryFrom((string) $type);

        if ($operationType === null) {
            throw ToolException::validation('Tipo de operação inválido.');
        }

        return AiOperation::query()
            ->where('user_id', $userId)
            ->where('job_posting_id', (int) $postingId)
            ->where('operation_type', $operationType)
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(AiOperation $operation): array
    {
        return [
            'id' => $operation->id,
            'type' => $operation->operation_type?->value,
            'status' => $operation->status?->value,
            'attempts' => $operation->attempts,
            'job_posting_id' => $operation->job_posting_id,
            'target_type' => $operation->target_type,
            'target_id' => $operation->target_id,
            'failure_code' => $operation->failure_code,
            'queued_at' => $operation->queued_at?->toIso8601String(),
            'started_at' => $operation->started_at?->toIso8601String(),
            'completed_at' => $operation->completed_at?->toIso8601String(),
            'failed_at' => $operation->failed_at?->toIso8601String(),
            'expires_at' => $operation->expires_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Id da operação de IA.'),
            'job_posting_id' => $schema->integer()->description('Id da vaga (busca alternativa por alvo).'),
            'type' => $schema->string()->description('Tipo: resume_adaptation, ats_score, ats_optimization ou strategic_competency_classification.'),
        ];
    }
}
