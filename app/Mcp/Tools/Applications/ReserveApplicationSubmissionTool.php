<?php

namespace App\Mcp\Tools\Applications;

use App\Mcp\Tools\JoboraTool;
use App\Services\ApplicationSubmissionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Reserva uma tentativa para aprovação vigente. Não autoriza nem executa envio. Repita a mesma chave para recuperar a tentativa.')]
class ReserveApplicationSubmissionTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        return $this->ok(app(ApplicationSubmissionService::class)->reserve($this->user(), $request->all()));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['approval_id' => $schema->string()->required(), 'expected_fingerprint' => $schema->string()->required(), 'idempotency_key' => $schema->string()->required()];
    }
}
