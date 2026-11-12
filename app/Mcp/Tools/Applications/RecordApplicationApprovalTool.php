<?php

namespace App\Mcp\Tools\Applications;

use App\Mcp\Support\AgentIntegrationContext;
use App\Mcp\Tools\JoboraTool;
use App\Services\ApplicationApprovalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Registra somente consentimento humano atestado pelo Hermes remoto para fingerprint congelado e identidade Telegram previamente confirmada. Não autoriza envio, não envia e não cria tentativa. O Jobora confia no Hermes, sem prova independente da conversa. confirm=true não substitui consentimento.')]
class RecordApplicationApprovalTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $actor = app()->bound(AgentIntegrationContext::class)
            ? ['source' => 'mcp', 'integration_id' => app(AgentIntegrationContext::class)->integration->id] : ['source' => 'stdio'];

        return $this->ok(app(ApplicationApprovalService::class)->record($this->user(), $request->all(), $actor));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['preparation_id' => $schema->string()->required(), 'expected_fingerprint' => $schema->string()->required(),
            'telegram_user_id' => $schema->string()->required(), 'chat_id' => $schema->string()->required(), 'message_id' => $schema->string()->required(),
            'approved_at' => $schema->string()->description('Data ISO 8601 com timezone; até cinco minutos no futuro.')->required(),
            'expires_at' => $schema->string()->description('Data ISO 8601: após agora, limitada pelo TTL backend e artefatos.')->required()];
    }
}
