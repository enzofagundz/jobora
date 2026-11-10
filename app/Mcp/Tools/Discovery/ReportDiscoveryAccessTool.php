<?php

namespace App\Mcp\Tools\Discovery;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Registra acesso (sucesso ou falha) de uma execução. Replay por event_key é idempotente; sucesso não declara captura.')]
class ReportDiscoveryAccessTool extends DiscoveryRunTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'execution_id' => ['required', 'string', 'max:200'],
            'event_key' => ['required', 'string', 'max:200'],
            'outcome' => ['required', 'in:success,failure'],
            'final_url' => ['nullable', 'string', 'max:2000'],
            'http_status' => ['nullable', 'integer', 'min:100', 'max:599'],
            'redirects' => ['nullable', 'array', 'max:20'],
            'redirects.*' => ['string', 'max:2000'],
            'details' => ['nullable', 'array'],
            'error' => ['nullable', 'string', 'max:500'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        return $this->respond(function () use ($validated): array {
            $event = $this->runs->reportAccess(
                $this->user(),
                (string) $validated['execution_id'],
                $validated,
            );

            return [
                'event' => [
                    'id' => $event->id,
                    'event_key' => $event->event_key,
                    'outcome' => $event->outcome->value,
                    'final_url' => $event->final_url,
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                ],
                'capture_created' => false,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'execution_id' => $schema->string()->required()->description('Identificador opaco da execução.'),
            'event_key' => $schema->string()->required()->description('Chave idempotente do evento.'),
            'outcome' => $schema->string()->required()->description('success ou failure.'),
            'final_url' => $schema->string()->description('URL final após redirects.'),
            'http_status' => $schema->integer()->description('Status HTTP observado.'),
            'redirects' => $schema->array()->description('URLs de redirect observadas.')->items($schema->string()),
            'details' => $schema->array()->description('Detalhes adicionais do acesso.'),
            'error' => $schema->string()->description('Erro em caso de falha.'),
            'occurred_at' => $schema->string()->description('Instante do acesso (ISO 8601).'),
        ];
    }
}
