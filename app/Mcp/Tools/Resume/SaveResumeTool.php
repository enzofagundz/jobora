<?php

namespace App\Mcp\Tools\Resume;

use App\Mcp\Support\McpUserResolver;
use App\Mcp\Support\ToolException;
use App\Mcp\Tools\JoboraTool;
use App\Services\ResumeService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Define o currículo atual do usuário configurado (texto puro). Substitui a versão anterior — não há histórico. Operação de sobrescrita: exige confirm=true.')]
class SaveResumeTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly ResumeService $resumes,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:50000'],
            'confirm' => ['nullable', 'boolean'],
        ]);

        $this->requireConfirm($request);

        if (trim($validated['content']) === '') {
            throw ToolException::validation('O currículo não pode ficar vazio.');
        }

        $resume = $this->resumes->save($this->user(), $validated['content']);

        return $this->ok([
            'content' => $resume->content,
            'updated_at' => $resume->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()->required()->description('Texto completo do currículo.'),
            'confirm' => $schema->boolean()->required()->description('Envie true para confirmar a substituição do currículo.'),
        ];
    }
}
