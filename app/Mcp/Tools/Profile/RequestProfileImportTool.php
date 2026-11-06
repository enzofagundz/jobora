<?php

namespace App\Mcp\Tools\Profile;

use App\Enums\ResumeLanguage;
use App\Mcp\Support\AgentIntegrationContext;
use App\Mcp\Tools\JoboraTool;
use App\Services\AiOperationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Solicita uma proposta revisável de perfil a partir de um snapshot do currículo. Não altera o perfil.')]
class RequestProfileImportTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $arguments = Validator::validate(['arguments' => $request->all()], [
            'arguments' => ['required', 'array:language,expected_version,retry'],
            'arguments.expected_version' => ['required', 'integer:strict', 'min:0', 'max:2147483644'],
            'arguments.language' => ['sometimes', 'required', 'in:pt-BR,en'],
            'arguments.retry' => ['sometimes', 'boolean:strict'],
        ])['arguments'];
        $actor = app()->bound(AgentIntegrationContext::class)
            ? ['source' => 'mcp', 'integration_id' => app(AgentIntegrationContext::class)->integration->id] : ['source' => 'stdio'];
        $operation = app(AiOperationService::class)->enqueueProfileImport($this->user(), ResumeLanguage::fromInput($arguments['language'] ?? 'pt-BR'), $arguments['expected_version'], $actor, $arguments['retry'] ?? false);

        return $this->ok(['operation_id' => $operation->id, 'status' => $operation->status->value]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'language' => $schema->string()->enum(['pt-BR', 'en'])->default('pt-BR'),
            'expected_version' => $schema->integer()->min(0)->required(),
            'retry' => $schema->boolean()->default(false),
        ];
    }
}
