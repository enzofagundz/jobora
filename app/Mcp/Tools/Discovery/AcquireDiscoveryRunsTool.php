<?php

namespace App\Mcp\Tools\Discovery;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Adquire atomicamente até N fontes pendentes, criando execuções reservadas com idempotency_key explícita.')]
class AcquireDiscoveryRunsTool extends DiscoveryRunTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        return $this->respond(function () use ($validated): array {
            $runs = $this->runs->acquire(
                $this->user(),
                $this->integration(),
                (string) $validated['idempotency_key'],
                (int) ($validated['limit'] ?? 1),
            );

            return [
                'runs' => $runs->map(fn ($run): array => $this->presentRun($run))->values()->all(),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'idempotency_key' => $schema->string()->required()->description('Chave idempotente explícita da aquisição.'),
            'limit' => $schema->integer()->description('Máximo de reservas (1–10).'),
        ];
    }
}
