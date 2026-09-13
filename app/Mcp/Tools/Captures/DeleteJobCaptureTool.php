<?php

namespace App\Mcp\Tools\Captures;

use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\JobCapture;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Exclui (soft delete) uma captura de vaga do usuário configurado. Operação destrutiva: exige confirm=true.')]
class DeleteJobCaptureTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['required', 'integer'],
            'confirm' => ['nullable', 'boolean'],
        ]);

        $this->requireConfirm($request);

        $capture = JobCapture::query()
            ->where('user_id', $this->user()->id)
            ->whereKey((int) $validated['id'])
            ->first();

        if ($capture === null) {
            throw ToolException::notFound('Captura não encontrada.');
        }

        $capture->delete();

        return $this->ok(['deleted' => true, 'id' => $capture->id]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Id da captura.'),
            'confirm' => $schema->boolean()->required()->description('Envie true para confirmar a exclusão.'),
        ];
    }
}
