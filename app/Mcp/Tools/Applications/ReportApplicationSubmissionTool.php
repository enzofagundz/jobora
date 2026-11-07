<?php

namespace App\Mcp\Tools\Applications;

use App\Mcp\Tools\JoboraTool;
use App\Services\ApplicationSubmissionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Registra resultado histórico atestado pelo executor remoto. Exige evidência positiva de sucesso ou ausência de envio. Timeout ou conexão ambígua significam unknown. Não altera acompanhamento V1.')]
class ReportApplicationSubmissionTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        return $this->ok(app(ApplicationSubmissionService::class)->report($this->user(), $request->all()));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['attempt_id' => $schema->string()->required(), 'event_key' => $schema->string()->required(),
            'outcome' => $schema->string()->enum(['succeeded', 'failed', 'unknown'])->required(), 'occurred_at' => $schema->string()->required(),
            'evidence' => $schema->object(['kind' => $schema->string()->enum(['submission_receipt', 'not_submitted', 'ambiguous'])->required(),
                'reference' => $schema->string(), 'details' => $schema->string()])->required(),
            'error_category' => $schema->string()->enum(['rejected', 'validation', 'timeout', 'connection', 'executor'])];
    }
}
