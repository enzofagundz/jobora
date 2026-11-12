<?php

namespace App\Mcp\Tools\System;

use App\Enums\AiOperationType;
use App\Mcp\Support\AgentIntegrationContext;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\AiOperation;
use App\Models\MatchingEvaluation;
use App\Models\ProfileImportProposal;
use App\Models\PublicationTriageResult;
use App\Services\MatchingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Consulta operações e histórico de triagem, adequação de currículo, ATS ou competências do usuário configurado. Não dispara IA.')]
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
        $publicationId = $request->get('publication_id');
        $type = $request->get('type');

        if (($postingId === null) === ($publicationId === null) || blank($type)) {
            throw ToolException::validation('Informe "id" ou um alvo (job_posting_id ou publication_id) e "type".');
        }

        $operationType = AiOperationType::tryFrom((string) $type);

        if ($operationType === null) {
            throw ToolException::validation('Tipo de operação inválido.');
        }

        $query = AiOperation::query()
            ->where('user_id', $userId)
            ->where('operation_type', $operationType);

        if ($publicationId !== null) {
            $query->where('target_type', 'publication')->where('target_id', (int) $publicationId);
        } else {
            $query->where('job_posting_id', (int) $postingId);
        }

        return $query->latest('id')->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(AiOperation $operation): array
    {
        $triageResults = $operation->operation_type === AiOperationType::PublicationTriage
            ? $operation->publicationTriageResults()->orderBy('id')->get()
            : collect();
        $publicationTriageHistory = $operation->operation_type === AiOperationType::PublicationTriage
            ? AiOperation::query()
                ->where('user_id', $operation->user_id)
                ->where('operation_type', AiOperationType::PublicationTriage)
                ->where('target_type', 'publication')
                ->where('target_id', $operation->target_id)
                ->with('publicationTriageResults')
                ->orderBy('id')
                ->get()
                ->flatMap(fn (AiOperation $historicalOperation): array => $historicalOperation->publicationTriageResults
                    ->map(fn (PublicationTriageResult $result): array => [
                        'operation_id' => $historicalOperation->id,
                        'operation_status' => $historicalOperation->status?->value,
                        ...$this->serializeTriageResult($result),
                    ])
                    ->all())
                ->values()
                ->all()
            : [];

        return [
            'id' => $operation->id,
            'matching_evaluation_id' => $operation->operation_type === AiOperationType::Matching
                ? MatchingEvaluation::query()->where('ai_operation_id', $operation->id)->where('run_token', $operation->run_token)->value('id') : null,
            'profile_import_proposal_id' => $operation->operation_type === AiOperationType::ProfileImport
                ? ProfileImportProposal::query()->where('ai_operation_id', $operation->id)->where('run_token', $operation->run_token)->latest('id')->value('id') : null,
            'retry_allowed' => $operation->operation_type === AiOperationType::Matching
                ? $this->matchingRetryAllowed($operation)
                : $operation->operation_type === AiOperationType::ProfileImport && $operation->status?->value === 'failed' && $operation->attempts < 3 && $operation->failure_code !== 'authorization_revoked',
            'type' => $operation->operation_type?->value,
            'status' => $operation->status?->value,
            'attempts' => $operation->attempts,
            'job_posting_id' => $operation->job_posting_id,
            'target_type' => $operation->target_type,
            'target_id' => $operation->target_id,
            'publication_id' => $operation->target_type === 'publication' ? $operation->target_id : null,
            'failure_code' => $operation->failure_code,
            'queued_at' => $operation->queued_at?->toIso8601String(),
            'started_at' => $operation->started_at?->toIso8601String(),
            'completed_at' => $operation->completed_at?->toIso8601String(),
            'failed_at' => $operation->failed_at?->toIso8601String(),
            'expires_at' => $operation->expires_at?->toIso8601String(),
            'publication_triage_result' => $triageResults->last() === null
                ? null
                : $this->serializeTriageResult($triageResults->last()),
            'publication_triage_results' => $triageResults
                ->map(fn ($result): array => $this->serializeTriageResult($result))
                ->values()
                ->all(),
            'publication_triage_history' => $publicationTriageHistory,
        ];
    }

    private function matchingRetryAllowed(AiOperation $operation): bool
    {
        $candidate = clone $operation;
        $actor = app()->bound(AgentIntegrationContext::class)
            ? ['source' => 'mcp', 'integration_id' => app(AgentIntegrationContext::class)->integration->id]
            : ['source' => 'stdio'];
        $candidate->input_snapshot = [...($operation->input_snapshot ?? []), 'requester_actor' => $actor];

        return app(MatchingService::class)->retryAllowed($this->user(), $candidate);
    }

    private function serializeTriageResult(PublicationTriageResult $result): array
    {
        return [
            'id' => $result->id,
            'publication_id' => $result->publication_id,
            'content_version' => $result->content_version,
            'attempt' => $result->attempt,
            'status' => $result->status,
            'provider' => $result->provider,
            'model' => $result->model,
            'schema_version' => $result->schema_version,
            'output' => $result->output,
            'prompt_tokens' => $result->prompt_tokens,
            'completion_tokens' => $result->completion_tokens,
            'error_code' => $result->error_code,
            'clef_result_id' => $result->clef_result_id,
            'clef_status' => $result->clef_status,
            'clef_bypassed' => $result->clef_bypassed,
            'clef_bypass_reason' => $result->clef_bypass_reason,
            'requested_by' => $result->requested_by,
            'created_at' => $result->created_at?->toIso8601String(),
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
            'publication_id' => $schema->integer()->description('Id da publicação para consultar sua triagem. Use type=publication_triage.'),
            'type' => $schema->string()->description('Tipo de operação, incluindo publication_triage.'),
        ];
    }
}
