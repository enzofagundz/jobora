<?php

namespace App\Mcp\Tools\Discovery;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Obtém uma fonte de descoberta do usuário pelo id.')]
class GetDiscoverySourceTool extends DiscoverySourceTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['required', 'integer'],
        ]);

        return $this->ok(['source' => $this->present($this->sources->get($this->user(), (int) $validated['id']))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Id da fonte.'),
        ];
    }
}
