<?php

namespace App\Mcp\Tools\Applications;

use App\Mcp\Tools\JoboraTool;
use App\Services\ApplicationSubmissionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Consome aprovação uma única vez após revalidar material e permissões. Só a primeira resposta pode autorizar execução externa até execute_before. Replay nunca autoriza. Não executa envio nem garante exactly-once.')]
class ConsumeApplicationSubmissionTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        return $this->ok(app(ApplicationSubmissionService::class)->consume($this->user(), $request->all()));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['attempt_id' => $schema->string()->required(), 'expected_revision' => $schema->integer()->required(),
            'expected_fingerprint' => $schema->string()->required(), 'event_key' => $schema->string()->required()];
    }
}
