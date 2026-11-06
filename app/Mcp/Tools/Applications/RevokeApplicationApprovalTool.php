<?php

namespace App\Mcp\Tools\Applications;

use App\Mcp\Support\AgentIntegrationContext;
use App\Mcp\Tools\JoboraTool;
use App\Services\ApplicationApprovalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Revoga consentimento do proprietário sem apagar histórico nem autorizar envio. Replay de mensagem não renova ou reativa consentimento revogado.')]
class RevokeApplicationApprovalTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $input = Validator::validate(['input' => $request->all()], ['input' => ['required', 'array:id'], 'input.id' => ['required', 'uuid']])['input'];
        $actor = app()->bound(AgentIntegrationContext::class)
            ? ['source' => 'mcp', 'integration_id' => app(AgentIntegrationContext::class)->integration->id] : ['source' => 'stdio'];

        return $this->ok(app(ApplicationApprovalService::class)->revoke($this->user(), $input['id'], $actor));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->string()->required()];
    }
}
