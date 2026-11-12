<?php

namespace App\Mcp\Tools\Profile;

use App\Mcp\Support\AgentIntegrationContext;
use App\Mcp\Tools\JoboraTool;
use App\Services\ProfileImportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Confirma proposta revisada somente sobre a versão original do perfil, preservando critérios pessoais.')]
class ConfirmProfileImportTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $arguments = Validator::validate(['arguments' => $request->all()], [
            'arguments' => ['required', 'array:id,expected_version,confirm'],
            'arguments.id' => ['required', 'integer', 'min:1'],
            'arguments.expected_version' => ['required', 'integer:strict', 'min:0', 'max:2147483644'],
            'arguments.confirm' => ['sometimes', 'boolean:strict'],
        ])['arguments'];
        $this->requireConfirm($request);
        $actor = app()->bound(AgentIntegrationContext::class)
            ? ['source' => 'mcp', 'integration_id' => app(AgentIntegrationContext::class)->integration->id] : ['source' => 'stdio'];

        return $this->ok(app(ProfileImportService::class)->confirm($this->user(), (string) $arguments['id'], $arguments['expected_version'], $actor));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->string()->required(), 'expected_version' => $schema->integer()->min(0)->required(), 'confirm' => $schema->boolean()->required()];
    }
}
