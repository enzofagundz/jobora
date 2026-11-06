<?php

namespace App\Mcp\Tools\Applications;

use App\Mcp\Tools\JoboraTool;
use App\Services\ApplicationPreparationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Consulta snapshot imutável, destino, pendências e validade da preparação de candidatura do usuário autenticado.')]
class GetApplicationPreparationTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $input = $request->validate(['id' => ['required', 'uuid']]);

        return $this->ok(app(ApplicationPreparationService::class)->get($this->user(), $input['id']));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->string()->required()];
    }
}
