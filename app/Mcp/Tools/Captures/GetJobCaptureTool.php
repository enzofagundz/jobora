<?php

namespace App\Mcp\Tools\Captures;

use App\Mcp\Support\JobCapturePresenter;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Models\JobCapture;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Devolve os dados completos de uma captura de vaga do usuário configurado, incluindo o estado da triagem.')]
class GetJobCaptureTool extends JoboraTool
{
    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['id' => ['required', 'integer']]);

        $capture = JobCapture::query()
            ->where('user_id', $this->user()->id)
            ->whereKey((int) $validated['id'])
            ->first();

        if ($capture === null) {
            throw ToolException::notFound('Captura não encontrada.');
        }

        return $this->ok(['capture' => JobCapturePresenter::toArray($capture)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Id da captura.'),
        ];
    }
}
