<?php

namespace App\Mcp\Tools\Captures;

use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Services\AiOperationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld]
#[Description('Solicita triagem estruturada de uma publicação elegível pela decisão Clef atual. Acompanhe a operação com get_ai_operation. Não inicia análise de currículo ou matching.')]
class RequestPublicationTriageTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly AiOperationService $operations,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'publication_id' => ['required', 'integer', 'min:1'],
            'content_version' => ['required', 'integer', 'min:1'],
            'retry' => ['sometimes', 'boolean'],
            'retry_clef' => ['sometimes', 'boolean'],
            'bypass_clef' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500', 'required_if:bypass_clef,true'],
        ]);

        if (($validated['bypass_clef'] ?? false) && blank($validated['reason'] ?? null)) {
            throw ToolException::validation('Informe motivo para ignorar a decisão Clef.');
        }

        if ($validated['retry_clef'] ?? false) {
            if (($validated['retry'] ?? false) || ($validated['bypass_clef'] ?? false) || filled($validated['reason'] ?? null)) {
                throw ToolException::validation('Retry de Clef não pode combinar com retry ou bypass de triagem.');
            }

            $clef = $this->operations->retryPublicationClef(
                $this->user(),
                (int) $validated['publication_id'],
                (int) $validated['content_version'],
            );

            return $this->ok([
                'publication_id' => $clef->publication_id,
                'content_version' => $clef->content_version,
                'clef_status' => $clef->status,
                'clef_attempt' => $clef->attempt,
            ]);
        }

        $operation = $this->operations->enqueuePublicationTriage(
            $this->user(),
            (int) $validated['publication_id'],
            (int) $validated['content_version'],
            (bool) ($validated['retry'] ?? false),
            (bool) ($validated['bypass_clef'] ?? false),
            $validated['reason'] ?? null,
        );

        return $this->ok([
            'operation_id' => $operation->id,
            'type' => $operation->operation_type->value,
            'status' => $operation->status->value,
            'publication_id' => $operation->target_id,
            'content_version' => $validated['content_version'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'publication_id' => $schema->integer()->min(1)->description('Id da publicação do usuário.'),
            'content_version' => $schema->integer()->min(1)->description('Versão atual da publicação, devolvida por list_publications.'),
            'retry' => $schema->boolean()->description('Reinicia uma operação com falha usando seu snapshot persistido, sem navegar ou repetir Clef.'),
            'retry_clef' => $schema->boolean()->description('Repete somente Clef para publicação inconclusiva na versão informada. Não solicita triagem LLM.'),
            'bypass_clef' => $schema->boolean()->description('Reavalia uma publicação barrada por Clef. Exige reason e registra decisão original e ator.'),
            'reason' => $schema->string()->max(500)->description('Motivo obrigatório para bypass explícito da decisão Clef.'),
        ];
    }
}
