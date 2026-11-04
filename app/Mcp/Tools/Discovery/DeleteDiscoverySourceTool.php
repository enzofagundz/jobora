<?php

namespace App\Mcp\Tools\Discovery;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Remove logicamente uma fonte de descoberta do usuário, preservando histórico. Exige confirm e expected_version.')]
class DeleteDiscoverySourceTool extends DiscoverySourceTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['required', 'integer'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'confirm' => ['nullable', 'boolean'],
        ]);

        $this->requireConfirm($request);

        return $this->respond(fn () => $this->sources->delete(
            $this->user(),
            (int) $validated['id'],
            (int) $validated['expected_version'],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Id da fonte.'),
            'expected_version' => $schema->integer()->required()->description('Versão atual da fonte.'),
            'confirm' => $schema->boolean()->required()->description('Envie true para confirmar a remoção.'),
        ];
    }
}
