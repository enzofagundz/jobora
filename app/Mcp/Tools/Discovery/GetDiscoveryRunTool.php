<?php

namespace App\Mcp\Tools\Discovery;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Obtém uma execução de descoberta por id ou execution_id.')]
class GetDiscoveryRunTool extends DiscoveryRunTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['nullable', 'integer'],
            'execution_id' => ['nullable', 'string', 'max:64', 'required_without:id'],
        ]);

        $id = $validated['id'] ?? $validated['execution_id'];

        return $this->ok(['run' => $this->presentRun($this->runs->get($this->user(), $id))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Id interno da execução.'),
            'execution_id' => $schema->string()->description('Identificador opaco da execução.'),
        ];
    }
}
