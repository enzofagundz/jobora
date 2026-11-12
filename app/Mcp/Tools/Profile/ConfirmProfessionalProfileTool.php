<?php

namespace App\Mcp\Tools\Profile;

use App\Mcp\Support\AgentIntegrationContext;
use App\Mcp\Tools\JoboraTool;
use App\Services\ProfessionalProfileService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Confirma explicitamente o rascunho persistido na versão esperada. Não aceita documento nem metadados de ator.')]
class ConfirmProfessionalProfileTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = Validator::validate(['arguments' => $request->all()], [
            'arguments' => ['required', 'array:expected_version,confirm'],
            'arguments.expected_version' => ['required', 'integer:strict', 'min:0', 'max:2147483646'],
            'arguments.confirm' => ['sometimes', 'boolean:strict'],
        ])['arguments'];
        $this->requireConfirm($request);
        $actor = ['source' => 'stdio'];

        if (app()->bound(AgentIntegrationContext::class)) {
            $actor = ['source' => 'mcp', 'integration_id' => app(AgentIntegrationContext::class)->integration->id];
        }

        return $this->ok(app(ProfessionalProfileService::class)->confirm($this->user(), $validated['expected_version'], $actor));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'expected_version' => $schema->integer()->min(0)->max(2147483646)->required(),
            'confirm' => $schema->boolean()->description('Envie true somente após confirmação explícita do usuário.')->required(),
        ];
    }
}
