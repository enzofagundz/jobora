<?php

namespace App\Mcp\Tools\Discovery;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Lista as execuções de descoberta do usuário, com filtro por estado.')]
class ListDiscoveryRunsTool extends DiscoveryRunTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:active,expired,cancelled,completed'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $page = $this->runs->paginateRuns(
            $this->user(),
            ['status' => $validated['status'] ?? null],
            (int) ($validated['limit'] ?? 20),
            (int) ($validated['offset'] ?? 0),
        );

        return $this->ok([
            'items' => array_map(fn ($run): array => $this->presentRun($run), $page->items()),
            'total' => $page->total(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('active, expired, cancelled ou completed.'),
            'limit' => $schema->integer()->description('Itens por página (1–100).'),
            'offset' => $schema->integer()->description('Itens a pular.'),
        ];
    }
}
