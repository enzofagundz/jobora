<?php

namespace App\Mcp\Tools\Resume;

use App\Enums\ResumeLanguage;
use App\Mcp\Support\McpUserResolver;
use App\Mcp\Tools\JoboraTool;
use App\Services\ResumeService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Devolve o currículo atual do usuário configurado (texto e data de atualização). Vazio quando ainda não cadastrado.')]
class GetResumeTool extends JoboraTool
{
    public function __construct(
        McpUserResolver $users,
        private readonly ResumeService $resumes,
    ) {
        parent::__construct($users);
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $resume = $this->resumes->current($this->user(), ResumeLanguage::PtBr);

        return $this->ok([
            'content' => $resume?->content,
            'updated_at' => $resume?->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
