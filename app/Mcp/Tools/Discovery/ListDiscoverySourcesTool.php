<?php

namespace App\Mcp\Tools\Discovery;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Lista as fontes de descoberta do usuário, com paginação e filtro por situação e plataforma.')]
class ListDiscoverySourcesTool extends DiscoverySourceTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'active' => ['nullable', 'boolean'],
            'source' => ['nullable', 'in:linkedin,indeed'],
        ]);

        $page = $this->sources->paginate(
            $this->user(),
            [
                'active' => $validated['active'] ?? null,
                'source' => $validated['source'] ?? null,
            ],
            (int) ($validated['limit'] ?? 20),
            (int) ($validated['offset'] ?? 0),
        );

        return $this->ok([
            'items' => array_map(fn ($source): array => $this->present($source), $page->items()),
            'total' => $page->total(),
            'limit' => $page->perPage(),
            'offset' => (int) ($validated['offset'] ?? 0),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('Itens por página (1–100).'),
            'offset' => $schema->integer()->description('Itens a pular.'),
            'active' => $schema->boolean()->description('Filtra fontes ativas ou inativas.'),
            'source' => $schema->string()->description('Plataforma: linkedin ou indeed.'),
        ];
    }
}
