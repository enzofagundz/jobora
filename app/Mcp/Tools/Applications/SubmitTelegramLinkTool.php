<?php

namespace App\Mcp\Tools\Applications;

use App\Mcp\Support\AgentIntegrationContext;
use App\Mcp\Tools\JoboraTool;
use App\Services\TelegramIdentityService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Hermes remoto apresenta o desafio e IDs canônicos da conversa privada Telegram. Cria identidade pendente; o usuário deve confirmar os dados no painel com a senha real. O Jobora confia neste atestado Hermes, sem prova independente contra integração comprometida.')]
class SubmitTelegramLinkTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $actor = app()->bound(AgentIntegrationContext::class)
            ? ['source' => 'mcp', 'integration_id' => app(AgentIntegrationContext::class)->integration->id] : ['source' => 'stdio'];

        return $this->ok(app(TelegramIdentityService::class)->submitChallenge($this->user(), $request->all(), $actor));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['challenge' => $schema->string()->required(), 'telegram_user_id' => $schema->string()->required(),
            'chat_id' => $schema->string()->required(), 'message_id' => $schema->string()->required(), 'chat_type' => $schema->string()->enum(['private'])->required()];
    }
}
