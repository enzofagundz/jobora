<?php

namespace App\Mcp\Tools\Profile;

use App\Mcp\Tools\JoboraTool;
use App\Services\ProfileImportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Consulta proposta privada, mudanças, evidências e sinais de versão obsoleta. Não dispara IA.')]
class GetProfileImportTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $arguments = Validator::validate(['arguments' => $request->all()], [
            'arguments' => ['required', 'array:id'], 'arguments.id' => ['required', 'integer', 'min:1'],
        ])['arguments'];

        return $this->ok(app(ProfileImportService::class)->get($this->user(), (string) $arguments['id']));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->string()->description('Id da proposta.')->required()];
    }
}
