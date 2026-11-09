<?php

namespace App\Mcp\Tools\Captures;

use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\PageCapture;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Obtém metadados de uma captura de página por id ou execution_id.')]
class GetPageCaptureTool extends JoboraTool
{
    public function __construct(McpUserResolver $users)
    {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['nullable', 'integer'],
            'execution_id' => ['nullable', 'string', 'max:64', 'required_without:id'],
        ]);

        $query = PageCapture::query()->where('user_id', $this->user()->id);

        $capture = isset($validated['id'])
            ? $query->whereKey((int) $validated['id'])->first()
            : $query->where('execution_id', (string) $validated['execution_id'])->first();

        if ($capture === null) {
            throw ToolException::notFound('Captura não encontrada.');
        }

        return $this->ok([
            'capture' => [
                'id' => $capture->id,
                'source' => $capture->source,
                'surface' => $capture->surface,
                'execution_id' => $capture->execution_id,
                'coverage' => $capture->coverage_status,
                'coverage_reason' => $capture->coverage_reason,
                'ingest_status' => $capture->ingest_status,
                'raw_expires_at' => $capture->raw_expires_at?->toIso8601String(),
                'raw_deleted_at' => $capture->raw_deleted_at?->toIso8601String(),
                'captured_at' => $capture->captured_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Id da captura.'),
            'execution_id' => $schema->string()->description('Identificador opaco da execução.'),
        ];
    }
}
