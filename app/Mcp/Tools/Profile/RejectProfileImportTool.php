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

#[Description('Rejeita somente a proposta privada. Não altera perfil nem currículo.')]
class RejectProfileImportTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $arguments = Validator::validate(['arguments' => $request->all()], [
            'arguments' => ['required', 'array:id'], 'arguments.id' => ['required', 'integer', 'min:1'],
        ])['arguments'];
        $actor = app()->bound(AgentIntegrationContext::class)
            ? ['source' => 'mcp', 'integration_id' => app(AgentIntegrationContext::class)->integration->id] : ['source' => 'stdio'];

        return $this->ok(app(ProfileImportService::class)->reject($this->user(), (string) $arguments['id'], $actor));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->string()->required()];
    }
}
