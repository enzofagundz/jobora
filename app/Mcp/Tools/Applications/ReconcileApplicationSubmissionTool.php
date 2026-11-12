<?php

namespace App\Mcp\Tools\Applications;

use App\Mcp\Tools\JoboraTool;
use App\Services\ApplicationSubmissionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Reconcilia somente unknown, com revisão esperada. Observação inconclusiva mantém unknown e bloqueio. Resultado definitivo exige prova positiva; falha exige ausência de envio e executor antigo encerrado. Não autoriza nova execução nem presume exactly-once.')]
class ReconcileApplicationSubmissionTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        return $this->ok(app(ApplicationSubmissionService::class)->reconcile($this->user(), $request->all()));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['attempt_id' => $schema->string()->required(), 'expected_revision' => $schema->integer()->required(), 'event_key' => $schema->string()->required(),
            'outcome' => $schema->string()->enum(['succeeded', 'failed', 'unknown'])->required(),
            'evidence' => $schema->object(['kind' => $schema->string()->enum(['submission_receipt', 'not_submitted', 'ambiguous'])->required(),
                'reference' => $schema->string()->description('Obrigatória para resultado definitivo.'), 'details' => $schema->string()])->required(), 'executor_stopped' => $schema->boolean()];
    }
}
